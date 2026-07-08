<?php

namespace Matchish\ScoutElasticSearch\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\SkipIfBatchCancelled;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Matchish\ScoutElasticSearch\ImportLock;
use Matchish\ScoutElasticSearch\Jobs\Stages\StageInterface;
use OpenSearch\Client;

/**
 * Runs a single import stage as its own queued job so the pipeline can fan out
 * across workers (the `--parallel` path in
 * {@see \Matchish\ScoutElasticSearch\Console\Commands\ImportCommand}). Sequential
 * imports still run every stage in-process inside the Import job.
 *
 * @internal
 */
final class StageJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @var StageInterface
     */
    private $stage;

    public ?int $timeout = null;

    /**
     * Prepare-phase heartbeat. When set (only on the --parallel prepare stages,
     * never on the fanned-out chunk jobs), this stage publishes a marker to the
     * cache the instant a worker starts running it, so a waiting command can
     * tell the chain has been *picked up and is progressing* rather than still
     * sitting unclaimed on the queue. Null = no heartbeat.
     *
     * @var string|null
     */
    public ?string $heartbeatKey = null;
    /**
     * Monotonic step number so the waiter can tell one stage's heartbeat from
     * the next (clean up = 1, create index = 2, planning = 3) and reset its
     * patience window every time the value advances.
     *
     * @var int|null
     */
    public ?int $heartbeatSeq = null;
    /**
     * @var int
     */
    public int $heartbeatTtl = 3600;

    /**
     * Renewable-lease carry for the prepare stages. Set (only on the CleanUp /
     * CreateWriteIndex StageJobs in the --parallel chain) so the stage renews
     * the {@see ImportLock} the instant a worker starts running it, keeping the
     * lease alive across the prepare window (clean up + create index + planning)
     * that would otherwise be entirely unrenewed between the command acquiring
     * the lock and {@see DispatchPullBatch} first renewing it. Deliberately
     * independent of {@see withHeartbeat} — the heartbeat only fires under
     * --wait, but the lease must be renewed on every parallel run. Null = the
     * chunk jobs, which rely on the batch progress() callback for renewal.
     *
     * @var string|null
     */
    public ?string $lockSearchableAs = null;
    /**
     * @var string|null
     */
    public ?string $lockOwner = null;
    /**
     * @var int
     */
    public int $lockRenewTtl = 3600;

    /**
     * Max attempts for this job. Defaults to 1 (today's behaviour: a chunk that
     * throws fails immediately). Overridden per-instance on the fanned-out chunk
     * jobs from `elasticsearch.import.batch.tries` so a transient failure (e.g. a
     * job_batches lock-wait timeout raised in worker bookkeeping) can be retried
     * against an idempotent re-index instead of cancelling the whole batch. Never
     * raised on the prepare stages: {@see \Matchish\ScoutElasticSearch\Jobs\Stages\CreateWriteIndex}
     * is non-idempotent and a retry would fail with resource_already_exists.
     *
     * @var int
     */
    public int $tries = 1;
    /**
     * Exponential-backoff base (seconds) for chunk-job retries. Null on the
     * prepare stages, which never back off. See {@see backoff()}.
     *
     * @var int|null
     */
    public ?int $backoffBase = null;
    /**
     * Upper bound (seconds) on a single backoff delay. Null falls back to the
     * base, i.e. a flat delay.
     *
     * @var int|null
     */
    public ?int $backoffCap = null;
    /**
     * Optional wall-clock ceiling (seconds from first dispatch) after which the
     * job stops retrying regardless of {@see $tries}. Null = bounded by tries
     * only. See {@see retryUntil()}.
     *
     * @var int|null
     */
    public ?int $retryUntilSeconds = null;

    public function __construct(StageInterface $stage)
    {
        $this->stage = $stage;
    }

    /**
     * Skip this job when its batch has been cancelled (a sibling chunk already
     * failed). Without this, once a failed chunk cancels the batch the remaining
     * / retrying chunks keep fetching and bulk-indexing into an index the
     * rollback is about to delete — the self-inflicted error storm. No-ops
     * safely on the prepare stages, which are chained rather than batched, so
     * `batch()` is null there.
     */
    public function middleware(): array
    {
        return [new SkipIfBatchCancelled];
    }

    /**
     * Renew the {@see ImportLock} lease when this stage begins. Only the prepare
     * stages set this (see {@see $lockSearchableAs}).
     */
    public function withLockRenew(string $searchableAs, string $owner, int $ttl): self
    {
        $this->lockSearchableAs = $searchableAs;
        $this->lockOwner = $owner;
        $this->lockRenewTtl = $ttl;

        return $this;
    }

    /**
     * Per-attempt retry delays. Evaluated once at push time and serialized into
     * the job payload (Laravel then picks the element for the current attempt),
     * so this must NOT read {@see attempts()} — it builds the whole schedule up
     * front. Equal jitter (delay/2 + rand(0, delay/2)) both keeps a floor so a
     * retry never hot-loops and de-clusters the sibling chunks that were all
     * released at the same instant, which is what caused the lock pile-up.
     *
     * Returns an empty schedule when there is nothing to retry — the prepare
     * stages (no backoff base) and any job left at the default tries=1.
     *
     * @return int[]
     */
    public function backoff(): array
    {
        $count = $this->tries - 1;

        if ($this->backoffBase === null || $count < 1) {
            return [];
        }

        $cap = $this->backoffCap ?? $this->backoffBase;
        $count = min(50, $count);

        $delays = [];
        for ($n = 1; $n <= $count; $n++) {
            $delay = (int) min($cap, $this->backoffBase * (2 ** min(20, $n - 1)));
            $half = intdiv($delay, 2);
            $delays[] = $half + random_int(0, $half);
        }

        return $delays;
    }

    /**
     * Optional wall-clock retry deadline. Evaluated once at push time.
     */
    public function retryUntil(): ?\DateTimeInterface
    {
        return $this->retryUntilSeconds ? now()->addSeconds($this->retryUntilSeconds) : null;
    }

    /**
     * Make this stage publish a prepare-phase heartbeat when it begins, so
     * {@see \Matchish\ScoutElasticSearch\Console\Commands\ImportCommand::waitForBatch}
     * can distinguish "no worker has picked up the chain" from "a worker is
     * actively preparing".
     */
    public function withHeartbeat(string $key, int $seq, int $ttl): self
    {
        $this->heartbeatKey = $key;
        $this->heartbeatSeq = $seq;
        $this->heartbeatTtl = $ttl;

        return $this;
    }

    public function handle(Client $elasticsearch): void
    {
        // Recheck cancellation after the middleware admitted us: closes the
        // admit->execute gap so a chunk that lost the race to a sibling's
        // failure does no work. Cannot preempt a bulk already in progress.
        if ($this->batch()?->cancelled()) {
            return;
        }

        if ($this->heartbeatKey !== null) {
            Cache::put($this->heartbeatKey, [
                'seq' => $this->heartbeatSeq,
                'stage' => $this->stage->title(),
            ], $this->heartbeatTtl);
        }

        // Prepare stages renew the lease as they begin so a long prepare window
        // (a busy queue between chain hops) cannot let it lapse and admit a
        // second, overlapping run of the same model.
        if ($this->lockOwner !== null) {
            ImportLock::renew($this->lockSearchableAs, $this->lockOwner, $this->lockRenewTtl);
        }

        $this->stage->handle($elasticsearch);
    }
}
