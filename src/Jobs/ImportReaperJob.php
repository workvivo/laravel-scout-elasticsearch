<?php

declare(strict_types=1);

namespace Matchish\ScoutElasticSearch\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Matchish\ScoutElasticSearch\ElasticSearch\Config\Config;
use Matchish\ScoutElasticSearch\ElasticSearch\Index;
use Matchish\ScoutElasticSearch\Import\ImportRunStore;
use Matchish\ScoutElasticSearch\ImportLock;
use Matchish\ScoutElasticSearch\Jobs\Stages\PullFromSource;
use Matchish\ScoutElasticSearch\Searchable\DefaultImportSource;
use Matchish\ScoutElasticSearch\Searchable\ImportSource;

/**
 * Opt-in watchdog for a --parallel import run.
 *
 * Completion of a parallel import normally depends on one in-process hop
 * surviving: {@see FinalizeJob} is only ever dispatched by the single chunk that
 * happens to observe `done === total`. If that chunk's worker is hard-killed
 * between marking itself done and dispatching the finalize job (SIGKILL, OOM,
 * SIGALRM), or if a chunk job disappears from the queue entirely without ever
 * running its `failed()` hook, the run sits at `running` forever: the alias is
 * never swapped, and the run only unblocks the next import once the per-model
 * {@see ImportLock} lease lapses for want of anyone left to renew it.
 *
 * This job closes both holes by polling the run record on an interval:
 *  - run complete but not finalized  -> dispatch a {@see FinalizeJob}
 *  - run incomplete, chunk neither done nor holding a live execution lease
 *    -> re-dispatch that chunk (safe: chunk bounds are frozen per job and
 *       documents overwrite by stable _id, so re-running a chunk is idempotent)
 *
 * It is deliberately inert unless `elasticsearch.import.reaper_interval` is
 * greater than zero, and it is deliberately incapable of destroying anything: it
 * never fails the run, never dispatches {@see RollbackImportJob}, and never
 * deletes an index. Escalating a genuinely broken chunk to a run failure stays
 * the job of {@see PullChunkJob::failed()} and the failure budget.
 *
 * Cost note: a pass is O(total chunks) Redis round trips (one SISMEMBER per
 * chunk, plus one EXISTS per not-yet-done chunk), so on a run with millions of
 * chunks keep the interval generous. Re-dispatches per pass are capped by
 * `import.dispatch_batch` and per chunk by `import.redispatch_limit`, which is
 * the same budget {@see PullChunkJob::failed()} spends, so the two paths cannot
 * between them re-dispatch one chunk without bound.
 *
 * @internal
 */
