<?php

declare(strict_types=1);

namespace Matchish\ScoutElasticSearch\Import;

use Illuminate\Queue\SqsQueue;
use Matchish\ScoutElasticSearch\ElasticSearch\Config\Config;

/**
 * Read-only pre-flight for the timing invariant every `--parallel` import
 * depends on:
 *
 *     p99 chunk  <<  effective job timeout  <  redelivery window  <  shutdown grace
 *
 * The job's own alarm must win the race against the broker's redelivery,
 * because the timeout path fails-and-deletes the message cleanly, whereas a
 * redelivery that overtakes a still-running chunk manufactures a phantom
 * second delivery: on SQS `attempts()` IS `ApproximateReceiveCount`, so
 * delivery #2 arrives with attempts=2 and the worker fails it BEFORE the job
 * body ever runs. That is how a whole import can be destroyed by a queue whose
 * `VisibilityTimeout` (30s by default on a hand-made queue) sits below the
 * effective job timeout (60s when SCOUT_QUEUE_TIMEOUT is unset, because the
 * worker's `--timeout` then governs).
 *
 * This class only *reports*. It dispatches nothing, writes no index, takes no
 * lock. Its single piece of I/O is one read-only SQS `GetQueueAttributes`
 * call, which lives behind an injectable seam so the whole checker is unit
 * testable with no AWS SDK present, and any throwable from it degrades to a
 * warning — a failed probe proves nothing, so it can never be fatal.
 *
 * Only positively PROVEN misconfiguration is fatal (a probed VisibilityTimeout
 * that is demonstrably at or below the governing job timeout). Everything else
 * warns, so the check cannot break a setup that works today. The single
 * exception is an effective job timeout above the AWS VisibilityTimeout ceiling
 * of 12 hours, which makes the ordering unsatisfiable for every conceivable
 * queue and therefore needs no probe to prove — see F0 in inspect().
 *
 * @phpstan-type ProbeResult array{visibility_timeout: int|null, max_receive_count: int|null, dead_letter: string|null, queue_url: string|null, error: string|null}
 * @phpstan-type Fact array{key: string, label: string, value: string, raw: int|string|null, provenance: string, provenance_label: string}
 * @phpstan-type Finding array{severity: string, code: string, message: string}
 * @phpstan-type Report array{enabled: bool, connection: string|null, driver: string|null, queue: string|null, job_timeout: int|null, effective_timeout: int, effective_timeout_source: string, probe: ProbeResult, facts: array<int, Fact>, findings: array<int, Finding>, fatal: bool}
 */
final class QueueTimingPreflight
{
    /**
     * The framework's documented `queue:work --timeout` default, used as the
     * governing timeout when neither SCOUT_QUEUE_TIMEOUT nor the declared
     * SCOUT_IMPORT_WORKER_TIMEOUT tells us better. The worker runs in another
     * process (possibly on another host), so its real `--timeout` is not
     * discoverable from here — it can only be declared.
     */
    public const DEFAULT_WORKER_TIMEOUT = 60;

    /**
     * The largest VisibilityTimeout SQS accepts: 12 hours. An AWS hard cap, not
     * a preference — SetQueueAttributes rejects anything above it. So this is
     * also the ceiling on the whole timing invariant when the driver is `sqs`:
     * the redelivery window can never be ordered above a job timeout that has
     * already passed 43200s.
     */
    public const SQS_MAX_VISIBILITY_TIMEOUT = 43200;

    /**
     * Seconds of headroom a correctly ordered redelivery window should keep
     * above the job timeout before we call the margin thin.
     */
    public const THIN_MARGIN = 30;

    public const SEVERITY_FATAL = 'fatal';
    public const SEVERITY_WARNING = 'warning';

    /** Read from the broker during this run. A fact. */
    public const PROVENANCE_PROBED = 'probed';

    /** Read from this application's configuration. The operator declared it. */
    public const PROVENANCE_DECLARED = 'declared';

    /** Nothing was declared, so a documented default was substituted. A guess. */
    public const PROVENANCE_ASSUMED = 'assumed default';

    /** Neither discoverable nor declared. */
    public const PROVENANCE_UNKNOWN = 'unknown';

