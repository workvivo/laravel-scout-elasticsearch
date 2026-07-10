<?php

namespace Matchish\ScoutElasticSearch\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Matchish\ScoutElasticSearch\ElasticSearch\Config\Config;
use Matchish\ScoutElasticSearch\ElasticSearch\Index;
use Matchish\ScoutElasticSearch\Import\ImportRunStore;
use Matchish\ScoutElasticSearch\ImportLock;
use Matchish\ScoutElasticSearch\Jobs\Stages\PullFromSource;
use Matchish\ScoutElasticSearch\Searchable\ImportSource;

/**
 * Fans the per-chunk pulls out as independent jobs coordinated by a run record.
 *
 * Runs after the write index exists (it is the tail of the CleanUp →
 * CreateWriteIndex chain), so by the time it dispatches chunks the target
 * index is ready to receive documents.
 *
 * @internal
 */
final class DispatchPullChunks implements ShouldQueue
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
    private $connectionName;
    /**
     * @var string|null
     */
    private $queueName;
    /**
     * @var string|null
     */
    private $lockOwner;
    /**
     * @var int
     */
    private $lockTtl;
    /**
     * Correlation token for the Redis run record.
     *
     * @var string
     */
    private $runToken;

    /**
     * When true, each fanned-out chunk logs a fetch/filter/index timing
     * breakdown (see {@see PullFromSource}). Carried onto the chunk jobs so the
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
        $this->connectionName = $connection;
        $this->queueName = $queue;
        $this->lockOwner = $lockOwner;
        $this->lockTtl = $lockTtl;
        $this->runToken = $progressToken ?? (string) Str::uuid();
        $this->profile = $profile;
    }

    /**
     * Cache key for the prepare-phase heartbeat the chain publishes while it
     * cleans up, creates the write index, and plans chunks. Lets a --wait command tell a
     * chain that is being actively worked from one still queued.
     */
    public static function preparingKey(string $token): string
    {
        return 'scout:import:preparing:'.$token;
    }

    public function handle(): void
    {
        // Third prepare heartbeat: planning is the last step before chunks
        // are dispatched, and on a large table without --fast-plan the key scan is the
        // slow part, so publish "planning" before it starts. seq 1 (clean up)
        // and 2 (create index) are published by the preceding StageJobs.
        if ($this->runToken !== '') {
            Cache::put(self::preparingKey($this->runToken), [
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

        $timeout = $this->timeout;
        $tries = max(1, (int) config('elasticsearch.import.retry.tries', 1));
        $backoffBase = (int) config('elasticsearch.import.retry.backoff_base', 5);
        $backoffCap = (int) config('elasticsearch.import.retry.backoff_cap', 120);
        $retryUntil = ((int) config('elasticsearch.import.retry.retry_until', 0)) ?: null;

        $chunks = PullFromSource::chunked($this->source, $this->profile)->values();

        $source = $this->source;
        $index = $this->index;
        $searchableAs = $source->searchableAs();
        $owner = $this->lockOwner;
        $ttl = $this->lockTtl;
        $store = app(ImportRunStore::class);

        // Building chunk bounds scans the key column and can take a while on a
        // large table; renew the lease now so it does not expire before the
        // first chunk reports progress.
        if ($owner !== null) {
            ImportLock::renew($searchableAs, $owner, $ttl);
        }

        $store->start($this->runToken, $chunks->count(), $index->name());
        $store->refreshTtls($this->runToken, $ttl);

        logger()->info('scout:import dispatching parallel chunks', [
            'searchable' => $searchableAs,
            'chunks' => $chunks->count(),
            'connection' => $this->connectionName,
            'queue' => $this->queueName,
        ]);

        if ($chunks->isEmpty()) {
            $this->dispatchFinalizeJob($source, $index, $owner, $ttl, $timeout);

            return;
        }

        foreach ($chunks as $chunkId => $stage) {
            $job = new PullChunkJob($source, $stage, $index, $this->runToken, $chunkId, $owner, $ttl, $this->connectionName, $this->queueName);
            $job->timeout = $timeout ?? Config::queueTimeout();
            $job->tries = $tries;
            $job->backoffBase = $backoffBase;
            $job->backoffCap = $backoffCap;
            $job->retryUntilSeconds = $retryUntil;

            if ($this->connectionName !== null) {
                $job->onConnection($this->connectionName);
            }
            if ($this->queueName !== null) {
                $job->onQueue($this->queueName);
            }
            Bus::dispatch($job);
        }
    }

    private function dispatchFinalizeJob(ImportSource $source, Index $index, ?string $owner, int $ttl, ?int $timeout): void
    {
        $job = new FinalizeJob($source, $index, $this->runToken, $owner, $ttl);
        $job->timeout = $timeout ?? Config::queueTimeout();

        if ($this->connectionName !== null) {
            $job->onConnection($this->connectionName);
        }
        if ($this->queueName !== null) {
            $job->onQueue($this->queueName);
        }
        Bus::dispatch($job);
    }
}
