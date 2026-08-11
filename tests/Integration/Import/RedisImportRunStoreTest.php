<?php

declare(strict_types=1);

namespace Tests\Integration\Import;

use Matchish\ScoutElasticSearch\Import\ImportRunStore;
use Matchish\ScoutElasticSearch\Import\RedisImportRunStore;
use Orchestra\Testbench\TestCase;
use Predis\Cluster\RedisStrategy;
use ReflectionMethod;

final class RedisImportRunStoreTest extends TestCase
{
    private RedisImportRunStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new RedisImportRunStore($this->app['redis']);

        try {
            if (! $this->store->supportsAtomicCoordination()) {
                $this->markTestSkipped('Redis is not available for coordinator integration tests.');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis is not available for coordinator integration tests.');
        }
    }

    /**
     * @test
     */
    public function lua_transitions_are_guarded_and_lock_owner_aware(): void
    {
        $token = 'test-run-'.bin2hex(random_bytes(8));

        $this->store->start($token, 2, 'products_1');
        $this->store->markDone($token, 0);

        $this->assertFalse($this->store->claimFinalization($token));
        $this->assertFalse($this->store->failIfNotDone($token, 0));
        $this->assertTrue($this->store->failIfNotDone($token, 1));
        $this->assertSame(ImportRunStore::STATUS_FAILED, $this->store->status($token));
    }

    /**
     * @test
     */
    public function duplicate_chunk_completion_is_idempotent(): void
    {
        $token = 'test-run-'.bin2hex(random_bytes(8));

        $this->store->start($token, 2, 'products_1');

        $this->assertSame(1, $this->store->markDone($token, 0));
        $this->assertSame(1, $this->store->markDone($token, 0));
        $this->assertSame(1, $this->store->snapshot($token)['done']);

        $this->assertSame(2, $this->store->markDone($token, 1));
    }

    /**
     * @test
     */
    public function keys_use_a_hash_tag_so_multi_key_lua_is_redis_cluster_safe(): void
    {
        $key = new ReflectionMethod(RedisImportRunStore::class, 'key');
        $key->setAccessible(true);

        $chunkKey = new ReflectionMethod(RedisImportRunStore::class, 'chunkKey');
        $chunkKey->setAccessible(true);

        $keys = [
            $key->invoke($this->store, 'run-token', 'status'),
            $key->invoke($this->store, 'run-token', 'done'),
            $key->invoke($this->store, 'run-token', 'total'),
            $key->invoke($this->store, 'run-token', 'index'),
            $key->invoke($this->store, 'run-token', 'finalize'),
            $key->invoke($this->store, 'run-token', 'failed'),
            $key->invoke($this->store, 'run-token', 'bounds'),
            $chunkKey->invoke($this->store, 'run-token', 7, 'lease'),
            $chunkKey->invoke($this->store, 'run-token', 7, 'redispatch'),
        ];

        $this->assertSame([
            'scout:import:run:{run-token}:status',
            'scout:import:run:{run-token}:done',
            'scout:import:run:{run-token}:total',
            'scout:import:run:{run-token}:index',
            'scout:import:run:{run-token}:finalize',
            'scout:import:run:{run-token}:failed',
            'scout:import:run:{run-token}:bounds',
            'scout:import:run:{run-token}:chunk:7:lease',
            'scout:import:run:{run-token}:chunk:7:redispatch',
        ], $keys);

        $strategy = new RedisStrategy();
        $slots = array_unique(array_map(function (string $key) use ($strategy) {
            return $strategy->getSlotByKey($key);
        }, $keys));

        $this->assertCount(1, $slots);
    }

    /**
     * @test
     */
    public function supports_atomic_coordination_runs_a_multi_key_probe(): void
    {
        $this->assertTrue($this->store->supportsAtomicCoordination());
    }