    public const CODE_TIMEOUT_ABOVE_SQS_CEILING = 'timeout_above_sqs_ceiling';
    public const CODE_SQS_VISIBILITY_BELOW_TIMEOUT = 'sqs_visibility_below_timeout';
    public const CODE_SQS_VISIBILITY_BELOW_WORKER_TIMEOUT = 'sqs_visibility_below_worker_timeout';
    public const CODE_QUEUE_PROBE_UNAVAILABLE = 'queue_probe_unavailable';
    public const CODE_JOB_TIMEOUT_UNSET = 'job_timeout_unset';
    public const CODE_RETRY_UNTIL_SET = 'retry_until_set';
    public const CODE_SQS_MAX_RECEIVE_COUNT_BELOW_TRIES = 'sqs_max_receive_count_below_tries';
    public const CODE_SQS_MISSING_DEAD_LETTER = 'sqs_missing_dead_letter';
    public const CODE_LOCK_TTL_BELOW_TIMEOUT = 'lock_ttl_below_timeout';
    public const CODE_SHUTDOWN_GRACE_BELOW_TIMEOUT = 'shutdown_grace_below_timeout';
    public const CODE_CHUNK_DURATION_HEADROOM = 'chunk_duration_headroom';
    public const CODE_RETRY_AFTER_BELOW_TIMEOUT = 'retry_after_below_timeout';
    public const CODE_SQS_VISIBILITY_MARGIN_THIN = 'sqs_visibility_margin_thin';

    /** The effective timeout came from SCOUT_QUEUE_TIMEOUT (the job's own alarm). */
    public const SOURCE_JOB_TIMEOUT = 'job_timeout';

    /** The effective timeout came from the declared SCOUT_IMPORT_WORKER_TIMEOUT. */
    public const SOURCE_DECLARED_WORKER_TIMEOUT = 'declared_worker_timeout';

    /** Nothing was declared: the framework's `queue:work --timeout` default. */
    public const SOURCE_ASSUMED_WORKER_TIMEOUT = 'assumed_worker_timeout';

    /**
     * The queue-attribute probe seam.
     *
     * A callable taking the resolved connection name and queue name and
     * returning (any subset of) the ProbeResult shape. Null means "use the
     * real SQS probe". Tests inject a closure and never touch AWS.
     *
     * @var (callable(?string, ?string): mixed)|null
     */
    private $probe;

    /**
     * @param  (callable(?string, ?string): mixed)|null  $probe  Queue-attribute probe; defaults to self::defaultProbe().
     */
    public function __construct(?callable $probe = null)
    {
        $this->probe = $probe;
    }

    /**
     * Master switch: `elasticsearch.import.preflight.enabled`.
     *
     * inspect() honours this itself (it then skips the probe and reports no
     * findings), but callers can check it up front to stay silent entirely.
     */
    public static function isEnabled(): bool
    {
        return (bool) config('elasticsearch.import.preflight.enabled', true);
    }

