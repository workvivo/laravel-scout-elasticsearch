<?php

namespace Matchish\ScoutElasticSearch;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Per-model guard that stops two concurrent `scout:import` runs for the *same*
 * searchable from racing each other's index alias swap.
 *
 * This is a safety net, not a correctness requirement: keyset chunk bounds are
 * frozen into each job at dispatch time (see
 * {@see \Matchish\ScoutElasticSearch\Database\Scopes\ChunkScope}), so imports
 * for different models never share state and the many chunks *within* one import
 * are meant to run together. Only a second, separate invocation for the same
 * model is blocked.
 *
 * The lock is a **renewable lease**, not a fixed-duration hold: the running
 * import renews it as it makes progress (after each chunk), so the TTL is an
 * *inactivity* timeout rather than a cap on total import time. A multi-hour
 * import on a large table keeps the lease alive as long as chunks keep
 * completing; if the run crashes and stops renewing, the lease self-heals after
 * one idle TTL window. Requires a cache store with atomic `add` (redis,
 * memcached, database, dynamodb, or the array store used in tests) — the `file`
 * driver is unsuitable.
 */
final class ImportLock
{
    /**
     * @var string
     */
    private $searchableAs;
    /**
     * @var int
     */
    private $ttl;

    public function __construct(string $searchableAs, int $ttl)
    {
        $this->searchableAs = $searchableAs;
        $this->ttl = $ttl;
    }

    public static function keyFor(string $searchableAs): string
    {
        return 'scout:import:'.$searchableAs;
    }

    /**
     * Atomically claim the lease without blocking. Returns the owner token on
     * success (needed to renew/release it later, possibly from a queue worker in
     * another process) or null when another import already holds it.
     */
    public function acquire(): ?string
    {
        $owner = Str::random(40);

        // add() only writes when the key is absent, so two racing acquires
        // cannot both win.
        return Cache::add(self::keyFor($this->searchableAs), $owner, $this->ttl)
            ? $owner
            : null;
    }

    /**
     * Extend the lease for another TTL window, but only while we still own it —
     * a heartbeat the running import calls as it makes progress so long imports
     * do not expire mid-run.
     */
    public static function renew(string $searchableAs, string $owner, int $ttl): void
    {
        $key = self::keyFor($searchableAs);

        if (Cache::get($key) === $owner) {
            Cache::put($key, $owner, $ttl);
        }
    }

    /**
     * Release a lease previously acquired for the given searchable using its
     * owner token. Never frees a lease that has since expired and been
     * re-acquired by someone else, and is safe to call more than once.
     */
    public static function release(string $searchableAs, string $owner): void
    {
        $key = self::keyFor($searchableAs);

        if (Cache::get($key) === $owner) {
            Cache::forget($key);
        }
    }
}
