<?php

declare(strict_types=1);

namespace Matchish\ScoutElasticSearch\Import;

/**
 * Coordination state for a single parallel import run.
 *
 * @internal This contract exists for the package's own import coordination and
 * is not covered by the package's backward-compatibility promise. Methods are
 * added here whenever the chunk protocol needs new state.
 */
interface ImportRunStore
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_FINALIZING = 'finalizing';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_FINALIZE_FAILED = 'finalize_failed';

    public function start(string $token, int $total, string $index): void;

    public function markDone(string $token, int $chunkId): int;

    public function isDone(string $token, int $chunkId): bool;

    public function total(string $token): int;

    public function status(string $token): ?string;

    public function snapshot(string $token): array;

    public function refreshTtls(string $token, int $ttl): void;

    /**
     * @deprecated Kept for backward compatibility. A chunk failure no longer
     * fails the whole run on its own: classify the failure with
     * chunkInFlight()/isDone(), then use recordFailure() to consume a slot of
     * the failure budget and failRun() to move the run to failed once the
     * budget is exhausted.
     */
    public function failIfNotDone(string $token, int $chunkId): bool;

    /**
     * Take the execution lease for a chunk.
     *
     * The owner token identifies one *execution* of the chunk job (not the
     * chunk, and not the job payload), so a live lease is proof that some other
     * attempt is either running right now or was killed mid-flight less than
     * $ttlSeconds ago. Re-entrant: passing the owner that already holds the
     * lease succeeds and extends it.
     */
    public function claimChunk(string $token, int $chunkId, string $owner, int $ttlSeconds): bool;

    /**
     * Release the chunk lease, but only if it is still held by $owner — a lease
     * that already expired and was re-taken by a later attempt must not be
     * deleted by the attempt that lost it.
     */
    public function releaseChunk(string $token, int $chunkId, string $owner): void;

    public function chunkInFlight(string $token, int $chunkId): bool;

    /**
     * Record a genuine (non-duplicate) chunk failure and return how many
     * distinct chunks have now failed. Idempotent per chunk, so the same chunk
     * failing repeatedly consumes a single slot of the failure budget, and
     * returns 0 for a chunk that already completed.
     */
    public function recordFailure(string $token, int $chunkId): int;

    public function failureCount(string $token): int;

    /**
     * Move a running run to failed. Returns false when the run already left the
     * running state, so only one caller ever triggers the rollback.
     */
    public function failRun(string $token): bool;

    /**
     * Count one re-dispatch of a chunk and return the new total, so an
     * ambiguous failure cannot re-dispatch the same chunk forever.
     */
    public function bumpRedispatch(string $token, int $chunkId): int;

    /**
     * Park planned chunk boundaries for a paged fan-out.
     *
     * @param  array<int, array{0:int, 1:mixed, 2:mixed}>  $bounds  [chunkId, start, end] triples
     */
    public function pushBounds(string $token, array $bounds): void;

    /**
     * Atomically take up to $count parked boundary triples.
     *
     * @return array<int, array{0:int, 1:mixed, 2:mixed}>
     */
    public function popBounds(string $token, int $count): array;

    public function pendingBounds(string $token): int;

    /**
     * Publish one profiling diagnosis into the run record, deduped by $code.
     *
     * The run record is the only channel a queue worker has back to the terminal
     * that started the import, so profiling findings travel through here rather
     * than through the log. Findings are aggregated, never appended: a run that
     * profiles 100k chunks and hits the same n+1 every time collapses to a
     * single row carrying the occurrence count and the worst example seen.
     *
     * $cap bounds the number of DISTINCT codes, not the number of calls. A code
     * that is already being tracked is always still counted, so the cap can
     * never silence a finding that is already being reported.
     *
     * @param  string  $payload  JSON-encoded data of this occurrence, kept only when it is the worst so far
     * @return bool false when the cap rejected a new code, true when the finding was recorded
     */
    public function recordProfileFinding(string $token, string $code, float $weight, string $payload, int $ttlSeconds, int $cap): bool;

    /**
     * Every published finding of a run, keyed by code and sorted by code.
     *
     * @return array<string, array{count:int, weight:float, data:array<string,scalar>}>
     */
    public function profileFindings(string $token): array;

    public function claimFinalization(string $token): bool;

    public function succeedIfFinalizing(string $token, string $lockOwner): bool;

    public function finalizeFailedIfFinalizing(string $token, string $lockOwner): bool;

    public function acquireFinalizeLock(string $token, string $owner, int $ttlSeconds): bool;

    public function renewFinalizeLock(string $token, string $owner, int $ttlSeconds): bool;

    public function releaseFinalizeLock(string $token, string $owner): void;

    public function supportsAtomicCoordination(): bool;
}