    /**
     * Inspect the resolved connection/queue and return the report.
     *
     * Pure apart from the single read-only GetQueueAttributes call, which only
     * happens when the driver is `sqs` and both the master switch and
     * `preflight.probe_queue` are on.
     *
     * @param  string|null  $connection  Resolved queue connection (ImportCommand::resolvedConnection()).
     * @param  string|null  $queue  Resolved queue name, null = the connection's default queue.
     * @param  int|null  $chunkSize  The run's resolved chunk size when the caller already knows it.
     * @return Report
     */
    public function inspect(?string $connection, ?string $queue, ?int $chunkSize = null): array
    {
        $enabled = self::isEnabled();
        $driver = $this->driverFor($connection);

        $probe = self::emptyProbe();
        if ($enabled && $driver === 'sqs') {
            $probe = $this->probeQueueAttributes($connection, $queue);
        }

        $jobTimeout = $this->jobTimeout();
        [$workerTimeout, $workerProvenance] = $this->declaredSeconds('worker_timeout');

        if ($jobTimeout !== null) {
            $effective = $jobTimeout;
            $effectiveSource = self::SOURCE_JOB_TIMEOUT;
        } elseif ($workerTimeout > 0) {
            $effective = $workerTimeout;
            $effectiveSource = self::SOURCE_DECLARED_WORKER_TIMEOUT;
        } else {
            $effective = self::DEFAULT_WORKER_TIMEOUT;
            $effectiveSource = self::SOURCE_ASSUMED_WORKER_TIMEOUT;
        }

        [$shutdownGrace, $shutdownProvenance] = $this->declaredSeconds('shutdown_grace');
        [$expectedChunk, $expectedChunkProvenance] = $this->declaredSeconds('expected_chunk_seconds');

        [$tries, $triesProvenance] = $this->intConfigFact('elasticsearch.import.retry.tries', 1);
        $tries = max(1, $tries);
        [$retryUntil, $retryUntilProvenance] = $this->intConfigFact('elasticsearch.import.retry.retry_until', 0);
        [$failureBudget, $failureBudgetProvenance] = $this->intConfigFact('elasticsearch.import.failure_budget', 1);
        [$redispatchLimit, $redispatchProvenance] = $this->intConfigFact('elasticsearch.import.redispatch_limit', 3);
        [$dispatchBatch, $dispatchBatchProvenance] = $this->intConfigFact('elasticsearch.import.dispatch_batch', 1000);
        [$lockTtl, $lockTtlProvenance] = $this->intConfigFact('elasticsearch.import.lock_ttl', 3600);
        [$chunk, $chunkProvenance] = $this->chunkFact($chunkSize);

        $retryAfter = $this->retryAfter($connection);
        $visibility = $probe['visibility_timeout'];
        $maxReceiveCount = $probe['max_receive_count'];
        // Only a probe that was actually attempted AND came back clean licenses
        // a "probed" claim. A skipped probe knows nothing, which is not the same
        // as knowing there is nothing.
        $probeAttempted = $enabled && $driver === 'sqs';
        $probed = $probeAttempted && $probe['error'] === null;
        $async = $driver !== null && $driver !== 'sync';

        $queueLabel = $probe['queue_url'] ?? $queue ?? $this->text('preflight_value_driver_default');

        $findings = [];

        // ---- FATAL: proven, probed re-delivery races — plus F0, the one
        // ---- misconfiguration that configuration alone already proves. -------

        // F0 — the invariant is not merely violated here, it is UNSATISFIABLE.
        // SQS caps VisibilityTimeout at 12 hours (43200s), so once the effective
        // job timeout climbs above that ceiling there is NO legal AWS value that
        // can sit above it: `job_timeout < VisibilityTimeout` has no solution on
        // any SQS queue that could ever exist.
        //
        // This is deliberately the ONLY fatal that fires without probed
        // evidence. F1/F2 need a probe because "this queue's window is too
        // short" is a claim about one particular queue, and an unread queue
        // proves nothing. An effective timeout above the AWS ceiling is a
        // different kind of claim: it is provable from configuration alone,
        // because every possible queue fails it, so a probe has nothing left to
        // establish. `queue:work sqs --timeout=86400` is a real setting in the
        // wild and PHP cannot see it from here (only the declared
        // `preflight.worker_timeout` makes it visible), which is why the message
        // names --timeout explicitly as the usual cause.
        $aboveSqsCeiling = $driver === 'sqs' && $effective > self::SQS_MAX_VISIBILITY_TIMEOUT;

        if ($aboveSqsCeiling) {
            $findings[] = $this->finding(self::SEVERITY_FATAL, self::CODE_TIMEOUT_ABOVE_SQS_CEILING, [
                'queue' => $queueLabel,
                'timeout' => $effective,
                'source' => $this->sourceLabel($effectiveSource),
                'ceiling' => self::SQS_MAX_VISIBILITY_TIMEOUT,
                // The remedy points the other way: the job timeout comes DOWN to
                // leave the usual thin margin under the ceiling, so a legal
                // VisibilityTimeout (up to the ceiling itself) can then be
                // ordered above it.
                'suggested' => self::SQS_MAX_VISIBILITY_TIMEOUT - self::THIN_MARGIN,
            ]);
        }

        // F1/F2 are suppressed once F0 has fired, and F0 is ordered ahead of them
        // for the same reason: it subsumes them. Their whole remedy is "raise
        // VisibilityTimeout to :suggested s", and above the ceiling that
        // suggested value is one AWS refuses — printing it would send the
        // operator to run a SetQueueAttributes call that cannot succeed. F0
        // carries the same proven re-delivery race with the only remedy that
        // exists up here: bring the job timeout back under the ceiling.

        // F1 — SCOUT_QUEUE_TIMEOUT is set and the probed window is at or below
        // it: SQS re-releases the message before the job's own alarm can fire.
        if (! $aboveSqsCeiling && $driver === 'sqs' && $visibility !== null && $jobTimeout !== null && $visibility <= $jobTimeout) {
            $findings[] = $this->finding(self::SEVERITY_FATAL, self::CODE_SQS_VISIBILITY_BELOW_TIMEOUT, [
                'queue' => $queueLabel,
                'visibility' => $visibility,
                'timeout' => $jobTimeout,
                'suggested' => $jobTimeout + self::THIN_MARGIN,
            ]);
        }

        // F2 — the PERF case: SCOUT_QUEUE_TIMEOUT unset (so the worker's
        // --timeout governs, declared or assumed 60) and the probed window is
        // at or below it. Same proven race, one level of indirection away.
        if (! $aboveSqsCeiling && $driver === 'sqs' && $visibility !== null && $jobTimeout === null && $visibility <= $effective) {
            $findings[] = $this->finding(self::SEVERITY_FATAL, self::CODE_SQS_VISIBILITY_BELOW_WORKER_TIMEOUT, [
                'queue' => $queueLabel,
                'visibility' => $visibility,
                'timeout' => $effective,
                'source' => $this->sourceLabel($effectiveSource),
                'suggested' => $effective + self::THIN_MARGIN,
            ]);
        }

        // ---- WARNINGS: everything unproven. ---------------------------------

        // W1 — no probe, so the ordering is simply unverified.
        if ($probeAttempted && ! $probed) {
            $findings[] = $this->finding(self::SEVERITY_WARNING, self::CODE_QUEUE_PROBE_UNAVAILABLE, [
                'queue' => $queueLabel,
                'reason' => (string) $probe['error'],
            ]);
        }

        // W2 — the governing timeout lives in a process we cannot see.
        if ($async && $jobTimeout === null) {
            $findings[] = $this->finding(self::SEVERITY_WARNING, self::CODE_JOB_TIMEOUT_UNSET, [
                'timeout' => $effective,
                'source' => $this->sourceLabel($effectiveSource),
            ]);
        }

        // W3 — retry_until silently disables tries (Worker.php:515 and :541 both
        // gate the maxTries comparison on `! $retryUntil`) and is stamped once at
        // dispatch, so on a large fan-out every still-queued chunk fails on its
        // first receive after the deadline, having never run.
        if ($retryUntil !== 0) {
            $findings[] = $this->finding(self::SEVERITY_WARNING, self::CODE_RETRY_UNTIL_SET, [
                'retry_until' => $retryUntil,
                'tries' => $tries,
            ]);
        }

        // W4 — the redrive policy will not permit the configured retries.
        if ($driver === 'sqs' && $maxReceiveCount !== null && $maxReceiveCount <= $tries) {
            $findings[] = $this->finding(self::SEVERITY_WARNING, self::CODE_SQS_MAX_RECEIVE_COUNT_BELOW_TRIES, [
                'queue' => $queueLabel,
                'max_receive_count' => $maxReceiveCount,
                'tries' => $tries,
                'suggested' => $tries + 1,
            ]);
        }

        // W5 — exhausted messages vanish silently with no dead-letter target.
        if ($probed && $probe['dead_letter'] === null) {
            $findings[] = $this->finding(self::SEVERITY_WARNING, self::CODE_SQS_MISSING_DEAD_LETTER, [
                'queue' => $queueLabel,
            ]);
        }

        // W6 — the import lease can lapse under a single still-running chunk.
        if ($lockTtl <= $effective) {
            $findings[] = $this->finding(self::SEVERITY_WARNING, self::CODE_LOCK_TTL_BELOW_TIMEOUT, [
                'lock_ttl' => $lockTtl,
                'timeout' => $effective,
            ]);
        }

        // W7 — a deploy will SIGKILL mid-chunk.
        if ($shutdownGrace > 0 && $shutdownGrace < $effective) {
            $findings[] = $this->finding(self::SEVERITY_WARNING, self::CODE_SHUTDOWN_GRACE_BELOW_TIMEOUT, [
                'grace' => $shutdownGrace,
                'timeout' => $effective,
            ]);
        }

        // W8 — a chunk near p99 will trip the timeout.
        if ($expectedChunk > 0 && $expectedChunk * 2 > $effective) {
            $findings[] = $this->finding(self::SEVERITY_WARNING, self::CODE_CHUNK_DURATION_HEADROOM, [
                'chunk_seconds' => $expectedChunk,
                'timeout' => $effective,
                'suggested' => $expectedChunk * 2,
            ]);
        }

        // W9 — the same re-delivery race on database/redis/beanstalkd, where
        // reserve/release semantics make it less catastrophic than on SQS.
        if ($async && $driver !== 'sqs' && $retryAfter !== null && $retryAfter > 0 && $retryAfter <= $effective) {
            $findings[] = $this->finding(self::SEVERITY_WARNING, self::CODE_RETRY_AFTER_BELOW_TIMEOUT, [
                'connection' => $connection ?? $this->text('preflight_value_driver_default'),
                'driver' => (string) $driver,
                'retry_after' => $retryAfter,
                'timeout' => $effective,
                'suggested' => $effective + self::THIN_MARGIN,
            ]);
        }

        // W10 — correct ordering, thin margin.
        if ($driver === 'sqs' && $visibility !== null && $visibility > $effective && ($visibility - $effective) < self::THIN_MARGIN) {
            $findings[] = $this->finding(self::SEVERITY_WARNING, self::CODE_SQS_VISIBILITY_MARGIN_THIN, [
                'queue' => $queueLabel,
                'visibility' => $visibility,
                'timeout' => $effective,
                'margin' => $visibility - $effective,
                'suggested' => $effective + self::THIN_MARGIN,
            ]);
        }

        if (! $enabled) {
            $findings = [];
        }

        $facts = [
            $this->fact('connection', $connection, $connection !== null ? self::PROVENANCE_DECLARED : self::PROVENANCE_UNKNOWN),
            $this->fact('driver', $driver, $driver !== null ? self::PROVENANCE_DECLARED : self::PROVENANCE_UNKNOWN),
            $this->fact('queue', $queue, $queue !== null ? self::PROVENANCE_DECLARED : self::PROVENANCE_ASSUMED, $queue ?? $this->text('preflight_value_driver_default')),
            $this->fact('queue_url', $probe['queue_url'], $probe['queue_url'] !== null ? self::PROVENANCE_PROBED : self::PROVENANCE_UNKNOWN),
            $this->fact('job_timeout', $jobTimeout, $jobTimeout !== null ? self::PROVENANCE_DECLARED : self::PROVENANCE_UNKNOWN, $jobTimeout !== null ? $this->seconds($jobTimeout) : null),
            $this->fact('effective_timeout', $effective, $effectiveSource === self::SOURCE_ASSUMED_WORKER_TIMEOUT ? self::PROVENANCE_ASSUMED : self::PROVENANCE_DECLARED, $this->seconds($effective).' ('.$this->sourceLabel($effectiveSource).')'),
            $this->fact('worker_timeout', $workerTimeout > 0 ? $workerTimeout : null, $workerProvenance, $workerTimeout > 0 ? $this->seconds($workerTimeout) : null),
            $this->fact('visibility_timeout', $visibility, $visibility !== null ? self::PROVENANCE_PROBED : self::PROVENANCE_UNKNOWN, $visibility !== null ? $this->seconds($visibility) : null),
            $this->fact('retry_after', $retryAfter, $retryAfter !== null ? self::PROVENANCE_DECLARED : self::PROVENANCE_UNKNOWN, $retryAfter !== null ? $this->seconds($retryAfter) : null, $driver === 'sqs' ? 'preflight_fact_retry_after_sqs' : null),
            $this->fact('max_receive_count', $maxReceiveCount, $maxReceiveCount !== null ? self::PROVENANCE_PROBED : self::PROVENANCE_UNKNOWN),
            $this->fact('dead_letter', $probe['dead_letter'], $probed ? self::PROVENANCE_PROBED : self::PROVENANCE_UNKNOWN, $probed && $probe['dead_letter'] === null ? $this->text('preflight_value_none') : $probe['dead_letter']),
            $this->fact('tries', $tries, $triesProvenance),
            $this->fact('retry_until', $retryUntil, $retryUntilProvenance, $retryUntil === 0 ? $this->text('preflight_value_off') : $this->seconds($retryUntil)),
            $this->fact('failure_budget', $failureBudget, $failureBudgetProvenance),
            $this->fact('redispatch_limit', $redispatchLimit, $redispatchProvenance),
            $this->fact('dispatch_batch', $dispatchBatch, $dispatchBatchProvenance),
            $this->fact('lock_ttl', $lockTtl, $lockTtlProvenance, $this->seconds($lockTtl)),
            $this->fact('chunk', $chunk, $chunkProvenance),
            $this->fact('shutdown_grace', $shutdownGrace > 0 ? $shutdownGrace : null, $shutdownProvenance, $shutdownGrace > 0 ? $this->seconds($shutdownGrace) : null),
            $this->fact('expected_chunk_seconds', $expectedChunk > 0 ? $expectedChunk : null, $expectedChunkProvenance, $expectedChunk > 0 ? $this->seconds($expectedChunk) : null),
        ];

        return [
            'enabled' => $enabled,
            'connection' => $connection,
            'driver' => $driver,
            'queue' => $queue,
            'job_timeout' => $jobTimeout,
            'effective_timeout' => $effective,
            'effective_timeout_source' => $effectiveSource,
            'probe' => $probe,
            'facts' => $facts,
            'findings' => $findings,
            'fatal' => self::hasFatal(['findings' => $findings]),
        ];
    }

