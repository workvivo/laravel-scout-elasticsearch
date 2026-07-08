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

        // Renew before the planning key scan below (which can take a while on a
        // large table without --fast-plan), so the lease cannot lapse while we
        // plan. A second renew after the scan lives further down.
        if ($this->lockOwner !== null) {
            ImportLock::renew($this->source->searchableAs(), $this->lockOwner, $this->lockTtl);
        }

        // Opt-in retry for the fanned-out chunk jobs. Default tries=1 reproduces
        // today's behaviour (a chunk that throws fails immediately). Only the
        // chunk jobs carry this — the prepare stages must never retry, since
        // CreateWriteIndex is non-idempotent. The chunk re-index is idempotent
        // (stable doc ids overwrite), so once enabled a transient failure (e.g.
        // a job_batches lock-wait timeout raised in worker bookkeeping) is
        // retried with backoff instead of cancelling the whole batch.
        $timeout = $this->timeout;
        $tries = max(1, (int) config('elasticsearch.import.batch.tries', 1));
        $backoffBase = (int) config('elasticsearch.import.batch.backoff_base', 5);
        $backoffCap = (int) config('elasticsearch.import.batch.backoff_cap', 120);
        $retryUntil = ((int) config('elasticsearch.import.batch.retry_until', 0)) ?: null;

        $chunkJobs = PullFromSource::chunked($this->source, $this->profile)
            ->map(function ($stage) use ($timeout, $tries, $backoffBase, $backoffCap, $retryUntil) {
                $job = new StageJob($stage);
                $job->timeout = $timeout;
                $job->tries = $tries;
                $job->backoffBase = $backoffBase;
                $job->backoffCap = $backoffCap;
                $job->retryUntilSeconds = $retryUntil;

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

        // One line per import so the chunk count (which drives job_batches
        // bookkeeping contention) can be correlated with any lock-wait incidents.
        logger()->info('scout:import dispatching parallel batch', [
            'searchable' => $searchableAs,
            'chunks' => count($chunkJobs),
            'connection' => $this->batchConnection,
            'queue' => $this->batchQueue,
        ]);

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
            // A failed chunk cancels the batch: we never swap, so the old index
            // keeps serving. Just report the triggering failure here — the
            // rollback (deleting the half-filled index) is deferred to finally()
            // so it cannot race chunks that are still draining.
            ->catch(function (Batch $batch, Throwable $e) use ($searchableAs) {
                report($e);
                logger()->error('scout:import batch cancelled', [
                    'searchable' => $searchableAs,
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                ]);
            })
            // Runs once the batch is fully settled — every chunk, including the
            // ones SkipIfBatchCancelled no-op'd, has been processed. Deleting the
            // unpromoted index here therefore cannot race an in-flight write, and
            // only happens when the batch actually failed. The lock is released
            // last, after the swap (success) or rollback (failure) has completed.
            ->finally(function (Batch $batch) use ($source, $index, $searchableAs, $owner) {
                if ($batch->cancelled()) {
                    self::removeUnpromotedIndex($source, $index, $owner);
                }
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
        try {
            (new RefreshIndex($index))->handle($elasticsearch);
            (new SwitchToNewAndRemoveOldIndex($source, $index))->handle($elasticsearch);
        } catch (Missing404Exception $e) {
            // The concrete index vanished before we could promote it — a
            // superseding run of the same model deleted it out from under us.
            // There is nothing left to promote, so log and abort instead of
            // throwing a raw 404 out of the batch then() callback. Narrow catch:
            // any other error (mapping conflict, cluster error) still propagates.
            logger()->warning('scout:import finalize aborted: target index vanished', [
                'index' => $index->name(),
            ]);
        }
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
    public static function removeUnpromotedIndex(ImportSource $source, Index $index, ?string $owner = null): void
    {
        $name = $index->name();
        $expectedPrefix = $source->searchableAs().'_';

        $isMultiTarget = $name === '' || $name === '_all' || strpbrk($name, '*,?') !== false;
        $belongsToModel = strpos($name, $expectedPrefix) === 0 && strlen($name) > strlen($expectedPrefix);

        if ($isMultiTarget || ! $belongsToModel) {
            return;
        }

        // If our lease has lapsed and another run now owns this model, that run
        // is responsible for its own index — do not delete on its behalf. Our
        // own frozen name is unique per run (Index::fromSource), so this is
        // belt-and-suspenders on top of the name checks above.
        if ($owner !== null && ! ImportLock::isHeldBy($source->searchableAs(), $owner)) {
            logger()->warning('scout:import rollback skipped: no longer lock owner', [
                'index' => $name,
            ]);

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
