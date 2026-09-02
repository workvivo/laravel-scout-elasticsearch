<?php

declare(strict_types=1);

namespace Matchish\ScoutElasticSearch\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Matchish\ScoutElasticSearch\ElasticSearch\Config\Config;
use Matchish\ScoutElasticSearch\ElasticSearch\Index;
use Matchish\ScoutElasticSearch\Import\ImportRunStore;
use Matchish\ScoutElasticSearch\Import\ProfileDiagnostics;
use Matchish\ScoutElasticSearch\ImportLock;
use Matchish\ScoutElasticSearch\Jobs\Middleware\SkipIfImportRun;
use Matchish\ScoutElasticSearch\Jobs\Stages\PullFromSource;
use Matchish\ScoutElasticSearch\Searchable\ImportSource;
use OpenSearch\Client;
use Throwable;

final class PullChunkJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private ImportSource $source;
    private PullFromSource $stage;
    private Index $index;
    private string $token;
    private int $chunkId;
    private ?string $lockOwner;
    private int $lockTtl;
    private ?string $connectionName;
    private ?string $queueName;

    public int $tries = 1;
    public ?int $timeout = null;
    public ?int $backoffBase = null;
    public ?int $backoffCap = null;
    public ?int $retryUntilSeconds = null;

    public function __construct(
        ImportSource $source,
        PullFromSource $stage,
        Index $index,
        string $token,
        int $chunkId,
        ?string $lockOwner,
        int $lockTtl,
        ?string $connection,
        ?string $queue
    ) {
        $this->source = $source;
        $this->stage = $stage;
        $this->index = $index;
        $this->token = $token;
        $this->chunkId = $chunkId;
        $this->lockOwner = $lockOwner;
        $this->lockTtl = $lockTtl;
        $this->connectionName = $connection;
        $this->queueName = $queue;
        $this->timeout = Config::queueTimeout();
    }

    public function middleware(): array
    {
        return [new SkipIfImportRun($this->token, $this->source->searchableAs(), $this->lockOwner)];
    }

    public function handle(Client $elasticsearch): void
    {
        $store = app(ImportRunStore::class);

        // Cheap duplicate guard. A queue can hand the same message to a second
        // worker (SQS re-delivers once the visibility timeout lapses, even while
        // attempt 1 is still running), and re-indexing a chunk is idempotent —
        // but pointless. If the chunk already completed there is nothing to do.
        if ($store->isDone($this->token, $this->chunkId)) {
            $this->log('info', 'scout:import chunk skipped: already done', 'already_done');

            return;
        }

        // The lease owner identifies this *execution*, so it must be generated
        // here and not in the constructor: a redelivered SQS message carries the
        // very same serialized payload, and a constructor-generated token would
        // therefore be identical across attempts, making the lease re-entrant
        // for the duplicate it is supposed to detect.
        $owner = Str::random(40);
        $leaseTtl = $this->leaseTtl();

        // Losing the race means another attempt of this chunk is in flight (or
        // was hard-killed less than one lease window ago). Returning quietly is
        // correct: the holder either finishes the work or its own failure path
        // re-dispatches the chunk. Failing here would be a lie.
        if (! $store->claimChunk($this->token, $this->chunkId, $owner, $leaseTtl)) {
            $this->log('info', 'scout:import chunk skipped: another attempt holds the lease', 'in_flight');

            return;
        }

        try {
            $this->stage->handle($elasticsearch);

            // Before markDone(), not after: the count this chunk publishes may
            // be the one that completes the run, and a terminal watching with
            // --wait stops polling the moment done == total. Findings recorded
            // after that transition can arrive too late to ever be printed.
            $this->publishProfileFindings($store);

            $done = $store->markDone($this->token, $this->chunkId);

            if ($this->lockOwner !== null) {
                ImportLock::renew($this->source->searchableAs(), $this->lockOwner, $this->lockTtl);
            }
            $store->refreshTtls($this->token, $this->lockTtl);

            if ($done === $store->total($this->token)) {
                $job = new FinalizeJob($this->source, $this->index, $this->token, $this->lockOwner, $this->lockTtl);
                $job->timeout = Config::queueTimeout();
                if ($this->connectionName !== null) {
                    $job->onConnection($this->connectionName);
                }
                if ($this->queueName !== null) {
                    $job->onQueue($this->queueName);
                }
                Bus::dispatch($job);
            }
        } finally {
            // Both paths must clear the lease *before* failed() runs — the
            // worker calls handleJobException() after handle() throws, in this
            // same process, and failed() reads the lease to tell a genuine
            // failure from a duplicate delivery. A lease left behind by a clean
            // throw would be misread as "another attempt is running". Only a
            // hard kill (SIGKILL/OOM) skips this, and that is exactly the case
            // where the lease *should* survive until its TTL.
            $store->releaseChunk($this->token, $this->chunkId, $owner);
        }
    }

    /**
     * Publish this chunk's profile diagnoses into the run record.
     *
     * `--profile-samples` writes its numbers to the log of whichever worker ran
     * the chunk, on whichever host — which the operator who typed `scout:import`
     * is not watching. The run record is the one channel that already flows the
     * other way (`--wait` polls it), so the findings go there and the terminal
     * renders them from the codes.
     *
     * A DIAGNOSTIC MUST NEVER FAIL AN IMPORT. That is the whole reason for the
     * catch-all below, and it is the most important property of this method:
     * everything inside — reading the stage's metrics, evaluating the rules,
     * encoding the payload and every one of the store writes — is observation
     * about work that has ALREADY SUCCEEDED. A chunk whose documents are in the
     * index must stay done even if Redis rejects the script, the metrics array
     * has drifted, or json_encode chokes on invalid UTF-8. So the failure is
     * logged at warning and swallowed; it never reaches the caller, where it
     * would skip markDone() and turn a healthy chunk into a run-killing
     * failure.
     */
    private function publishProfileFindings(ImportRunStore $store): void
    {
        try {
            // 0 disables publication entirely, checked first so a disabled
            // install pays nothing: no metrics read, no rule evaluation, no
            // store call.
            $cap = $this->intConfig('profile_findings', 20);

            if ($cap < 1) {
                return;
            }

            // Null for every chunk that was not sampled, which is the common
            // case even during a --profile-samples run.
            $metrics = $this->stage->lastProfile();

            if ($metrics === null) {
                return;
            }

            // The same resolution leaseTtl() uses, minus its 60s fallback:
            // passing null when nothing is configured is deliberate. A lease
            // needs *some* number and 60 is a safe one, but a diagnosis built on
            // a guessed timeout would report a chunk as near a limit that does
            // not exist. Unknown suppresses the timeout rules instead.
            $timeout = $this->timeout ?? Config::queueTimeout();

            foreach (ProfileDiagnostics::from($metrics, $timeout) as $finding) {
                $payload = json_encode($finding['data']);

                $store->recordProfileFinding(
                    $this->token,
                    $finding['code'],
                    $finding['weight'],
                    $payload === false ? '[]' : $payload,
                    $this->lockTtl,
                    $cap
                );
            }
        } catch (Throwable $e) {
            $this->log('warning', 'scout:import chunk profile findings not recorded', 'profile_publish_failed', $e);
        }
    }

    /**
     * Classify a chunk failure using Redis state only.
     *
     * Nothing set during handle() is visible here: CallQueuedHandler::failed()
     * deserializes a *fresh* command and never attaches the job, so instance
     * state is gone and attempts() always reports 1 — deliberately not logged,
     * it would be actively misleading. The classification therefore rests
     * entirely on the chunk lease and the done set.
     */
    public function failed(Throwable $e): void
    {
        $store = app(ImportRunStore::class);

        // The chunk finished; whatever failed afterwards cannot cost us the run.
        if ($store->isDone($this->token, $this->chunkId)) {
            $this->log('info', 'scout:import chunk failure ignored: chunk already done', 'already_done', $e);

            return;
        }

        // A live lease means this failure is ambiguous: either another attempt
        // is working on the chunk right now (duplicate delivery — Laravel fails
        // the redelivered copy *before* handle(), so the job middleware never
        // runs and cannot skip it), or an attempt was hard-killed mid-flight and
        // its lease is running down. Re-dispatch rather than judge: chunk bounds
        // are frozen into the payload and documents overwrite by stable _id, so
        // running a chunk again is always safe, while failing the run here would
        // destroy a possibly multi-hour import over a harmless duplicate.
        if ($store->chunkInFlight($this->token, $this->chunkId)) {
            $attempt = $store->bumpRedispatch($this->token, $this->chunkId);

            if ($attempt <= $this->redispatchLimit()) {
                // Wait out the lease so the re-dispatched copy can actually
                // claim it — the in-flight attempt has either completed (and
                // released) by then, or was killed and its lease has expired.
                $delay = $this->leaseTtl() + random_int(1, 30);

                $this->log('warning', 'scout:import chunk failure re-dispatched', 'in_flight_redispatched', $e, [
                    'redispatch' => $attempt,
                    'delay' => $delay,
                ]);

                Bus::dispatch($this->redispatch($delay));

                // Not an error: no report(), and the run status is untouched.
                return;
            }

            // A chunk that keeps coming back ambiguous must not loop forever, so
            // once the budget is spent it is treated as a genuine failure.
            $this->log('warning', 'scout:import chunk re-dispatch budget exhausted', 'genuine', $e, [
                'redispatch' => $attempt,
            ]);
        }

        // No lease, not done: a genuine failure. It consumes one slot of the
        // run's failure budget (idempotent per chunk), and only exhausting the
        // budget rolls the import back.
        $failures = $store->recordFailure($this->token, $this->chunkId);
        $budget = $this->failureBudget();

        $this->log('error', 'scout:import chunk failed', 'genuine', $e, [
            'failures' => $failures,
            'failure_budget' => $budget,
        ]);

        report($e);

        if ($failures < $budget) {
            return;
        }

        // failRun() only transitions from running, so exactly one chunk ever
        // gets to trigger the rollback.
        if (! $store->failRun($this->token)) {
            return;
        }

        $job = (new RollbackImportJob($this->source, $this->index, $this->lockOwner))
            ->delay(now()->addSeconds($this->rollbackDelay()));
        $job->timeout = Config::queueTimeout();

        if ($this->connectionName !== null) {
            $job->onConnection($this->connectionName);
        }
        if ($this->queueName !== null) {
            $job->onQueue($this->queueName);
        }
        Bus::dispatch($job);
    }

    /**
     * A fresh copy of this chunk job, retry configuration included, delayed past
     * the current lease window.
     */
    private function redispatch(int $delay): self
    {
        $job = new self(
            $this->source,
            $this->stage,
            $this->index,
            $this->token,
            $this->chunkId,
            $this->lockOwner,
            $this->lockTtl,
            $this->connectionName,
            $this->queueName
        );

        $job->tries = $this->tries;
        $job->timeout = $this->timeout;
        $job->backoffBase = $this->backoffBase;
        $job->backoffCap = $this->backoffCap;
        $job->retryUntilSeconds = $this->retryUntilSeconds;

        $job->delay(now()->addSeconds($delay));

        if ($this->connectionName !== null) {
            $job->onConnection($this->connectionName);
        }
        if ($this->queueName !== null) {
            $job->onQueue($this->queueName);
        }

        return $job;
    }

    /**
     * How long a chunk may hold its lease: the whole time the job is allowed to
     * run, plus a pad so a job killed right at its timeout still looks in-flight
     * to the duplicate that shows up moments later.
     */
    private function leaseTtl(): int
    {
        $timeout = (int) ($this->timeout ?? Config::queueTimeout() ?? 60);

        if ($timeout < 1) {
            $timeout = 60;
        }

        return $timeout + $this->intConfig('chunk_lease_pad', 120);
    }

    private function redispatchLimit(): int
    {
        return max(0, $this->intConfig('redispatch_limit', 3));
    }

    /**
     * Distinct chunk failures tolerated before the run is rolled back. The
     * default of 1 reproduces the historical behaviour exactly: one genuine
     * chunk failure still kills the run.
     */
    private function failureBudget(): int
    {
        return max(1, $this->intConfig('failure_budget', 1));
    }

    private function intConfig(string $key, int $default): int
    {
        $value = config('elasticsearch.import.'.$key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function log(string $level, string $message, string $classification, ?Throwable $e = null, array $context = []): void
    {
        $context = array_merge([
            'searchable' => $this->source->searchableAs(),
            'index' => $this->index->name(),
            'token' => $this->token,
            'chunk' => $this->chunkId,
            'classification' => $classification,
        ], $context);

        if ($e !== null) {
            $context['exception'] = get_class($e);
            $context['message'] = $e->getMessage();
        }

        logger()->log($level, $message, $context);
    }

    private function rollbackDelay(): int
    {
        $delay = config('elasticsearch.import.rollback_delay', 5);

        return is_numeric($delay) ? (int) $delay : 5;
    }

    public function backoff(): ?array
    {
        $count = $this->tries - 1;

        if ($this->backoffBase === null || $count < 1) {
            // Not []: Queue::getJobBackoff() implodes the array, and the empty
            // string ends up as (int) 0 in Worker::calculateBackoff() — an
            // immediate re-release with a zero visibility timeout.
            return null;
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

    public function retryUntil(): ?\DateTimeInterface
    {
        return $this->retryUntilSeconds ? now()->addSeconds($this->retryUntilSeconds) : null;
    }
}