    /**
     * Are there any fatal findings? Accepts a full report or anything carrying
     * a `findings` list, so callers can pass either.
     *
     * @param  array{findings?: array<int, array{severity?: string}>}  $report
     */
    public static function hasFatal(array $report): bool
    {
        foreach ($report['findings'] ?? [] as $finding) {
            if (($finding['severity'] ?? null) === self::SEVERITY_FATAL) {
                return true;
            }
        }

        return false;
    }

    /**
     * Re-label every fatal finding as a warning and clear the fatal flag, for
     * the `--force` path: the operator has taken the wheel, so the findings are
     * still worth printing but must not abort the run.
     *
     * @param  Report  $report
     * @return Report
     */
    public static function withoutFatals(array $report): array
    {
        $findings = [];
        foreach ($report['findings'] as $finding) {
            $finding['severity'] = self::SEVERITY_WARNING;
            $findings[] = $finding;
        }

        $report['findings'] = $findings;
        $report['fatal'] = false;

        return $report;
    }

    /**
     * The real probe: one read-only SQS GetQueueAttributes call.
     *
     * Guarded on both sides — `Aws\Sqs\SqsClient` is only a `suggest` of
     * laravel/framework and may be absent, and the IAM role may lack
     * `sqs:GetQueueAttributes`. Any throwable becomes an `error` string, never
     * an exception, because a failed probe is always a warning.
     *
     * @return ProbeResult
     */
    public static function defaultProbe(?string $connection, ?string $queue): array
    {
        $result = self::emptyProbe();

        if (! class_exists('Aws\Sqs\SqsClient')) {
            $result['error'] = self::message('preflight_probe_reason_sdk_missing');

            return $result;
        }

        try {
            $resolved = app('queue')->connection($connection);

            if (! $resolved instanceof SqsQueue) {
                $result['error'] = self::message('preflight_probe_reason_not_sqs');

                return $result;
            }

            // getQueue() resolves prefix/suffix and passes an already-complete
            // URL through untouched, so this is the exact URL the queue pushes to.
            $url = $resolved->getQueue($queue);
            $result['queue_url'] = $url;

            $attributes = self::readQueueAttributes($resolved->getSqs(), $url);

            if ($attributes === null) {
                $result['error'] = self::message('preflight_probe_reason_unsupported_client');

                return $result;
            }

            $visibility = $attributes['VisibilityTimeout'] ?? null;
            if (is_numeric($visibility)) {
                $result['visibility_timeout'] = (int) $visibility;
            }

            $redrive = $attributes['RedrivePolicy'] ?? null;
            if (is_string($redrive) && $redrive !== '') {
                $policy = json_decode($redrive, true);
                if (is_array($policy)) {
                    $maxReceiveCount = $policy['maxReceiveCount'] ?? null;
                    if (is_numeric($maxReceiveCount)) {
                        $result['max_receive_count'] = (int) $maxReceiveCount;
                    }

                    $target = $policy['deadLetterTargetArn'] ?? null;
                    if (is_string($target) && $target !== '') {
                        $result['dead_letter'] = $target;
                    }
                }
            }
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            $result['error'] = $message !== '' ? $message : get_class($e);
        }

        return $result;
    }