    /**
     * @test
     */
    public function finalization_resumes_from_finalizing_and_rejects_stale_terminal_writes(): void
    {
        $token = 'test-run-'.bin2hex(random_bytes(8));

        $this->store->start($token, 0, 'products_1');
        $this->assertTrue($this->store->acquireFinalizeLock($token, 'owner-a', 60));
        $this->assertTrue($this->store->claimFinalization($token));
        $this->store->releaseFinalizeLock($token, 'owner-a');

        $this->assertTrue($this->store->acquireFinalizeLock($token, 'owner-b', 60));
        $this->assertTrue($this->store->claimFinalization($token));
        $this->assertFalse($this->store->finalizeFailedIfFinalizing($token, 'owner-a'));
        $this->assertTrue($this->store->succeedIfFinalizing($token, 'owner-b'));
        $this->assertSame(ImportRunStore::STATUS_SUCCEEDED, $this->store->status($token));
    }

    /**
     * @test
     */
    public function chunk_leases_are_exclusive_re_entrant_and_owner_guarded_on_release(): void
    {
        $token = 'test-run-'.bin2hex(random_bytes(8));

        $this->store->start($token, 3, 'products_1');

        $this->assertFalse($this->store->chunkInFlight($token, 1));
        $this->assertTrue($this->store->claimChunk($token, 1, 'owner-a', 60));
        $this->assertTrue($this->store->chunkInFlight($token, 1));

        // The duplicate delivery that the whole design exists for.
        $this->assertFalse($this->store->claimChunk($token, 1, 'owner-b', 60));
        // Re-entrant for the holder, and a lease is per chunk.
        $this->assertTrue($this->store->claimChunk($token, 1, 'owner-a', 60));
        $this->assertTrue($this->store->claimChunk($token, 2, 'owner-b', 60));

        // A late release from the attempt that lost the lease must not evict the
        // holder — otherwise a duplicate could cancel a live attempt.
        $this->store->releaseChunk($token, 1, 'owner-b');
        $this->assertTrue($this->store->chunkInFlight($token, 1));

        $this->store->releaseChunk($token, 1, 'owner-a');
        $this->assertFalse($this->store->chunkInFlight($token, 1));
        $this->assertTrue($this->store->chunkInFlight($token, 2));
    }

    /**
     * @test
     */
    public function a_chunk_lease_expires_on_its_own_so_a_hard_killed_worker_frees_the_chunk(): void
    {
        $token = 'test-run-'.bin2hex(random_bytes(8));

        $this->store->start($token, 1, 'products_1');

        // TTL is floored at 1s, so this is the shortest observable lease. A
        // SIGKILLed worker never runs its release, and this is what lets the
        // re-dispatched copy eventually claim the chunk.
        $this->assertTrue($this->store->claimChunk($token, 0, 'killed-owner', 1));
        $this->assertTrue($this->store->chunkInFlight($token, 0));

        sleep(2);

        $this->assertFalse($this->store->chunkInFlight($token, 0));
        $this->assertTrue($this->store->claimChunk($token, 0, 'replacement-owner', 60));
    }

    /**
     * @test
     */
    public function failures_are_budgeted_per_chunk_and_only_fail_a_running_run(): void
    {
        $token = 'test-run-'.bin2hex(random_bytes(8));

        $this->store->start($token, 4, 'products_1');
        $this->store->markDone($token, 3);

        $this->assertSame(0, $this->store->failureCount($token));

        // Idempotent per chunk: a chunk that keeps being re-delivered must not
        // eat the whole budget on its own.
        $this->assertSame(1, $this->store->recordFailure($token, 0));
        $this->assertSame(1, $this->store->recordFailure($token, 0));
        $this->assertSame(2, $this->store->recordFailure($token, 1));
        $this->assertSame(2, $this->store->failureCount($token));

        // A completed chunk failing afterwards costs nothing.
        $this->assertSame(0, $this->store->recordFailure($token, 3));
        $this->assertSame(2, $this->store->failureCount($token));

        // Exactly one caller gets to trigger the rollback.
        $this->assertTrue($this->store->failRun($token));
        $this->assertFalse($this->store->failRun($token));
        $this->assertSame(ImportRunStore::STATUS_FAILED, $this->store->status($token));
    }