final class ImportReaperJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private ImportSource $source;
    private Index $index;
    private string $token;
    private ?string $lockOwner;
    private int $lockTtl;
    private ?string $connectionName;
    private ?string $queueName;
    private bool $profile;
    /**
     * Target number of chunks the run profiles, spread across the plan. Carried
     * so a re-dispatched chunk can be given back the *same* per-chunk profile
     * decision it was dispatched with. Null means $profile alone decides.
     */
    private ?int $profileSamples;
    /**
     * Monotonic pass counter, for correlating the log lines of one run's reaper
     * chain. Carries no control-flow meaning.
     */
    private int $pass;

    /**
     * Never retried: a reaper pass is a poll, and the next pass is scheduled by
     * the pass itself. A retry would only duplicate the chain.
     */
    public int $tries = 1;
    public ?int $timeout = null;

    public function __construct(
        ImportSource $source,
        Index $index,
        string $token,
        ?string $lockOwner,
        int $lockTtl,
        ?string $connection,
        ?string $queue,
        bool $profile = false,
        int $pass = 0,
        ?int $profileSamples = null
    ) {
        $this->source = $source;
        $this->index = $index;
        $this->token = $token;
        $this->lockOwner = $lockOwner;
        $this->lockTtl = $lockTtl;
        $this->connectionName = $connection;
        $this->queueName = $queue;
        $this->profile = $profile;
        $this->pass = $pass;
        $this->profileSamples = $profileSamples;
        $this->timeout = Config::queueTimeout();
    }

    /**
     * Start the reaper chain for a run, if the reaper is enabled. A no-op
     * (returning false) at the default `reaper_interval = 0`.
     *
     * This is the whole dispatch-side wiring: the fan-out only has to call it
     * once, right after it has published the run record, e.g. at the end of
     * DispatchPullChunks::handle():
     *
     *     ImportReaperJob::scheduleFor($source, $index, $token, $owner, $ttl,
     *         $connection, $queue, $profile, $profileSamples);
     *
     * Keeping the enabled check in here is what lets that stay a single
     * unconditional line on the dispatch side.
     */
    public static function scheduleFor(
        ImportSource $source,
        Index $index,
        string $token,
        ?string $lockOwner,
        int $lockTtl,
        ?string $connection,
        ?string $queue,
        bool $profile = false,
        ?int $profileSamples = null
    ): bool {
        $interval = self::intervalSeconds();

        if ($interval < 1) {
            return false;
        }

        $job = new self($source, $index, $token, $lockOwner, $lockTtl, $connection, $queue, $profile, 0, $profileSamples);
        self::dispatchWithDelay($job, $interval, $connection, $queue);

        return true;
    }

    public function handle(): void
    {
        $interval = self::intervalSeconds();

        // Re-read every pass rather than freezing the interval into the payload:
        // setting reaper_interval back to 0 must be able to stop an already
        // running chain, not just prevent new ones.
        if ($interval < 1) {
            return;
        }

        $store = app(ImportRunStore::class);
        $status = $store->status($this->token);

        // No status at all means the run record has expired (or never existed):
        // there is nothing left to reason about, and re-dispatching chunks
        // against a vanished record would just recreate half a run.
        if ($status === null) {
            return;
        }

        // Anything past `running` — finalizing, succeeded, failed,
        // finalize_failed — is somebody else's business, and every one of those
        // states is terminal for the reaper. Stop the chain instead of
        // rescheduling so an abandoned run costs nothing.
        if ($status !== ImportRunStore::STATUS_RUNNING) {
            return;
        }

        // The lease having moved means another `scout:import` for this model
        // acquired it after ours lapsed. Its chunks own the index now, so
        // anything we dispatched would be racing a live run (and would be
        // skipped by SkipIfImportRun anyway).
        if ($this->lockOwner !== null
            && ! ImportLock::isHeldBy($this->source->searchableAs(), $this->lockOwner)) {
            return;
        }

        $snapshot = $store->snapshot($this->token);
        $recordedIndex = $snapshot['index'] ?? null;

        // Paranoia against a recycled token: the run record must be about the
        // same write index this payload was built for.
        if (is_string($recordedIndex) && $recordedIndex !== '' && $recordedIndex !== $this->index->name()) {
            return;
        }

        $total = (int) ($snapshot['total'] ?? 0);
        $done = (int) ($snapshot['done'] ?? 0);

        // Covers the empty-import case (total 0) too. The completeness read is
        // not atomic, but it does not have to be: FinalizeJob re-checks with
        // claimFinalization(), which declines when the run is incomplete or
        // already claimed, so a stale read at worst dispatches a job that
        // immediately returns.
        if ($done >= $total) {
            $this->dispatchFinalize();

            return;
        }

        // A non-empty bounds list means the paged fan-out has not finished
        // dispatching yet, so "missing" chunks are simply chunks whose turn has
        // not come. Leave them alone.
        if ($store->pendingBounds($this->token) > 0) {
            $this->reschedule($interval);

            return;
        }

        $batch = $this->dispatchBatch();
        $missing = [];
        $inFlight = 0;
        $truncated = false;

        for ($chunkId = 0; $chunkId < $total; $chunkId++) {
            if ($store->isDone($this->token, $chunkId)) {
                continue;
            }

            // A live execution lease is proof that an attempt is running right
            // now, or was killed less than one lease TTL ago — in which case the
            // copy that re-delivery already queued will pick the work up as soon
            // as the lease expires. Either way, not ours to touch.
            if ($store->chunkInFlight($this->token, $chunkId)) {
                $inFlight++;

                continue;
            }

            $missing[] = $chunkId;

            if (count($missing) >= $batch) {
                $truncated = true;

                break;
            }
        }

        if ($missing === []) {
            // Nothing stranded: every outstanding chunk is in flight.
            $this->reschedule($interval);

            return;
        }

        $stages = $this->rebuildStages($missing, $total);

        if ($stages === []) {
            // rebuildStages() already logged why it could not rebuild. The
            // mismatch can be transient (rows written while we planned), so keep
            // polling rather than abandoning the run.
            $this->reschedule($interval);

            return;
        }

        $limit = $this->redispatchLimit();
        $dispatched = 0;
        $overBudget = 0;

        foreach ($stages as $chunkId => $stage) {
            // Shared counter with PullChunkJob's ambiguous-failure re-dispatch,
            // so a chunk that keeps evaporating cannot be revived forever by the
            // two paths taking turns.
            if ($store->bumpRedispatch($this->token, $chunkId) > $limit) {
                $overBudget++;

                continue;
            }

            $this->dispatchChunk($chunkId, $stage);
            $dispatched++;
        }

        if ($dispatched > 0) {
            // The chunks we just queued are gated on the lease still being ours
            // (SkipIfImportRun), and the run keys must outlive this pass, so
            // renew both — exactly as the fan-out does around its own dispatch
            // loop.
            if ($this->lockOwner !== null) {
                ImportLock::renew($this->source->searchableAs(), $this->lockOwner, $this->lockTtl);
            }
            $store->refreshTtls($this->token, $this->lockTtl);
        }

        logger()->warning('scout:import reaper found stranded chunks', [
            'searchable' => $this->source->searchableAs(),
            'index' => $this->index->name(),
            'token' => $this->token,
            'pass' => $this->pass,
            'done' => $done,
            'total' => $total,
            'in_flight' => $inFlight,
            'redispatched' => $dispatched,
            'over_budget' => $overBudget,
        ]);

        // Whole range scanned, nothing in flight, and every stranded chunk has
        // spent its re-dispatch budget: another pass would repeat this one
        // verbatim. Stop the chain and leave the run at `running` with a loud
        // log — the reaper is not allowed to fail a run, and a human needs to
        // look at these chunks.
        if ($dispatched === 0 && $inFlight === 0 && ! $truncated) {
            logger()->error('scout:import reaper giving up: stranded chunks exhausted their re-dispatch budget', [
                'searchable' => $this->source->searchableAs(),
                'index' => $this->index->name(),
                'token' => $this->token,
                'chunks' => array_slice(array_keys($stages), 0, 20),
                'done' => $done,
                'total' => $total,
            ]);

            return;
        }

        $this->reschedule($interval);
    }

    /**
     * Rebuild the pull stage for each stranded chunk id.
     *
     * The planned bounds are not kept for the lifetime of a run (the fan-out
     * consumes them), so they are re-planned here. Re-planning is only
     * trustworthy while it still produces the same number of chunks as the run
     * record: a different count means the key space moved under us and chunk id
     * N no longer denotes the range chunk N was dispatched with, so we refuse
     * rather than index a shifted range.
     *
     * The per-chunk profile decision is recomputed the same way the fan-out made
     * it — one stride from the run's chunk total — so a reaped chunk neither
     * starts nor stops profiling just because the reaper is the one dispatching
     * it. $total is the run record's count, which is exactly what the planning hop
     * strode over.
     *
     * @param  array<int, int>  $chunkIds
     * @return array<int, PullFromSource> keyed by chunk id
     */
    private function rebuildStages(array $chunkIds, int $total): array
    {
        $stages = [];
        $stride = PullFromSource::profileStride($total, $this->profile, $this->profileSamples);

        // The fast path plans scalar bounds only, so a stranded chunk is rebuilt
        // without materialising an ImportSource for every chunk of the run.
        if ($this->source instanceof DefaultImportSource) {
            $bounds = $this->source->chunkBounds();

            if ($bounds->count() !== $total) {
                $this->logPlanMismatch($bounds->count(), $total);

                return [];
            }

            foreach ($chunkIds as $chunkId) {
                /** @var array{0:mixed, 1:mixed}|null $pair */
                $pair = $bounds->get($chunkId);

                if ($pair === null) {
                    continue;
                }

                $stages[$chunkId] = new PullFromSource(
                    $this->source->withChunkBounds($pair[0], $pair[1]),
                    PullFromSource::shouldProfile($chunkId, $stride)
                );
            }

            return $stages;
        }

        // Any other ImportSource implementation only exposes chunked(), so fall
        // back to building every chunk and keeping the ones we need.
        // chunked() strides over the count it materialises, and that count is
        // checked against $total right below, so the sampled set it produces is
        // the same one the fan-out dispatched.
        $chunks = PullFromSource::chunked($this->source, $this->profile, $this->profileSamples)->values();

        if ($chunks->count() !== $total) {
            $this->logPlanMismatch($chunks->count(), $total);

            return [];
        }

        foreach ($chunkIds as $chunkId) {
            $stage = $chunks->get($chunkId);

            if ($stage instanceof PullFromSource) {
                $stages[$chunkId] = $stage;
            }
        }

        return $stages;
    }

    private function logPlanMismatch(int $planned, int $total): void
    {
        logger()->warning('scout:import reaper skipped: re-planned chunk count differs from the run record', [
            'searchable' => $this->source->searchableAs(),
            'index' => $this->index->name(),
            'token' => $this->token,
            'pass' => $this->pass,
            'planned' => $planned,
            'total' => $total,
        ]);
    }

    private function dispatchChunk(int $chunkId, PullFromSource $stage): void
    {
        $job = new PullChunkJob(
            $this->source,
            $stage,
            $this->index,
            $this->token,
            $chunkId,
            $this->lockOwner,
            $this->lockTtl,
            $this->connectionName,
            $this->queueName
        );
        $job->timeout = $this->timeout ?? Config::queueTimeout();
        // Same retry configuration the fan-out gives a first-time chunk: a
        // re-dispatched chunk is an ordinary chunk, not a special case. Keep in
        // sync with DispatchPullChunks::retrySettings().
        $job->tries = max(1, $this->intConfig('retry.tries', 1));
        $job->backoffBase = $this->intConfig('retry.backoff_base', 5);
        $job->backoffCap = $this->intConfig('retry.backoff_cap', 120);
        $job->retryUntilSeconds = $this->intConfig('retry.retry_until', 0) ?: null;

        $this->onSameRoute($job);

        Bus::dispatch($job);
    }

    private function dispatchFinalize(): void
    {
        $job = new FinalizeJob($this->source, $this->index, $this->token, $this->lockOwner, $this->lockTtl);
        $job->timeout = $this->timeout ?? Config::queueTimeout();

        $this->onSameRoute($job);

        Bus::dispatch($job);

        logger()->info('scout:import reaper finalizing a complete run', [
            'searchable' => $this->source->searchableAs(),
            'index' => $this->index->name(),
            'token' => $this->token,
            'pass' => $this->pass,
        ]);
    }

    /**
     * Queue the next pass. The chain, not a schedule entry, is what keeps the
     * reaper alive, so every terminal path in handle() simply declines to call
     * this.
     */
    private function reschedule(int $interval): void
    {
        $next = new self(
            $this->source,
            $this->index,
            $this->token,
            $this->lockOwner,
            $this->lockTtl,
            $this->connectionName,
            $this->queueName,
            $this->profile,
            $this->pass + 1,
            $this->profileSamples
        );

        self::dispatchWithDelay($next, $interval, $this->connectionName, $this->queueName);
    }

    private static function dispatchWithDelay(self $job, int $interval, ?string $connection, ?string $queue): void
    {
        $job->delay(now()->addSeconds($interval));

        if ($connection !== null) {
            $job->onConnection($connection);
        }
        if ($queue !== null) {
            $job->onQueue($queue);
        }

        Bus::dispatch($job);
    }

    /**
     * @param  FinalizeJob|PullChunkJob  $job
     */
    private function onSameRoute($job): void
    {
        if ($this->connectionName !== null) {
            $job->onConnection($this->connectionName);
        }
        if ($this->queueName !== null) {
            $job->onQueue($this->queueName);
        }
    }

    /**
     * Seconds between passes. 0 (the default) disables the reaper entirely.
     */
    private static function intervalSeconds(): int
    {
        $interval = config('elasticsearch.import.reaper_interval', 0);

        return is_numeric($interval) ? max(0, (int) $interval) : 0;
    }

    private function dispatchBatch(): int
    {
        return max(1, $this->intConfig('dispatch_batch', 1000));
    }

    /**
     * Floored at 0, not 1, so `redispatch_limit = 0` disables re-dispatch here
     * exactly as it does in {@see PullChunkJob::failed()} — the two paths share
     * one counter, so they have to read the budget the same way.
     */
    private function redispatchLimit(): int
    {
        return max(0, $this->intConfig('redispatch_limit', 3));
    }

    private function intConfig(string $key, int $default): int
    {
        $value = config('elasticsearch.import.'.$key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