    /**
     * @return ProbeResult
     */
    public static function emptyProbe(): array
    {
        return [
            'visibility_timeout' => null,
            'max_receive_count' => null,
            'dead_letter' => null,
            'queue_url' => null,
            'error' => null,
        ];
    }

    /**
     * Run the seam and normalise whatever came back, so a fake returning a
     * partial array is as valid as the real probe.
     *
     * @return ProbeResult
     */
    private function probeQueueAttributes(?string $connection, ?string $queue): array
    {
        if (! (bool) config('elasticsearch.import.preflight.probe_queue', true)) {
            $result = self::emptyProbe();
            $result['error'] = $this->text('preflight_probe_reason_disabled');

            return $result;
        }

        $probe = $this->probe;

        try {
            $raw = $probe === null
                ? self::defaultProbe($connection, $queue)
                : $probe($connection, $queue);
        } catch (\Throwable $e) {
            $raw = null;
            $message = $e->getMessage();
            $result = self::emptyProbe();
            $result['error'] = $message !== '' ? $message : get_class($e);

            return $result;
        }

        return self::normalizeProbe($raw);
    }

    /**
     * @param  mixed  $raw
     * @return ProbeResult
     */
    private static function normalizeProbe($raw): array
    {
        $result = self::emptyProbe();

        if (! is_array($raw)) {
            $result['error'] = self::message('preflight_probe_reason_invalid');

            return $result;
        }

        $visibility = $raw['visibility_timeout'] ?? null;
        if (is_numeric($visibility)) {
            $result['visibility_timeout'] = (int) $visibility;
        }

        $maxReceiveCount = $raw['max_receive_count'] ?? null;
        if (is_numeric($maxReceiveCount)) {
            $result['max_receive_count'] = (int) $maxReceiveCount;
        }

        $deadLetter = $raw['dead_letter'] ?? null;
        if (is_string($deadLetter) && $deadLetter !== '') {
            $result['dead_letter'] = $deadLetter;
        }

        $url = $raw['queue_url'] ?? null;
        if (is_string($url) && $url !== '') {
            $result['queue_url'] = $url;
        }

        $error = $raw['error'] ?? null;
        if (is_string($error) && $error !== '') {
            $result['error'] = $error;
        }

        return $result;
    }