    /**
     * @test
     */
    public function redispatch_counters_are_per_chunk_and_expire_with_the_run(): void
    {
        $token = 'test-run-'.bin2hex(random_bytes(8));

        $this->store->start($token, 2, 'products_1');

        $this->assertSame(1, $this->store->bumpRedispatch($token, 0));
        $this->assertSame(2, $this->store->bumpRedispatch($token, 0));
        $this->assertSame(1, $this->store->bumpRedispatch($token, 1));

        // refreshTtls() cannot reach per-chunk keys (it does not know which chunk
        // ids exist), so the first increment has to set the TTL itself.
        $ttl = (int) $this->app['redis']->connection()->ttl('scout:import:run:{'.$token.'}:chunk:0:redispatch');
        $this->assertGreaterThan(0, $ttl);
    }

    /**
     * @test
     */
    public function parked_bounds_pop_in_fifo_order_and_drain_atomically(): void
    {
        $token = 'test-run-'.bin2hex(random_bytes(8));

        $this->store->start($token, 5, 'products_1');
        $this->assertSame(0, $this->store->pendingBounds($token));

        $this->store->pushBounds($token, [[0, null, 10], [1, 10, 20], [2, 20, 30]]);
        $this->store->pushBounds($token, [[3, 30, 40], [4, 40, 50]]);
        $this->assertSame(5, $this->store->pendingBounds($token));

        $this->assertSame([[0, null, 10], [1, 10, 20]], $this->store->popBounds($token, 2));
        $this->assertSame(3, $this->store->pendingBounds($token));

        // A short last page, then a clean empty drain.
        $this->assertSame([[2, 20, 30], [3, 30, 40], [4, 40, 50]], $this->store->popBounds($token, 10));
        $this->assertSame(0, $this->store->pendingBounds($token));
        $this->assertSame([], $this->store->popBounds($token, 10));

        // Non-positive counts take nothing rather than draining the list.
        $this->store->pushBounds($token, [[5, 50, 60]]);
        $this->assertSame([], $this->store->popBounds($token, 0));
        $this->assertSame(1, $this->store->pendingBounds($token));
    }

    /**
     * @test
     */
    public function string_bounds_survive_the_json_round_trip(): void
    {
        $token = 'test-run-'.bin2hex(random_bytes(8));

        $this->store->start($token, 1, 'products_1');
        $this->store->pushBounds($token, [[0, '018f-aaaa', '018f-bbbb']]);

        $this->assertSame([[0, '018f-aaaa', '018f-bbbb']], $this->store->popBounds($token, 1));
    }

    /**
     * @test
     */
    public function refresh_ttls_covers_the_new_keys_but_never_stretches_the_finalize_lock(): void
    {
        $token = 'test-run-'.bin2hex(random_bytes(8));
        $redis = $this->app['redis']->connection();

        $this->store->start($token, 2, 'products_1');
        $this->store->markDone($token, 0);
        // Chunk 1, not 0: recordFailure() declines for a chunk that already
        // completed, and would leave the `failed` key non-existent.
        $this->store->recordFailure($token, 1);
        $this->store->pushBounds($token, [[0, null, 10]]);
        $this->assertTrue($this->store->acquireFinalizeLock($token, 'owner-a', 30));

        $this->store->refreshTtls($token, 3600);

        foreach (['status', 'total', 'index', 'done', 'failed', 'bounds'] as $suffix) {
            $this->assertGreaterThan(3000, (int) $redis->ttl('scout:import:run:{'.$token.'}:'.$suffix), $suffix);
        }

        // The finalize key is a lock with its own short TTL. Stretching it to the
        // run TTL would let a straggler chunk keep an orphaned finalize lock alive
        // for the rest of the run, blocking every later attempt to finalize.
        $this->assertLessThanOrEqual(30, (int) $redis->ttl('scout:import:run:{'.$token.'}:finalize'));
    }
}
