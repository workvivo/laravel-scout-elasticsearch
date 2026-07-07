<?php

namespace Matchish\ScoutElasticSearch\Jobs;

use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Matchish\ScoutElasticSearch\ElasticSearch\Index;
use Matchish\ScoutElasticSearch\ImportLock;
use Matchish\ScoutElasticSearch\Jobs\Stages\PullFromSource;
use Matchish\ScoutElasticSearch\Jobs\Stages\RefreshIndex;
use Matchish\ScoutElasticSearch\Jobs\Stages\SwitchToNewAndRemoveOldIndex;
use Matchish\ScoutElasticSearch\Searchable\ImportSource;
use OpenSearch\Client;
use OpenSearch\Common\Exceptions\Missing404Exception;
use Throwable;

/**
 * Fans the per-chunk pulls out into a {@see \Illuminate\Bus\Batch} so they run
 * in parallel across workers, then finalizes the index once they all succeed.
 *
 * Runs after the write index exists (it is the tail of the CleanUp →
 * CreateWriteIndex chain), so by the time it dispatches the batch the target
 * index is ready to receive documents.
 *
 * @internal
 */
final class DispatchPullBatch implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @var ImportSource
     */
    private $source;
    /**
     * @var Index
     */
    private $index;
    /**
     * @var string|null
     */
    private $batchConnection;
    /**
     * @var string|null
     */
    private $batchQueue;
    /**
     * @var string|null
     */
    private $lockOwner;
    /**
     * @var int
     */
    private $lockTtl;
    /**
     * Correlation token for `--wait`: when set, the created batch id and target
     * index are published to the cache so the dispatching command can find the
     * batch and poll its progress. Null when nobody is waiting.
     *
     * @var string|null
     */
    private $progressToken;

    /**
     * When true, each fanned-out chunk logs a fetch/filter/index timing
     * breakdown (see {@see PullFromSource}). Carried onto the batch jobs so the
     * flag survives serialization to the workers.
     *
     * @var bool
     */
    private $profile;

    public ?int $timeout = null;

    public function __construct(
        ImportSource $source,
        Index $index,
        ?string $connection,
        ?string $queue,
        ?string $lockOwner = null,
        int $lockTtl = 3600,
        ?string $progressToken = null,
        bool $profile = false
    ) {
        $this->source = $source;
        $this->index = $index;
        $this->batchConnection = $connection;
        $this->batchQueue = $queue;
        $this->lockOwner = $lockOwner;
        $this->lockTtl = $lockTtl;
        $this->progressToken = $progressToken;
        $this->profile = $profile;
    }

    public static function progressKey(string $token): string
    {
        return 'scout:import:progress:'.$token;
    }

    /**
     * Cache key for the prepare-phase heartbeat the chain publishes while it
     * cleans up, creates the write index, and plans chunks — before the batch
     * (and therefore {@see progressKey}) exists. Lets a --wait command tell a
     * chain that is being actively worked from one still queued.
     */
    public static function preparingKey(string $token): string
    {
        return 'scout:import:preparing:'.$token;
    }

    public function handle(): void
    {
        // Third prepare heartbeat: planning is the last step before the batch
        // exists, and on a large table without --fast-plan the key scan is the
        // slow part, so publish "planning" before it starts. seq 1 (clean up)
        // and 2 (create index) are published by the preceding StageJobs.
        if ($this->progressToken !== null) {
            Cache::put(self::preparingKey($this->progressToken), [
                'seq' => 3,
                'stage' => 'Planning chunks',
            ], $this->lockTtl);
        }

        $timeout = $this->timeout;
        $chunkJobs = PullFromSource::chunked($this->source, $this->profile)
            ->map(function ($stage) use ($timeout) {
                $job = new StageJob($stage);
                $job->timeout = $timeout;

                return $job;
            })
            ->all();

        $source = $this->source;
        $index = $this->index;
        $searchableAs = $source->searchableAs();
        $owner = $this->lockOwner;
        $ttl = $this->lockTtl;
        $progressKey = $this->progressToken !== null ? self::progressKey($this->progressToken) : null;

        // Building chunk bounds scans the key column and can take a while on a
        // large table; renew the lease now so it does not expire before the
        // first chunk reports progress.
        if ($owner !== null) {
            ImportLock::renew($searchableAs, $owner, $ttl);
        }

        // Empty table: no chunks to fan out. Mirror the sequential pipeline,
        // which still promotes an empty index, then free the lock.
        if (empty($chunkJobs)) {
            self::finalize($source, $index);
            if ($progressKey !== null) {
                Cache::put($progressKey, ['empty' => true, 'index' => $index->name()], $ttl);
            }
            if ($owner !== null) {
                ImportLock::release($searchableAs, $owner);
            }

            return;
        }

        $batch = Bus::batch($chunkJobs)
            ->name('scout-import:'.$searchableAs)
            // Fires after each chunk completes: heartbeat the lease so imports
            // that run longer than one TTL window (large tables) never expire
            // mid-run, while a crashed run still self-heals after one idle TTL.
            ->progress(function (Batch $batch) use ($searchableAs, $owner, $ttl) {
                if ($owner !== null) {
                    ImportLock::renew($searchableAs, $owner, $ttl);
                }
            })
            // Runs only when every chunk succeeded — refresh and swap the alias.
            ->then(function (Batch $batch) use ($source, $index) {
                self::finalize($source, $index);
            })
            // A failed chunk means we never swap: the old index keeps serving.
            // Remove the half-filled index we created but never promoted so it
            // does not linger and consume cluster resources.
            ->catch(function (Batch $batch, Throwable $e) use ($source, $index) {
                report($e);
                self::removeUnpromotedIndex($source, $index);
            })
            // Always runs after then/catch, so the alias swap (success) or the
            // rollback (failure) has completed by the time the lock is released.
            ->finally(function (Batch $batch) use ($searchableAs, $owner) {
                if ($owner !== null) {
                    ImportLock::release($searchableAs, $owner);
                }
            });

        // A null connection/queue means "use the driver default"; PendingBatch
        // only accepts strings, so apply them only when explicitly set.
        if ($this->batchConnection !== null) {
            $batch->onConnection($this->batchConnection);
        }
        if ($this->batchQueue !== null) {
            $batch->onQueue($this->batchQueue);
        }

        $dispatched = $batch->dispatch();

        // Hand the batch id and target index to a waiting command so it can poll
        // progress. Chunk count = totalJobs. (On the sync driver the batch has
        // already finished here; the waiter detects that via findBatch.)
        if ($progressKey !== null) {
            Cache::put($progressKey, [
                'batchId' => $dispatched->id,
                'total' => $dispatched->totalJobs,
                'index' => $index->name(),
            ], $ttl);
        }
    }

    private static function finalize(ImportSource $source, Index $index): void
    {
        $elasticsearch = app(Client::class);
        (new RefreshIndex($index))->handle($elasticsearch);
        (new SwitchToNewAndRemoveOldIndex($source, $index))->handle($elasticsearch);
    }

    /**
     * Delete the new index that a failed parallel import created but never
     * switched the alias to. Idempotent: a missing index is not an error.
     *
     * Deliberately conservative — this only ever removes the single concrete
     * index this import created:
     *  - the name must be non-empty and free of wildcard/multi-target syntax
     *    (`*`, `,`, `?`, `_all`), so a bad name can never fan a delete out
     *    across other indices;
     *  - the name must start with this model's `searchableAs()` prefix (the new
     *    index is always `{searchableAs}_{timestamp}`), so we never touch an
     *    index belonging to another model;
     *  - `expand_wildcards=none` is sent as a last line of defence so the
     *    cluster refuses to expand a wildcard even if one somehow slipped past
     *    the checks above.
     * The live read alias is unaffected: it still points at the old index,
     * which this method never names.
     */
    public static function removeUnpromotedIndex(ImportSource $source, Index $index): void
    {
        $name = $index->name();
        $expectedPrefix = $source->searchableAs().'_';

        $isMultiTarget = $name === '' || $name === '_all' || strpbrk($name, '*,?') !== false;
        $belongsToModel = strpos($name, $expectedPrefix) === 0 && strlen($name) > strlen($expectedPrefix);

        if ($isMultiTarget || ! $belongsToModel) {
            return;
        }

        $elasticsearch = app(Client::class);

        try {
            $elasticsearch->indices()->delete([
                'index' => $name,
                'expand_wildcards' => 'none',
            ]);
        } catch (Missing404Exception $e) {
            // Never created (or already cleaned up) — nothing to roll back.
        }
    }
}