    /**
     * Pull `Attributes` off the SDK response without ever naming an Aws type in
     * a position that has to resolve when the SDK is absent.
     *
     * @param  mixed  $client
     * @return array<string, mixed>|null
     */
    private static function readQueueAttributes($client, string $url): ?array
    {
        // is_callable(), NOT method_exists(). An AWS SDK v3 client declares no
        // real method per API operation — every one of them is dispatched through
        // __call (the SDK only documents them as @method annotations). So
        // method_exists($client, 'getQueueAttributes') is FALSE on a perfectly
        // healthy SqsClient, which would make this probe report "unsupported
        // client" every single time and leave the one fact worth probing —
        // VisibilityTimeout — permanently unknown. is_callable() honours __call.
        $operation = [$client, 'getQueueAttributes'];

        if (! is_object($client) || ! is_callable($operation)) {
            return null;
        }

        $response = call_user_func($operation, [
            'QueueUrl' => $url,
            'AttributeNames' => ['VisibilityTimeout', 'RedrivePolicy'],
        ]);

        $attributes = null;
        if (is_array($response) || $response instanceof \ArrayAccess) {
            $attributes = $response['Attributes'] ?? null;
        }

        if (! is_array($attributes)) {
            return [];
        }

        /** @var array<string, mixed> $attributes */
        return $attributes;
    }

