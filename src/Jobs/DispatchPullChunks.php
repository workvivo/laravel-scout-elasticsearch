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
use Matchish\ScoutElasticSearch\Searchable\DefaultImportSource;
use Matchish\ScoutElasticSearch\Searchable\ImportSource;

/**
 * Fans the per-chunk pulls out as independent jobs coordinated by a run record.
 *
 * Runs after the write index exists (it is the tail of the CleanUp →
 * CreateWriteIndex chain), so by the time it dispatches chunks the target
 * index is ready to receive documents.
 *
 * Very large plans are fanned out over several hops instead of one: a plan of
 * millions of chunks would otherwise hold an ImportSource per chunk in memory
 * and spend tens of minutes inside a single queue job — long enough for the
 * broker's visibility window to re-deliver it mid-fan-out. Each hop dispatches
 * at most `import.dispatch_batch` chunks from boundaries parked in the run
 * record, then re-enqueues itself for the next page. Plans at or below one page
 * (and any source that cannot rebuild a chunk from bounds) take the original
 * single-hop path unchanged.
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
     * breakdown (see {@see PullFromSource}) — or, with $profileSamples, only the
     * sampled ones do. Carried onto the chunk jobs so the flag survives
     * serialization to the workers.
     *
     * @var bool
     */
    private $profile;

    /**
     * Target number of chunks to profile, spread across the whole plan, instead
     * of profiling every one. Null (the default) leaves $profile meaning "every
     * chunk" — the original behaviour.
     *
     * @var int|null
     */
    private $profileSamples;

    /**
     * Number of chunks already dispatched by earlier hops of a paged fan-out.
     * Zero marks the planning hop — the only hop that plans, publishes the run
     * record, and may take the single-hop path. Any positive value marks a
     * continuation hop, which re-plans nothing and only drains the next page of
     * parked boundaries. The store tracks what is left itself, so the cursor is
     * there to make the log line and the queue payload readable.
     *
     * @var int
     */
    private $cursor;

    /**
     * Chunk total decided by the planning hop, carried so a continuation hop can
     * report progress without re-planning. Null on the planning hop.
     *
     * @var int|null
     */
    private $total;

    public ?int $timeout = null;

    /**
     * A hop is never retried. Without this the worker's --tries governs the job,
     * and a --tries > 1 turns any hop failure (or a visibility-timeout
     * re-delivery of a slow one) into a full re-plan that dispatches the whole
     * fan-out a second time.
     */
    public int $tries = 1;

    public function __construct(
        ImportSource $source,
        Index $index,
        ?string $connection,
        ?string $queue,
        ?string $lockOwner = null,
        int $lockTtl = 3600,
        ?string $progressToken = null,
        bool $profile = false,
        int $cursor = 0,
        ?int $total = null,
        ?int $profileSamples = null
    ) {
        $this->source = $source;
        $this->index = $index;
        $this->connectionName = $connection;
        $this->queueName = $queue;
        $this->lockOwner = $lockOwner;
        $this->lockTtl = $lockTtl;
        $this->runToken = $progressToken ?? (string) Str::uuid();
        $this->profile = $profile;
        $this->cursor = $cursor;
        $this->total = $total;
        $this->profileSamples = $profileSamples;
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
        $store = app(ImportRunStore::class);
        $retry = $this->retrySettings();

        // Continuation hop of a paged fan-out. The plan already lives in the run
        // record, so re-planning here would be both slow and wrong: a second
        // start() would reset the chunk total that the finalize accounting keys
        // off. Only a DefaultImportSource plan is ever paged (it is the only
        // source that can rebuild a chunk from bounds), so a continuation hop for
        // any other source has nothing parked to drain.
        if ($this->cursor > 0) {
            $source = $this->source;

            if ($source instanceof DefaultImportSource) {
                $this->drainBounds($source, $store, $retry, $this->total);
            }

            return;
        }

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

        $source = $this->source;
        $index = $this->index;
        $searchableAs = $source->searchableAs();
        $owner = $this->lockOwner;
        $ttl = $this->lockTtl;
        $batch = $this->dispatchBatch();

        // Plan. For the default source we plan *boundaries* — plain scalars —
        // rather than one ImportSource per chunk, so a plan big enough to need
        // paging never has to be held in memory as objects. chunked() is itself
        // defined as chunkBounds() mapped through withChunkBounds(), so the
        // chunk sources built from these bounds are exactly the ones it returns.
        // Any other source keeps planning through chunked(), the only shape of
        // plan it can produce — and therefore always takes the single-hop path.
        //
        // Exactly one of $chunks (single hop: the whole plan, in memory) and
        // $pagedSource (paged: the plan parked in the run record) ends up set.
        /** @var \Illuminate\Support\Collection<int, PullFromSource>|null $chunks */
        $chunks = null;
        /** @var DefaultImportSource|null $pagedSource */
        $pagedSource = null;
        /** @var array<int, array{0:int, 1:mixed, 2:mixed}> $triples */
        $triples = [];

        if ($source instanceof DefaultImportSource) {
            $bounds = $source->chunkBounds()->values();
            $count = $bounds->count();

            // One stride for the whole plan, computed once here from the chunk
            // count, so every stage built by this run — this hop's, a later
            // hop's, the reaper's — agrees on which chunks are sampled.
            $stride = PullFromSource::profileStride($count, $this->profile, $this->profileSamples);

            if ($count > $batch) {
                $pagedSource = $source;
                // The chunk id is the chunk's position in the plan — what the
                // run record's done/total accounting counts — so park the ids
                // with the bounds rather than re-deriving them per hop.
                $triples = $bounds->map(function (array $pair, int $chunkId): array {
                    return [$chunkId, $pair[0], $pair[1]];
                })->values()->all();
            } else {
                $chunks = $bounds->map(function (array $pair, int $chunkId) use ($source, $stride): PullFromSource {
                    return new PullFromSource(
                        $source->withChunkBounds($pair[0], $pair[1]),
                        PullFromSource::shouldProfile($chunkId, $stride)
                    );
                });
            }
        } else {
            $chunks = PullFromSource::chunked($source, $this->profile, $this->profileSamples)->values();
            $count = $chunks->count();
            // chunked() already applied this stride per chunk; recompute it only
            // so the log line below can report the sampling in effect.
            $stride = PullFromSource::profileStride($count, $this->profile, $this->profileSamples);
        }

        // Building chunk bounds scans the key column and can take a while on a
        // large table; renew the lease now so it does not expire before the
        // first chunk reports progress.
        if ($owner !== null) {
            ImportLock::renew($searchableAs, $owner, $ttl);
        }

        $store->start($this->runToken, $count, $index->name());
        $store->refreshTtls($this->runToken, $ttl);

        logger()->info('scout:import dispatching parallel chunks', [
            'searchable' => $searchableAs,
            'chunks' => $count,
            'connection' => $this->connectionName,
            'queue' => $this->queueName,
            // Hop 0 by definition: only the planning hop reaches this line.
            'hop' => 0,
            'cursor' => $this->cursor,
            'paged' => $pagedSource !== null,
            // false / 'all' / '1 in N': whoever reads this line must be able to
            // tell a sampled profile from a complete one.
            'profile' => $this->profileLabel($stride),
            'profile_samples' => $this->profileSamples,
        ]);

        if ($count === 0) {
            $this->dispatchFinalizeJob($source, $index, $owner, $ttl, $timeout);

            return;
        }

        // Start the watchdog chain, if it is switched on. It is deliberately
        // unconditional here: scheduleFor() owns the enabled check and is a no-op
        // at the default `reaper_interval = 0`, so nothing is queued unless an
        // operator asked for it. It has to be started from the planning hop —
        // the only hop that knows the run record now exists.
        ImportReaperJob::scheduleFor($source, $index, $this->runToken, $owner, $ttl, $this->connectionName, $this->queueName, $this->profile, $this->profileSamples);

        if ($pagedSource !== null) {
            $store->pushBounds($this->runToken, $triples);

            $this->drainBounds($pagedSource, $store, $retry, $count);

            return;
        }

        // Single-hop fan-out, unchanged: a plan that fits in one page is
        // dispatched right here, with nothing parked and no second hop.
        if ($chunks !== null) {
            foreach ($chunks as $chunkId => $stage) {
                $this->dispatchChunkJob($stage, $chunkId, $owner, $ttl, $timeout, $retry);
            }
        }
    }

    /**
     * Dispatch one page of a paged fan-out: pop up to `dispatch_batch` parked
     * boundary triples, rebuild the chunk source from each, and queue its
     * PullChunkJob. Re-enqueues the next hop while boundaries remain.
     *
     * Both leases are renewed *inside* the dispatch loop, not just around it. A
     * page is up to dispatch_batch queue writes and a fan-out can span many
     * pages, so on a slow broker the work can outlive lock_ttl — and a lapsed
     * import lock admits a second run of the same model while expired run keys
     * lose the chunk accounting outright, stranding an import in which no chunk
     * has yet reported progress. The renew is time-based so its cost stays
     * negligible whatever the page size.
     *
     * @param  array{tries:int, backoff_base:int, backoff_cap:int, retry_until:int|null}  $retry
     */
    private function drainBounds(DefaultImportSource $source, ImportRunStore $store, array $retry, ?int $total): void
    {
        $searchableAs = $source->searchableAs();
        $owner = $this->lockOwner;
        $ttl = $this->lockTtl;
        $timeout = $this->timeout;

        $renewEvery = max(1, intdiv($ttl, 4));
        $lastRenew = microtime(true);

        // Recomputed from the carried chunk total, not from a fresh plan: a
        // continuation hop does not re-plan, so $total is the only chunk count it
        // has — and it is the same one the planning hop used, which is precisely
        // what makes every hop sample the same chunk ids. Deriving the stride any
        // other way would silently shift the sampled set between pages, so the
        // sampled chunks would no longer be spread evenly across the plan.
        $stride = PullFromSource::profileStride((int) $total, $this->profile, $this->profileSamples);

        $dispatched = 0;

        foreach ($store->popBounds($this->runToken, $this->dispatchBatch()) as $triple) {
            $chunkId = (int) $triple[0];
            $stage = new PullFromSource(
                $source->withChunkBounds($triple[1], $triple[2]),
                PullFromSource::shouldProfile($chunkId, $stride)
            );
            $this->dispatchChunkJob($stage, $chunkId, $owner, $ttl, $timeout, $retry);
            $dispatched++;

            if (microtime(true) - $lastRenew >= $renewEvery) {
                if ($owner !== null) {
                    ImportLock::renew($searchableAs, $owner, $ttl);
                }
                $store->refreshTtls($this->runToken, $ttl);
                $lastRenew = microtime(true);
            }
        }

        if ($owner !== null) {
            ImportLock::renew($searchableAs, $owner, $ttl);
        }
        $store->refreshTtls($this->runToken, $ttl);

        $pending = $store->pendingBounds($this->runToken);

        logger()->info('scout:import dispatched chunk page', [
            'searchable' => $searchableAs,
            'cursor' => $this->cursor,
            'dispatched' => $dispatched,
            'pending' => $pending,
            'chunks' => $total,
            'connection' => $this->connectionName,
            'queue' => $this->queueName,
            // Repeated on every page for the same reason as on the planning hop:
            // a log reader landing on one page must not read partial profile
            // coverage as full coverage.
            'profile' => $this->profileLabel($stride),
            'profile_samples' => $this->profileSamples,
        ]);

        if ($pending === 0) {
            return;
        }

        // Hand the rest of the plan to a fresh hop rather than looping here, so
        // no single job has to survive the whole fan-out. The cursor is floored
        // at 1: it is what marks a hop as a continuation, and a hop that read
        // cursor 0 would re-plan the import and reset the run record.
        $next = new self(
            $this->source,
            $this->index,
            $this->connectionName,
            $this->queueName,
            $owner,
            $ttl,
            $this->runToken,
            $this->profile,
            max(1, $this->cursor + $dispatched),
            $total,
            $this->profileSamples
        );
        $next->timeout = $timeout;

        if ($this->connectionName !== null) {
            $next->onConnection($this->connectionName);
        }
        if ($this->queueName !== null) {
            $next->onQueue($this->queueName);
        }
        Bus::dispatch($next);
    }

    /**
     * Queue one chunk job. The job carries the *whole* source (it needs
     * searchableAs and the finalize hand-off); only the stage is scoped to the
     * chunk.
     *
     * @param  array{tries:int, backoff_base:int, backoff_cap:int, retry_until:int|null}  $retry
     */
    private function dispatchChunkJob(PullFromSource $stage, int $chunkId, ?string $owner, int $ttl, ?int $timeout, array $retry): void
    {
        $job = new PullChunkJob($this->source, $stage, $this->index, $this->runToken, $chunkId, $owner, $ttl, $this->connectionName, $this->queueName);
        $job->timeout = $timeout ?? Config::queueTimeout();
        $job->tries = $retry['tries'];
        $job->backoffBase = $retry['backoff_base'];
        $job->backoffCap = $retry['backoff_cap'];
        $job->retryUntilSeconds = $retry['retry_until'];

        if ($this->connectionName !== null) {
            $job->onConnection($this->connectionName);
        }
        if ($this->queueName !== null) {
            $job->onQueue($this->queueName);
        }
        Bus::dispatch($job);
    }

    /**
     * The sampling in effect, as it appears in the fan-out log lines: false when
     * profiling is off, 'all' when every chunk is profiled, '1 in N' when only
     * every Nth chunk is. Never mistake a sampled run for full coverage.
     *
     * @return bool|string
     */
    private function profileLabel(int $stride)
    {
        if ($stride === 0) {
            return false;
        }

        return $stride === 1 ? 'all' : '1 in '.$stride;
    }

    /**
     * @return array{tries:int, backoff_base:int, backoff_cap:int, retry_until:int|null}
     */
    private function retrySettings(): array
    {
        return [
            'tries' => max(1, (int) config('elasticsearch.import.retry.tries', 1)),
            'backoff_base' => (int) config('elasticsearch.import.retry.backoff_base', 5),
            'backoff_cap' => (int) config('elasticsearch.import.retry.backoff_cap', 120),
            'retry_until' => ((int) config('elasticsearch.import.retry.retry_until', 0)) ?: null,
        ];
    }

    /**
     * How many chunks one hop dispatches. Also the threshold above which a plan
     * is paged at all, so the default keeps every plan of 1000 chunks or fewer
     * on the original single-hop path.
     */
    private function dispatchBatch(): int
    {
        $batch = config('elasticsearch.import.dispatch_batch', 1000);

        return max(1, is_numeric($batch) ? (int) $batch : 1000);
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
