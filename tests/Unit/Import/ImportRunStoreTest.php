<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use Closure;
use Illuminate\Redis\Connections\Connection;
use LogicException;
use Matchish\ScoutElasticSearch\Import\ImportRunStore;
use Matchish\ScoutElasticSearch\Import\RedisImportRunStore;
use ReflectionClass;
use ReflectionProperty;
use Tests\Fakes\FakeImportRunStore;
use PHPUnit\Framework\TestCase;

final class ImportRunStoreTest extends TestCase
{
    private FakeImportRunStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new FakeImportRunStore();
    }

    /**
     * @test
     */
    public function start_is_idempotent_and_does_not_clear_done_or_rewind_status(): void
    {
        $this->store->start('run', 2, 'products_1');
        $this->store->markDone('run', 0);
        $this->store->markDone('run', 1);
        $this->assertTrue($this->store->claimFinalization('run'));

        $this->store->start('run', 2, 'products_1');

        $this->assertSame(ImportRunStore::STATUS_FINALIZING, $this->store->status('run'));
        $this->assertTrue($this->store->isDone('run', 0));
        $this->assertSame(2, $this->store->snapshot('run')['done']);
    }

    /**
     * @test
     */
    public function start_rejects_total_and_index_mismatches(): void
    {
        $this->store->start('run', 2, 'products_1');

        try {
            $this->store->start('run', 3, 'products_1');
            $this->fail('Expected total mismatch');
        } catch (LogicException $e) {
            $this->assertStringContainsString('total mismatch', $e->getMessage());
        }

        try {
            $this->store->start('run', 2, 'products_2');
            $this->fail('Expected index mismatch');
        } catch (LogicException $e) {
            $this->assertStringContainsString('index mismatch', $e->getMessage());
        }
    }

    /**
     * @test
     */
    public function failure_does_not_win_after_the_same_chunk_is_done(): void
    {
        $this->store->start('run', 1, 'products_1');
        $this->store->markDone('run', 0);

        $this->assertFalse($this->store->failIfNotDone('run', 0));
        $this->assertSame(ImportRunStore::STATUS_RUNNING, $this->store->status('run'));
    }

    /**
     * @test
     */
    public function claim_finalization_requires_completeness_and_resumes_finalizing(): void
    {
        $this->store->start('run', 2, 'products_1');
        $this->store->markDone('run', 0);

        $this->assertFalse($this->store->claimFinalization('run'));
        $this->store->markDone('run', 1);
        $this->assertTrue($this->store->claimFinalization('run'));
        $this->assertTrue($this->store->claimFinalization('run'));
    }

    /**
     * @test
     */
    public function terminal_transitions_require_the_finalize_lock_owner(): void
    {
        $this->store->start('run', 0, 'products_1');
        $this->assertTrue($this->store->acquireFinalizeLock('run', 'owner-a', 60));
        $this->assertTrue($this->store->claimFinalization('run'));

        $this->assertFalse($this->store->finalizeFailedIfFinalizing('run', 'owner-b'));
        $this->assertSame(ImportRunStore::STATUS_FINALIZING, $this->store->status('run'));

        $this->assertTrue($this->store->succeedIfFinalizing('run', 'owner-a'));
        $this->assertSame(ImportRunStore::STATUS_SUCCEEDED, $this->store->status('run'));
    }

    /**
     * @test
     */
    public function claim_chunk_is_exclusive_and_re_entrant_for_the_same_owner(): void
    {
        $this->store->start('run', 2, 'products_1');

        $this->assertFalse($this->store->chunkInFlight('run', 0));
        $this->assertTrue($this->store->claimChunk('run', 0, 'owner-a', 60));
        $this->assertTrue($this->store->chunkInFlight('run', 0));

        // A second execution of the same chunk must lose: a live lease is the
        // proof that lets failed() classify a redelivery as ambiguous rather
        // than as a genuine failure.
        $this->assertFalse($this->store->claimChunk('run', 0, 'owner-b', 60));

        // Re-entrant for the holder, so a retry inside one execution cannot
        // deadlock against its own lease.
        $this->assertTrue($this->store->claimChunk('run', 0, 'owner-a', 60));

        // Leases are per chunk, not per run.
        $this->assertTrue($this->store->claimChunk('run', 1, 'owner-b', 60));
        $this->assertTrue($this->store->chunkInFlight('run', 1));
    }

    /**
     * @test
     */
    public function an_expired_chunk_lease_is_claimable_by_a_later_attempt(): void
    {
        $this->store->start('run', 1, 'products_1');
        $this->assertTrue($this->store->claimChunk('run', 0, 'owner-a', 60));

        // A worker killed mid-flight (SIGALRM / OOM) never runs its finally, so
        // the lease only goes away when its TTL elapses.
        $this->store->expireChunkLease('run', 0);

        $this->assertFalse($this->store->chunkInFlight('run', 0));
        $this->assertTrue($this->store->claimChunk('run', 0, 'owner-b', 60));
    }

    /**
     * @test
     */
    public function release_chunk_only_releases_its_own_lease(): void
    {
        $this->store->start('run', 1, 'products_1');
        $this->assertTrue($this->store->claimChunk('run', 0, 'owner-a', 60));

        $this->store->releaseChunk('run', 0, 'owner-b');
        $this->assertTrue($this->store->chunkInFlight('run', 0));

        $this->store->releaseChunk('run', 0, 'owner-a');
        $this->assertFalse($this->store->chunkInFlight('run', 0));
    }

    /**
     * @test
     */
    public function releasing_an_expired_lease_does_not_evict_its_successor(): void
    {
        $this->store->start('run', 1, 'products_1');
        $this->assertTrue($this->store->claimChunk('run', 0, 'owner-a', 60));
        $this->store->expireChunkLease('run', 0);
        $this->assertTrue($this->store->claimChunk('run', 0, 'owner-b', 60));

        // owner-a comes back to life and releases: it lost the lease long ago
        // and must not cancel the attempt that is actually running.
        $this->store->releaseChunk('run', 0, 'owner-a');

        $this->assertTrue($this->store->chunkInFlight('run', 0));
    }

    /**
     * @test
     */
    public function record_failure_is_idempotent_per_chunk_and_ignores_done_chunks(): void
    {
        $this->store->start('run', 3, 'products_1');

        $this->assertSame(1, $this->store->recordFailure('run', 0));
        // Same chunk failing again spends no further budget.
        $this->assertSame(1, $this->store->recordFailure('run', 0));
        $this->assertSame(1, $this->store->failureCount('run'));

        $this->assertSame(2, $this->store->recordFailure('run', 1));
        $this->assertSame(2, $this->store->failureCount('run'));

        // A chunk that completed is never a failure, however often a duplicate
        // delivery of it lands in failed().
        $this->store->markDone('run', 2);
        $this->assertSame(0, $this->store->recordFailure('run', 2));
        $this->assertSame(2, $this->store->failureCount('run'));
    }

    /**
     * @test
     */
    public function failure_count_starts_at_zero_for_an_unknown_run(): void
    {
        $this->assertSame(0, $this->store->failureCount('never-started'));

        $this->store->start('run', 1, 'products_1');
        $this->assertSame(0, $this->store->failureCount('run'));
    }

    /**
     * @test
     */
    public function fail_run_only_fires_from_running(): void
    {
        $this->store->start('run', 1, 'products_1');

        $this->assertTrue($this->store->failRun('run'));
        $this->assertSame(ImportRunStore::STATUS_FAILED, $this->store->status('run'));

        // Only one caller ever triggers the rollback.
        $this->assertFalse($this->store->failRun('run'));
        $this->assertSame(ImportRunStore::STATUS_FAILED, $this->store->status('run'));
    }

    /**
     * @test
     */
    public function fail_run_does_not_reopen_a_finalizing_or_unknown_run(): void
    {
        $this->assertFalse($this->store->failRun('never-started'));

        $this->store->start('run', 1, 'products_1');
        $this->store->markDone('run', 0);
        $this->assertTrue($this->store->claimFinalization('run'));

        $this->assertFalse($this->store->failRun('run'));
        $this->assertSame(ImportRunStore::STATUS_FINALIZING, $this->store->status('run'));
    }

    /**
     * @test
     */
    public function bump_redispatch_increments_per_chunk(): void
    {
        $this->store->start('run', 2, 'products_1');

        $this->assertSame(1, $this->store->bumpRedispatch('run', 0));
        $this->assertSame(2, $this->store->bumpRedispatch('run', 0));
        $this->assertSame(3, $this->store->bumpRedispatch('run', 0));

        // Counters are per chunk, so one pathological chunk cannot exhaust
        // another chunk's re-dispatch budget.
        $this->assertSame(1, $this->store->bumpRedispatch('run', 1));
        $this->assertSame(1, $this->store->bumpRedispatch('other-run', 0));
    }

    /**
     * @test
     */
    public function bounds_are_popped_in_fifo_order_and_drain_cleanly(): void
    {
        $this->store->start('run', 5, 'products_1');
        $this->assertSame(0, $this->store->pendingBounds('run'));

        $this->store->pushBounds('run', [
            [0, null, 100],
            [1, 100, 200],
            [2, 200, 300],
        ]);
        $this->store->pushBounds('run', [
            [3, 300, 400],
            [4, 400, 500],
        ]);

        $this->assertSame(5, $this->store->pendingBounds('run'));

        $this->assertSame([[0, null, 100], [1, 100, 200]], $this->store->popBounds('run', 2));
        $this->assertSame(3, $this->store->pendingBounds('run'));

        $this->assertSame([[2, 200, 300], [3, 300, 400]], $this->store->popBounds('run', 2));

        // The last page comes back short rather than padded, and the list is
        // then empty for every later hop.
        $this->assertSame([[4, 400, 500]], $this->store->popBounds('run', 2));
        $this->assertSame(0, $this->store->pendingBounds('run'));
        $this->assertSame([], $this->store->popBounds('run', 2));
    }

    /**
     * @test
     */
    public function popping_a_non_positive_count_takes_nothing(): void
    {
        $this->store->start('run', 1, 'products_1');
        $this->store->pushBounds('run', [[0, null, 100]]);

        $this->assertSame([], $this->store->popBounds('run', 0));
        $this->assertSame([], $this->store->popBounds('run', -1));
        $this->assertSame(1, $this->store->pendingBounds('run'));
    }

    /**
     * @test
     */
    public function bounds_survive_the_json_round_trip_with_an_int_chunk_id(): void
    {
        $this->store->start('run', 2, 'products_1');

        // Non-numeric keys (uuid/string primary keys) are parked verbatim; only
        // the chunk id is guaranteed to come back typed as an int.
        $this->store->pushBounds('run', [
            [0, null, '0f8c-aaaa'],
            [1, '0f8c-aaaa', '0f8c-bbbb'],
        ]);

        $this->assertSame([
            [0, null, '0f8c-aaaa'],
            [1, '0f8c-aaaa', '0f8c-bbbb'],
        ], $this->store->popBounds('run', 10));
    }

    /**
     * @test
     */
    public function bounds_are_empty_for_a_run_that_never_parked_any(): void
    {
        $this->store->start('run', 1, 'products_1');
        $this->store->pushBounds('run', []);

        $this->assertSame(0, $this->store->pendingBounds('run'));
        $this->assertSame([], $this->store->popBounds('run', 10));
        $this->assertSame(0, $this->store->pendingBounds('never-started'));
        $this->assertSame([], $this->store->popBounds('never-started', 10));
    }

    /**
     * @test
     */
    public function profile_findings_dedupe_by_code_and_keep_the_worst_example(): void
    {
        $this->store->start('run', 3, 'products_1');

        // Three chunks trip the same rule. The whole point of the counts hash is
        // that this stays ONE row: a run that profiles 100k chunks and hits the
        // same n+1 every time must not grow 100k entries.
        $this->assertTrue($this->store->recordProfileFinding('run', 'n_plus_one', 12.0, (string) json_encode([
            'relation' => 'App\Models\Order::items', 'loads' => 12, 'relations' => 1,
        ]), 3600, 20));
        $this->assertTrue($this->store->recordProfileFinding('run', 'n_plus_one', 480.0, (string) json_encode([
            'relation' => 'App\Models\Order::user', 'loads' => 480, 'relations' => 2,
        ]), 3600, 20));
        // A milder occurrence AFTER the worst one: it still counts, but it must
        // not overwrite the example an operator is shown.
        $this->assertTrue($this->store->recordProfileFinding('run', 'n_plus_one', 7.0, (string) json_encode([
            'relation' => 'App\Models\Order::tags', 'loads' => 7, 'relations' => 1,
        ]), 3600, 20));

        $findings = $this->store->profileFindings('run');

        $this->assertCount(1, $findings);
        $this->assertSame([
            'count' => 3,
            'weight' => 480.0,
            'data' => ['relation' => 'App\Models\Order::user', 'loads' => 480, 'relations' => 2],
        ], $findings['n_plus_one']);
    }

    /**
     * @test
     */
    public function a_tie_on_weight_keeps_the_example_that_got_there_first(): void
    {
        $this->store->start('run', 2, 'products_1');

        $this->store->recordProfileFinding('run', 'fetch_dominant', 900.0, '{"fetch_ms":900}', 3600, 20);
        $this->store->recordProfileFinding('run', 'fetch_dominant', 900.0, '{"fetch_ms":901}', 3600, 20);

        // Worst-wins is strict, so equal weights do not churn the stored payload
        // — with thousands of chunks that would be a write per chunk for no
        // change in what is reported.
        $this->assertSame(['fetch_ms' => 900], $this->store->profileFindings('run')['fetch_dominant']['data']);
        $this->assertSame(2, $this->store->profileFindings('run')['fetch_dominant']['count']);
    }

    /**
     * @test
     */
    public function the_profile_cap_bounds_distinct_codes_and_never_stops_counting_a_known_one(): void
    {
        $this->store->start('run', 1, 'products_1');

        $this->assertTrue($this->store->recordProfileFinding('run', 'n_plus_one', 1.0, '{}', 3600, 2));
        $this->assertTrue($this->store->recordProfileFinding('run', 'filter_queries', 1.0, '{}', 3600, 2));

        // The cap is on DISTINCT codes, so the third code is turned away.
        $this->assertFalse($this->store->recordProfileFinding('run', 'large_payload', 1.0, '{}', 3600, 2));

        // A code that is already tracked is always still counted, even with the
        // hash full: the cap must never make a finding it is already reporting
        // look rarer than it is.
        $this->assertTrue($this->store->recordProfileFinding('run', 'n_plus_one', 5.0, '{"loads":5}', 3600, 2));

        $findings = $this->store->profileFindings('run');

        $this->assertSame(['filter_queries', 'n_plus_one'], array_keys($findings));
        $this->assertSame(2, $findings['n_plus_one']['count']);
        $this->assertSame(5.0, $findings['n_plus_one']['weight']);
        $this->assertSame(['loads' => 5], $findings['n_plus_one']['data']);
    }

    /**
     * @test
     */
    public function a_cap_below_one_records_nothing_at_all(): void
    {
        $this->store->start('run', 1, 'products_1');

        // SCOUT_IMPORT_PROFILE_FINDINGS=0 disables publication outright: nothing
        // is stored, so nothing can be read back.
        $this->assertFalse($this->store->recordProfileFinding('run', 'n_plus_one', 1.0, '{}', 3600, 0));
        $this->assertFalse($this->store->recordProfileFinding('run', 'n_plus_one', 1.0, '{}', 3600, -1));

        $this->assertSame([], $this->store->profileFindings('run'));
    }

    /**
     * @test
     */
    public function profile_findings_are_empty_until_something_is_published(): void
    {
        $this->assertSame([], $this->store->profileFindings('never-started'));

        $this->store->start('run', 1, 'products_1');
        $this->assertSame([], $this->store->profileFindings('run'));
    }

    /**
     * @test
     */
    public function profile_findings_are_keyed_by_code_sorted_and_carry_scalar_data_only(): void
    {
        $this->store->start('run', 3, 'products_1');

        $this->store->recordProfileFinding('run', 'n_plus_one', 480.0, (string) json_encode([
            'relation' => 'App\Models\Order::user',
            'loads' => 480,
            // A nested value cannot survive: the payload was encoded by a queue
            // worker, possibly on an older release, so the reader keeps scalars
            // and drops everything else instead of trusting the shape.
            'nested' => ['x' => 1],
        ]), 3600, 20);
        $this->store->recordProfileFinding('run', 'chunk_near_timeout', 41230.0, '{"total_ms":41230,"timeout":60,"pct":68.7}', 3600, 20);
        // Not JSON at all — the reader degrades to no data rather than throwing.
        $this->store->recordProfileFinding('run', 'large_payload', 200.0, 'truncated{', 3600, 20);

        $findings = $this->store->profileFindings('run');

        // Sorted by code, so the terminal renders findings in its own priority
        // order without depending on Redis hash iteration order.
        $this->assertSame(['chunk_near_timeout', 'large_payload', 'n_plus_one'], array_keys($findings));

        foreach ($findings as $code => $finding) {
            $this->assertSame(['count', 'weight', 'data'], array_keys($finding), $code);
            $this->assertIsInt($finding['count'], $code);
            $this->assertIsFloat($finding['weight'], $code);
        }

        $this->assertSame(
            ['relation' => 'App\Models\Order::user', 'loads' => 480],
            $findings['n_plus_one']['data']
        );
        $this->assertSame(['total_ms' => 41230, 'timeout' => 60, 'pct' => 68.7], $findings['chunk_near_timeout']['data']);
        $this->assertSame([], $findings['large_payload']['data']);
        $this->assertSame(200.0, $findings['large_payload']['weight']);
    }

    /**
     * @test
     */
    public function a_weight_at_or_below_the_sentinel_is_counted_without_an_example(): void
    {
        $this->store->start('run', 2, 'products_1');

        // -1 is the "nothing recorded yet" sentinel the Lua compares against, so
        // a weight that cannot beat it leaves the example unset. The occurrence
        // is still counted, and the weight reads back as 0.0 in both stores —
        // pinned here because the two implementations have to agree on it.
        $this->assertTrue($this->store->recordProfileFinding('run', 'weightless', -1.0, '{"a":1}', 3600, 20));
        $this->assertTrue($this->store->recordProfileFinding('run', 'weightless', -5.0, '{"a":2}', 3600, 20));

        $this->assertSame(
            ['count' => 2, 'weight' => 0.0, 'data' => []],
            $this->store->profileFindings('run')['weightless']
        );

        // A weight of exactly zero does beat the sentinel, so its example sticks.
        $this->assertTrue($this->store->recordProfileFinding('run', 'zeroed', 0.0, '{"a":3}', 3600, 20));
        $this->assertSame(
            ['count' => 1, 'weight' => 0.0, 'data' => ['a' => 3]],
            $this->store->profileFindings('run')['zeroed']
        );
    }

    /**
     * @test
     */
    public function profile_findings_are_scoped_to_their_run(): void
    {
        $this->store->start('run-a', 1, 'products_1');
        $this->store->start('run-b', 1, 'products_1');

        $this->store->recordProfileFinding('run-a', 'n_plus_one', 480.0, '{"loads":480}', 3600, 20);

        $this->assertSame(['n_plus_one'], array_keys($this->store->profileFindings('run-a')));
        // A multi-model import runs one token per searchable, and the terminal
        // reports each separately.
        $this->assertSame([], $this->store->profileFindings('run-b'));
    }

    /**
     * @test
     */
    public function the_redis_store_keeps_the_profile_keys_inside_the_run_and_refreshes_them(): void
    {
        // The only test here that leaves FakeImportRunStore behind, because the
        // fake's refreshTtls() is a no-op and its keys are array indexes: the
        // suffix list and the {token} hash tag exist only in the Redis store.
        // Driven through a recording Connection rather than a server, so what is
        // asserted is the command stream, not Redis behaviour (that belongs to
        // tests/Integration/Import).
        $connection = new class extends Connection
        {
            /** @var list<array<int, mixed>> */
            public array $calls = [];

            public function createSubscription($channels, Closure $callback, $method = 'subscribe')
            {
                //
            }

            public function command($method, array $parameters = [])
            {
                $this->calls[] = array_merge([$method], $parameters);

                return 1;
            }
        };

        // The constructor resolves a connection out of the container; the store
        // itself has no other state, so bypassing it keeps this a unit test.
        /** @var RedisImportRunStore $store */
        $store = (new ReflectionClass(RedisImportRunStore::class))->newInstanceWithoutConstructor();
        $property = new ReflectionProperty(RedisImportRunStore::class, 'connection');
        $property->setAccessible(true);
        $property->setValue($store, $connection);

        // A cap of 0 must cost the import literally nothing: no round trip at
        // all, not just no write.
        $this->assertFalse($store->recordProfileFinding('TOK', 'n_plus_one', 1.0, '{}', 3600, 0));
        $this->assertSame([], $connection->calls);

        $this->assertTrue($store->recordProfileFinding('TOK', 'n_plus_one', 1.0, '{}', 3600, 20));

        // EVAL numkeys, then the keys: all three hashes carry the {TOK} hash tag,
        // which is what keeps them in one Redis Cluster slot so the script may
        // touch them together.
        $eval = $connection->calls[0];
        $this->assertSame('eval', $eval[0]);
        $this->assertSame(3, $eval[2]);
        $this->assertSame([
            'scout:import:run:{TOK}:profile:counts',
            'scout:import:run:{TOK}:profile:weights',
            'scout:import:run:{TOK}:profile:worst',
        ], array_slice($eval, 3, 3));

        $connection->calls = [];
        $store->refreshTtls('TOK', 3600);

        // The three new hashes are refreshed with the rest of the run, or a long
        // import would lose its findings mid-flight. The finalize lock is still
        // absent on purpose: stretching it to the run TTL would let an orphaned
        // lock block every later attempt to finalize.
        $this->assertSame([
            ['expire', 'scout:import:run:{TOK}:status', 3600],
            ['expire', 'scout:import:run:{TOK}:total', 3600],
            ['expire', 'scout:import:run:{TOK}:index', 3600],
            ['expire', 'scout:import:run:{TOK}:done', 3600],
            ['expire', 'scout:import:run:{TOK}:failed', 3600],
            ['expire', 'scout:import:run:{TOK}:bounds', 3600],
            ['expire', 'scout:import:run:{TOK}:profile:counts', 3600],
            ['expire', 'scout:import:run:{TOK}:profile:weights', 3600],
            ['expire', 'scout:import:run:{TOK}:profile:worst', 3600],
        ], $connection->calls);
    }
}