    private function driverFor(?string $connection): ?string
    {
        if ($connection === null) {
            return null;
        }

        $driver = config("queue.connections.{$connection}.driver");

        return is_string($driver) && $driver !== '' ? $driver : null;
    }

    /**
     * The job timeout this package stamps on its own jobs: null unless
     * SCOUT_QUEUE_TIMEOUT (elasticsearch.queue.timeout) is explicitly set, in
     * which case the job's own alarm governs.
     */
    private function jobTimeout(): ?int
    {
        $timeout = Config::queueTimeout();

        return is_int($timeout) && $timeout > 0 ? $timeout : null;
    }

    /**
     * `retry_after` for the connection. Correct comparand on
     * database/redis/beanstalkd; INERT on SQS (SqsConnector never reads it).
     */
    private function retryAfter(?string $connection): ?int
    {
        if ($connection === null) {
            return null;
        }

        $value = config("queue.connections.{$connection}.retry_after");

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * An operator-declared value under `import.preflight`. 0 means undeclared,
     * and undeclared is unknown — these three numbers cannot be discovered from
     * inside PHP.
     *
     * @return array{0: int, 1: string}
     */
    private function declaredSeconds(string $key): array
    {
        $value = config('elasticsearch.import.preflight.'.$key);
        $seconds = is_numeric($value) ? (int) $value : 0;

        return $seconds > 0
            ? [$seconds, self::PROVENANCE_DECLARED]
            : [0, self::PROVENANCE_UNKNOWN];
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function intConfigFact(string $key, int $default): array
    {
        $value = config($key);

        return is_numeric($value)
            ? [(int) $value, self::PROVENANCE_DECLARED]
            : [$default, self::PROVENANCE_ASSUMED];
    }

    /**
     * Chunk size for the run: the caller's resolved value when it knows one,
     * else the same config chain DefaultImportSource walks.
     *
     * @return array{0: int, 1: string}
     */
    private function chunkFact(?int $chunkSize): array
    {
        if ($chunkSize !== null && $chunkSize > 0) {
            return [$chunkSize, self::PROVENANCE_DECLARED];
        }

        foreach (['elasticsearch.import.chunk.default', 'scout.chunk.searchable'] as $key) {
            $value = config($key);
            if (is_numeric($value) && (int) $value > 0) {
                return [(int) $value, self::PROVENANCE_DECLARED];
            }
        }

        return [500, self::PROVENANCE_ASSUMED];
    }

    /**
     * @param  int|string|null  $raw
     * @param  string|null  $labelKey  Lang key override; defaults to `preflight_fact_{$key}`.
     * @return Fact
     */
    private function fact(string $key, $raw, string $provenance, ?string $display = null, ?string $labelKey = null): array
    {
        if ($display === null) {
            $display = $raw === null ? $this->text('preflight_value_unknown') : (string) $raw;
        }

        return [
            'key' => $key,
            'label' => $this->text($labelKey ?? 'preflight_fact_'.$key),
            'value' => $display,
            'raw' => $raw,
            'provenance' => $provenance,
            'provenance_label' => $this->provenanceLabel($provenance),
        ];
    }

    /**
     * @param  array<string, int|string>  $replace
     * @return Finding
     */
    private function finding(string $severity, string $code, array $replace = []): array
    {
        return [
            'severity' => $severity,
            'code' => $code,
            'message' => $this->text('preflight_'.$code, $replace),
        ];
    }

    private function provenanceLabel(string $provenance): string
    {
        $keys = [
            self::PROVENANCE_PROBED => 'preflight_provenance_probed',
            self::PROVENANCE_DECLARED => 'preflight_provenance_declared',
            self::PROVENANCE_ASSUMED => 'preflight_provenance_assumed',
            self::PROVENANCE_UNKNOWN => 'preflight_provenance_unknown',
        ];

        return $this->text($keys[$provenance] ?? 'preflight_provenance_unknown');
    }

    private function sourceLabel(string $source): string
    {
        $keys = [
            self::SOURCE_JOB_TIMEOUT => 'preflight_source_job_timeout',
            self::SOURCE_DECLARED_WORKER_TIMEOUT => 'preflight_source_declared_worker_timeout',
            self::SOURCE_ASSUMED_WORKER_TIMEOUT => 'preflight_source_assumed_worker_timeout',
        ];

        return $this->text($keys[$source] ?? 'preflight_source_assumed_worker_timeout');
    }

    private function seconds(int $value): string
    {
        return $value.'s';
    }

    /**
     * @param  array<string, int|string>  $replace
     */
    private function text(string $key, array $replace = []): string
    {
        return self::message($key, $replace);
    }

    /**
     * @param  array<string, int|string>  $replace
     */
    private static function message(string $key, array $replace = []): string
    {
        $message = trans('scout::import.'.$key, $replace);

        return is_string($message) ? $message : $key;
    }
}
