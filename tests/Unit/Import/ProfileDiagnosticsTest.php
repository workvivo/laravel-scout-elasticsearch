<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use Matchish\ScoutElasticSearch\Import\ProfileDiagnostics;
// Imported for one assertion only: that this class truncates its SQL HARDER than
// the capture side does. The relationship between the two budgets is the contract
// (capture bounds a log line, this bounds a Redis value and a terminal line), and
// pinning it needs both numbers in one place. Nothing else here reaches across.
use Matchish\ScoutElasticSearch\Jobs\Stages\PullFromSource;
use PHPUnit\Framework\TestCase;

/**
 * The interpretation step behind `--profile-samples` and `--probe`: one chunk's
 * raw metrics in, machine codes out.
 *
 * Extends PHPUnit's TestCase and not Testbench on purpose — the class under test
 * is pure (no container, no config, no clock, no I/O), and a test that needed a
 * booted application to exercise it would be evidence that it is not.
 *
 * Two properties get more attention here than the individual rules, because
 * they are the ones that would hurt in production:
 *
 *  - the two timeout rules are MUTUALLY EXCLUSIVE, so an operator is never told
 *    that the same chunk both blew its budget and is merely approaching it;
 *  - a DRIFTED metrics array degrades to fewer findings and never throws. The
 *    array is assembled in PullFromSource, crosses a queue, and may have been
 *    written by a different release of this package than the one reading it, so
 *    every key is optional and every denominator suspect.
 */
final class ProfileDiagnosticsTest extends TestCase
{
    /**
     * @test
     */
    public function n_plus_one_names_the_worst_relation_and_counts_the_distinct_ones(): void
    {
        $findings = ProfileDiagnostics::from([
            'total_ms' => 1000.0,
            'lazy_loads' => [
                'App\Models\Order::items' => 12,
                // The worst offender is deliberately not first, so the rule
                // cannot pass by picking the head of the map.
                'App\Models\Order::user' => 480,
                'App\Models\Order::tags' => 7,
            ],
        ], null);

        $this->assertSame([ProfileDiagnostics::CODE_N_PLUS_ONE], $this->codes($findings));

        $finding = $this->finding($findings, ProfileDiagnostics::CODE_N_PLUS_ONE);

        // The weight is the hit count, so folding thousands of chunks into one
        // row per code keeps the worst chunk as the example.
        $this->assertSame(480.0, $finding['weight']);
        $this->assertSame([
            'relation' => 'App\Models\Order::user',
            'loads' => 480,
            'relations' => 3,
        ], $finding['data']);
    }

    /**
     * @test
     */
    public function n_plus_one_ignores_entries_whose_hit_count_is_not_a_number(): void
    {
        $findings = ProfileDiagnostics::from([
            'lazy_loads' => [
                'App\Models\Order::user' => null,
                'App\Models\Order::items' => ['nested'],
                'App\Models\Order::tags' => '7',
            ],
        ], null);

        $finding = $this->finding($findings, ProfileDiagnostics::CODE_N_PLUS_ONE);

        // A numeric string still counts (the map survives a JSON round trip in
        // other code paths), but the two unusable entries are dropped rather
        // than counted as zero: `relations` only claims relations we can vouch
        // for.
        $this->assertSame(7.0, $finding['weight']);
        $this->assertSame([
            'relation' => 'App\Models\Order::tags',
            'loads' => 7,
            'relations' => 1,
        ], $finding['data']);
    }

    /**
     * @test
     */
    public function n_plus_one_stays_silent_without_a_usable_lazy_load_map(): void
    {
        foreach ([
            'missing' => [],
            'empty map' => ['lazy_loads' => []],
            'not an array' => ['lazy_loads' => 'App\Models\Order::user'],
            'no usable count' => ['lazy_loads' => ['App\Models\Order::user' => 'many']],
        ] as $label => $metrics) {
            $this->assertSame([], ProfileDiagnostics::from($metrics, null), $label);
        }
    }

    /**
     * @test
     */
    public function the_two_timeout_findings_are_mutually_exclusive_at_their_boundaries(): void
    {
        // At the timeout exactly: exceeded, and never also "near".
        $this->assertSame(
            [ProfileDiagnostics::CODE_EXCEEDS_TIMEOUT],
            $this->codes(ProfileDiagnostics::from(['total_ms' => 60000.0], 60))
        );

        // Comfortably over: still only the one verdict.
        $this->assertSame(
            [ProfileDiagnostics::CODE_EXCEEDS_TIMEOUT],
            $this->codes(ProfileDiagnostics::from(['total_ms' => 120000.0], 60))
        );

        // At half the timeout exactly: near, and never also "exceeds".
        $this->assertSame(
            [ProfileDiagnostics::CODE_NEAR_TIMEOUT],
            $this->codes(ProfileDiagnostics::from(['total_ms' => 30000.0], 60))
        );

        // One millisecond under half: neither. A chunk using less than half its
        // budget is simply healthy.
        $this->assertSame([], ProfileDiagnostics::from(['total_ms' => 29999.0], 60));
    }

    /**
     * @test
     */
    public function both_timeout_findings_report_the_budget_they_consumed(): void
    {
        $exceeded = $this->finding(
            ProfileDiagnostics::from(['total_ms' => 90000.4], 60),
            ProfileDiagnostics::CODE_EXCEEDS_TIMEOUT
        );

        $this->assertSame(90000.4, $exceeded['weight']);
        $this->assertSame(['total_ms' => 90000.4, 'timeout' => 60, 'pct' => 150.0], $exceeded['data']);

        $near = $this->finding(
            ProfileDiagnostics::from(['total_ms' => 45000.0], 60),
            ProfileDiagnostics::CODE_NEAR_TIMEOUT
        );

        $this->assertSame(45000.0, $near['weight']);
        $this->assertSame(['total_ms' => 45000.0, 'timeout' => 60, 'pct' => 75.0], $near['data']);
    }

    /**
     * @test
     */
    public function an_unknown_job_timeout_emits_neither_timeout_finding(): void
    {
        // A wrong timeout would be a wrong diagnosis, so "unknown" has to stay
        // silent rather than fall back to a guess. Zero and negatives are read
        // as unknown too: treating 0 as a real budget would make every chunk
        // ever profiled exceed it.
        foreach ([null, 0, -5] as $timeout) {
            $this->assertSame(
                [],
                ProfileDiagnostics::from(['total_ms' => 999999.0], $timeout),
                var_export($timeout, true)
            );
        }
    }

    /**
     * @test
     */
    public function filter_queries_fires_only_above_one_query(): void
    {
        // None and one are the healthy shapes: shouldBeSearchable() reading
        // loaded attributes, or one query for the whole chunk.
        $this->assertSame([], ProfileDiagnostics::from(['queries' => ['filter' => 0]], null));
        $this->assertSame([], ProfileDiagnostics::from(['queries' => ['filter' => 1]], null));

        $finding = $this->finding(
            ProfileDiagnostics::from(['queries' => ['filter' => 500], 'fetched' => 500], null),
            ProfileDiagnostics::CODE_FILTER_QUERIES
        );

        $this->assertSame(500.0, $finding['weight']);
        $this->assertSame(['queries' => 500, 'fetched' => 500], $finding['data']);
    }

    /**
     * @test
     */
    public function fetch_dominant_needs_strictly_more_than_the_dominant_share(): void
    {
        // Exactly at the share is not dominant: the threshold is strict so a
        // chunk sitting on the line is not reported one run and not the next.
        $this->assertSame([], ProfileDiagnostics::from([
            'total_ms' => 1000.0,
            'fetch_ms' => 1000.0 * ProfileDiagnostics::DOMINANT_SHARE,
        ], null));

        $finding = $this->finding(
            ProfileDiagnostics::from(['total_ms' => 1000.0, 'fetch_ms' => 900.0], null),
            ProfileDiagnostics::CODE_FETCH_DOMINANT
        );

        $this->assertSame(900.0, $finding['weight']);
        $this->assertSame(['fetch_ms' => 900.0, 'total_ms' => 1000.0, 'pct' => 90.0], $finding['data']);
    }

    /**
     * @test
     */
    public function index_dominant_needs_strictly_more_than_the_dominant_share_and_reports_bulk_ms(): void
    {
        $this->assertSame([], ProfileDiagnostics::from([
            'total_ms' => 1000.0,
            'index_ms' => 1000.0 * ProfileDiagnostics::DOMINANT_SHARE,
        ], null));

        $finding = $this->finding(
            ProfileDiagnostics::from([
                'total_ms' => 1000.0,
                'index_ms' => 800.0,
                'bulk_ms' => 750.0,
            ], null),
            ProfileDiagnostics::CODE_INDEX_DOMINANT
        );

        $this->assertSame(800.0, $finding['weight']);
        // bulk_ms rides along because it is what separates the two very
        // different causes: cluster round-trip time vs. CPU spent serializing.
        $this->assertSame([
            'index_ms' => 800.0,
            'total_ms' => 1000.0,
            'bulk_ms' => 750.0,
            'pct' => 80.0,
        ], $finding['data']);
    }

    /**
     * @test
     */
    public function large_payload_measures_the_average_document_and_not_the_chunk(): void
    {
        // 500 KB over 10 documents is exactly the threshold, which is not "more
        // than" it.
        $this->assertSame([], ProfileDiagnostics::from([
            'indexed' => 10,
            'payload_kb' => 10 * ProfileDiagnostics::LARGE_DOCUMENT_KB,
        ], null));

        // A huge chunk payload spread over enough documents is not a finding
        // either: the rule is about document size, so --chunk cannot trip it.
        $this->assertSame([], ProfileDiagnostics::from([
            'indexed' => 10000,
            'payload_kb' => 100000.0,
        ], null));

        $finding = $this->finding(
            ProfileDiagnostics::from(['indexed' => 10, 'payload_kb' => 2000.0], null),
            ProfileDiagnostics::CODE_LARGE_PAYLOAD
        );

        $this->assertSame(200.0, $finding['weight']);
        $this->assertSame(['avg_kb' => 200.0, 'payload_kb' => 2000.0, 'indexed' => 10], $finding['data']);
    }

    /**
     * @test
     */
    public function a_healthy_chunk_yields_no_findings(): void
    {
        // A full, realistic metrics array: work split between fetching and
        // indexing, one query per phase, nothing lazy-loaded, a second of a
        // sixty-second budget, ~2 KB documents.
        $this->assertSame([], ProfileDiagnostics::from([
            'source' => 'Matchish\ScoutElasticSearch\Searchable\DefaultImportSource',
            'fetched' => 1000,
            'indexed' => 1000,
            'fetch_ms' => 400.0,
            'filter_ms' => 10.0,
            'index_ms' => 500.0,
            'serialize_ms' => 100.0,
            'bulk_ms' => 400.0,
            'payload_kb' => 2000.0,
            'total_ms' => 1000.0,
            'queries' => ['fetch' => 1, 'filter' => 0, 'index' => 0],
            'lazy_loads' => [],
        ], 60));
    }

    /**
     * @test
     */
    public function every_rule_can_fire_at_once_in_a_stable_order(): void
    {
        $findings = ProfileDiagnostics::from([
            'fetched' => 1000,
            'indexed' => 1000,
            'fetch_ms' => 50000.0,
            'index_ms' => 50000.0,
            'bulk_ms' => 40000.0,
            'payload_kb' => 80000.0,
            'total_ms' => 70000.0,
            'queries' => ['fetch' => 1, 'filter' => 1000, 'index' => 1],
            'lazy_loads' => ['App\Models\Order::user' => 1000],
        ], 60);

        // Order is part of the contract: a caller folding many chunks together,
        // or rendering them, gets the same sequence every time.
        $this->assertSame([
            ProfileDiagnostics::CODE_N_PLUS_ONE,
            ProfileDiagnostics::CODE_EXCEEDS_TIMEOUT,
            ProfileDiagnostics::CODE_FILTER_QUERIES,
            ProfileDiagnostics::CODE_FETCH_DOMINANT,
            ProfileDiagnostics::CODE_INDEX_DOMINANT,
            ProfileDiagnostics::CODE_LARGE_PAYLOAD,
        ], $this->codes($findings));

        // One finding per code per chunk, so the store's per-code rows cannot be
        // double-counted by a single chunk.
        $this->assertSame(array_unique($this->codes($findings)), $this->codes($findings));
    }

    /**
     * @test
     */
    public function the_codes_are_stable_strings_because_they_are_persisted(): void
    {
        // These land in a Redis hash written by one release and read by another,
        // so renaming one silently breaks rendering. Pinned deliberately.
        $this->assertSame('n_plus_one', ProfileDiagnostics::CODE_N_PLUS_ONE);
        $this->assertSame('chunk_exceeds_timeout', ProfileDiagnostics::CODE_EXCEEDS_TIMEOUT);
        $this->assertSame('chunk_near_timeout', ProfileDiagnostics::CODE_NEAR_TIMEOUT);
        $this->assertSame('filter_queries', ProfileDiagnostics::CODE_FILTER_QUERIES);
        $this->assertSame('fetch_dominant', ProfileDiagnostics::CODE_FETCH_DOMINANT);
        $this->assertSame('index_dominant', ProfileDiagnostics::CODE_INDEX_DOMINANT);
        $this->assertSame('large_payload', ProfileDiagnostics::CODE_LARGE_PAYLOAD);
    }

    /**
     * @test
     */
    public function an_empty_metrics_array_is_diagnosed_as_nothing(): void
    {
        // The degenerate drift: every key gone. Not one rule may fire on
        // defaults, or every chunk of every run would report something.
        $this->assertSame([], ProfileDiagnostics::from([], 60));
        $this->assertSame([], ProfileDiagnostics::from([], null));
    }

    /**
     * @test
     */
    public function a_drifted_metrics_array_never_throws_and_never_divides_by_zero(): void
    {
        // Wrong types everywhere: nulls, arrays where floats belong, prose where
        // numbers belong, a `queries` that is not a map at all. Each of these
        // reads as 0.0, which fires nothing.
        $this->assertSame([], ProfileDiagnostics::from([
            'fetched' => null,
            'indexed' => null,
            'fetch_ms' => ['not', 'a', 'number'],
            'index_ms' => 'quite fast',
            'bulk_ms' => false,
            'payload_kb' => null,
            'total_ms' => 'unknown',
            'queries' => 'none',
            'lazy_loads' => 17,
        ], 60));

        // total_ms of 0 is the divide-by-zero trap: both dominance rules would
        // divide by it, and the timeout percentage is computed from it.
        $this->assertSame([], ProfileDiagnostics::from([
            'total_ms' => 0,
            'fetch_ms' => 500.0,
            'index_ms' => 500.0,
        ], 60));

        // indexed of 0 is the other one — an empty chunk still reports a payload
        // size, and dividing by its document count must not happen.
        $this->assertSame([], ProfileDiagnostics::from([
            'indexed' => 0,
            'payload_kb' => 5000.0,
        ], 60));

        // A negative timeout budget combined with a zero total: pct() must
        // absorb it rather than produce -INF or NAN.
        $this->assertSame([], ProfileDiagnostics::from(['total_ms' => 0.0], -60));
    }

    /**
     * @test
     */
    public function numeric_strings_are_read_as_numbers(): void
    {
        // Everything published by a worker can arrive as a string (JSON, Redis,
        // a queue payload), so the rules must not require native floats.
        $findings = ProfileDiagnostics::from([
            'total_ms' => '1000',
            'fetch_ms' => '900',
            'fetched' => '2',
            'indexed' => '2',
            'payload_kb' => '400',
            'queries' => ['filter' => '4'],
        ], 60);

        $this->assertSame([
            ProfileDiagnostics::CODE_FILTER_QUERIES,
            ProfileDiagnostics::CODE_FETCH_DOMINANT,
            ProfileDiagnostics::CODE_LARGE_PAYLOAD,
        ], $this->codes($findings));

        // And they come back out typed: weights are floats, counts are ints.
        $filter = $this->finding($findings, ProfileDiagnostics::CODE_FILTER_QUERIES);
        $this->assertSame(4.0, $filter['weight']);
        $this->assertSame(['queries' => 4, 'fetched' => 2], $filter['data']);
    }

    /**
     * @test
     */
    public function every_finding_carries_the_shape_its_consumers_rely_on(): void
    {
        $findings = ProfileDiagnostics::from([
            'total_ms' => 70000.0,
            'fetch_ms' => 60000.0,
            'indexed' => 10,
            'payload_kb' => 2000.0,
            'queries' => ['filter' => 3],
            'lazy_loads' => ['App\Models\Order::user' => 12],
        ], 60);

        $this->assertNotSame([], $findings);

        // A list, not a map: the publisher iterates it and the store keys the
        // result by the `code` field, not by position.
        $this->assertSame(range(0, count($findings) - 1), array_keys($findings));

        foreach ($findings as $finding) {
            $this->assertSame(['code', 'weight', 'data'], array_keys($finding));
            $this->assertIsString($finding['code']);
            $this->assertIsFloat($finding['weight']);

            foreach ($finding['data'] as $key => $value) {
                // The store JSON-encodes this and drops non-scalars on read, so
                // a non-scalar here would be silently lost before the terminal.
                $this->assertIsString($key, $finding['code'].' data key');
                $this->assertTrue(is_scalar($value), $finding['code'].' data value '.$key.' must be scalar');
            }
        }
    }

    // --- the slowest query of the dominant phase ----------------------------
    //
    // WHY ONLY TWO FINDINGS CARRY IT. `fetch_dominant` and `index_dominant` are
    // the two verdicts whose very next question is "which query?" — the PERF run
    // that motivated this reported 258 seconds of fetch over 5 queries and never
    // said which of the 5 owned it. Every other finding already names its own
    // subject (a relation, a document size, a query count), so attaching SQL to
    // them would be noise in a Redis record and on a terminal.
    //
    // PRIVACY IS THE HARD REQUIREMENT. `slow_sql` is the statement with `?`
    // placeholders and nothing else; the sibling `bindings` of a query log entry
    // are real row data and are never captured upstream, so nothing here may
    // start plumbing them through. The tests below assert on whole `data` arrays
    // precisely so a new key smuggling values in cannot pass unnoticed.

    /**
     * @test
     */
    public function each_dominance_finding_carries_its_own_phase_and_not_the_other_phase_query(): void
    {
        $findings = ProfileDiagnostics::from($this->dominantMetrics([
            'slow_query' => [
                'fetch' => ['sql' => 'select * from "users" where "id" > ? order by "id" asc limit 1000', 'ms' => 258439.8],
                // The decoy. The filter phase's statement must reach NEITHER
                // finding: a filter query is its own diagnosis (filter_queries)
                // and attributing it to the fetch would send an operator to
                // EXPLAIN the wrong statement.
                'filter' => ['sql' => 'select count(*) from "subscriptions" where "user_id" = ?', 'ms' => 3.7],
                'index' => ['sql' => 'select * from "roles" where "roles"."id" in (?×1000)', 'ms' => 267.3],
            ],
        ]), null);

        $this->assertSame([
            ProfileDiagnostics::CODE_FETCH_DOMINANT,
            ProfileDiagnostics::CODE_INDEX_DOMINANT,
        ], $this->codes($findings));

        // Appended AFTER every pre-existing key, and in this order: consumers
        // read the earlier keys and some assert on the whole array, so the shape
        // they knew has to survive byte for byte with the new keys on the end.
        $this->assertSame([
            'fetch_ms' => 800.0,
            'total_ms' => 1000.0,
            'pct' => 80.0,
            'slow_sql' => 'select * from "users" where "id" > ? order by "id" asc limit 1000',
            'slow_ms' => 258439.8,
        ], $this->finding($findings, ProfileDiagnostics::CODE_FETCH_DOMINANT)['data']);

        $this->assertSame([
            'index_ms' => 700.0,
            'total_ms' => 1000.0,
            'bulk_ms' => 650.0,
            'pct' => 70.0,
            'slow_sql' => 'select * from "roles" where "roles"."id" in (?×1000)',
            'slow_ms' => 267.3,
        ], $this->finding($findings, ProfileDiagnostics::CODE_INDEX_DOMINANT)['data']);

        // Stated as a property rather than left implicit in the two arrays above:
        // the phases must not be crossed, and the unrelated phase must not leak.
        foreach ($findings as $finding) {
            $this->assertStringNotContainsString(
                'subscriptions',
                (string) json_encode($finding['data']),
                $finding['code'].' must not carry the filter phase statement'
            );
        }

        // The weights are untouched by any of this — they are still the phase
        // timings, which is what folds many chunks into one row per code.
        $this->assertSame(800.0, $this->finding($findings, ProfileDiagnostics::CODE_FETCH_DOMINANT)['weight']);
        $this->assertSame(700.0, $this->finding($findings, ProfileDiagnostics::CODE_INDEX_DOMINANT)['weight']);
    }

    /**
     * @test
     */
    public function the_dominance_findings_omit_both_keys_when_no_query_was_captured(): void
    {
        // ABSENCE IS EXPRESSED BY ABSENCE, never by null or ''. `data` is typed
        // array<string, scalar> — the store drops non-scalars silently and the
        // renderer tests for the key — so a null would be lost somewhere between
        // the worker and the terminal instead of being handled.
        //
        // Every one of these is a real arrival: metrics written by a release from
        // before capture existed, a phase that issued no query at all, and a
        // framework log entry that no longer carries a usable statement.
        $variants = [
            'slow_query missing entirely' => [],
            'slow_query not an array' => ['slow_query' => 'select 1'],
            'slow_query empty' => ['slow_query' => []],
            'only another phase captured' => ['slow_query' => ['filter' => ['sql' => 'select 1', 'ms' => 1.0]]],
            'phase entry null' => ['slow_query' => ['fetch' => null, 'index' => null]],
            'phase entry not an array' => ['slow_query' => ['fetch' => 'select 1', 'index' => 12.0]],
            'sql missing' => ['slow_query' => ['fetch' => ['ms' => 12.0], 'index' => ['ms' => 12.0]]],
            'sql not a string' => ['slow_query' => [
                'fetch' => ['sql' => ['select 1'], 'ms' => 1.0],
                'index' => ['sql' => 42, 'ms' => 1.0],
            ]],
            // A duration with no statement is dropped whole: the phase timing
            // already reports the duration, so `slow_ms` alone would add nothing
            // and would render as a sentence with a missing subject.
            'sql empty or blank' => ['slow_query' => [
                'fetch' => ['sql' => '', 'ms' => 99.0],
                'index' => ['sql' => "  \n ", 'ms' => 99.0],
            ]],
        ];

        foreach ($variants as $label => $slowQuery) {
            $findings = ProfileDiagnostics::from($this->dominantMetrics($slowQuery), null);

            $this->assertSame([
                'fetch_ms' => 800.0,
                'total_ms' => 1000.0,
                'pct' => 80.0,
            ], $this->finding($findings, ProfileDiagnostics::CODE_FETCH_DOMINANT)['data'], $label);

            $this->assertSame([
                'index_ms' => 700.0,
                'total_ms' => 1000.0,
                'bulk_ms' => 650.0,
                'pct' => 70.0,
            ], $this->finding($findings, ProfileDiagnostics::CODE_INDEX_DOMINANT)['data'], $label);
        }
    }

    /**
     * @test
     */
    public function a_captured_statement_with_an_unusable_duration_still_names_the_statement(): void
    {
        // The one tolerated partial. The statement is the half an operator acts
        // on; the duration is a nicety the phase timing already covers. So a
        // missing or drifted `ms` degrades to 0.0 rather than dropping the SQL.
        foreach ([['sql' => 'select 1'], ['sql' => 'select 1', 'ms' => 'quite slow'], ['sql' => 'select 1', 'ms' => null]] as $entry) {
            $data = $this->finding(
                ProfileDiagnostics::from($this->dominantMetrics(['slow_query' => ['fetch' => $entry]]), null),
                ProfileDiagnostics::CODE_FETCH_DOMINANT
            )['data'];

            $this->assertSame('select 1', $data['slow_sql']);
            $this->assertSame(0.0, $data['slow_ms']);
        }

        // And a numeric string counts, like every other metric in this class:
        // the value survives a JSON round trip through the run record.
        $data = $this->finding(
            ProfileDiagnostics::from($this->dominantMetrics([
                'slow_query' => ['fetch' => ['sql' => 'select 1', 'ms' => '267.3']],
            ]), null),
            ProfileDiagnostics::CODE_FETCH_DOMINANT
        )['data'];

        $this->assertSame(267.3, $data['slow_ms']);
    }

    /**
     * @test
     */
    public function no_finding_other_than_the_two_dominance_ones_gains_a_slow_query(): void
    {
        // Every rule firing at once, with a statement captured for all three
        // phases — the shape most likely to over-attach. Only the two dominance
        // findings may carry SQL.
        $findings = ProfileDiagnostics::from([
            'fetched' => 1000,
            'indexed' => 1000,
            'fetch_ms' => 50000.0,
            'index_ms' => 50000.0,
            'bulk_ms' => 40000.0,
            'payload_kb' => 80000.0,
            'total_ms' => 70000.0,
            'queries' => ['fetch' => 1, 'filter' => 1000, 'index' => 1],
            'lazy_loads' => ['App\Models\Order::user' => 1000],
            'slow_query' => [
                'fetch' => ['sql' => 'select * from "orders" where "id" > ?', 'ms' => 50000.0],
                'filter' => ['sql' => 'select 1 from "flags" where "order_id" = ?', 'ms' => 40.0],
                'index' => ['sql' => 'select * from "users" where "id" = ?', 'ms' => 30.0],
            ],
        ], 60);

        $carry = [ProfileDiagnostics::CODE_FETCH_DOMINANT, ProfileDiagnostics::CODE_INDEX_DOMINANT];

        foreach ($findings as $finding) {
            if (in_array($finding['code'], $carry, true)) {
                $this->assertArrayHasKey('slow_sql', $finding['data'], $finding['code']);
                $this->assertArrayHasKey('slow_ms', $finding['data'], $finding['code']);

                continue;
            }

            $this->assertArrayNotHasKey('slow_sql', $finding['data'], $finding['code']);
            $this->assertArrayNotHasKey('slow_ms', $finding['data'], $finding['code']);
        }

        // filter_queries is the pointed case: the filter phase DID capture a
        // statement, and it still must not be attached to anything. The remedy
        // for filter_queries is to stop querying per model, not to EXPLAIN it.
        $this->assertSame(
            ['queries' => 1000, 'fetched' => 1000],
            $this->finding($findings, ProfileDiagnostics::CODE_FILTER_QUERIES)['data']
        );
    }

    /**
     * @test
     */
    public function the_two_new_values_stay_scalar_because_the_store_drops_anything_else(): void
    {
        $data = $this->finding(
            ProfileDiagnostics::from($this->dominantMetrics([
                'slow_query' => ['fetch' => ['sql' => 'select * from "users" where "id" > ?', 'ms' => 258439.8]],
            ]), null),
            ProfileDiagnostics::CODE_FETCH_DOMINANT
        )['data'];

        $this->assertIsString($data['slow_sql']);
        $this->assertIsFloat($data['slow_ms']);

        // The actual downstream trip: json_encode into the Redis run record and
        // back out for the terminal. The statement has to survive it character
        // for character — a multibyte marker mangled here would print as noise
        // under the finding an operator is trying to act on.
        //
        // Only the two new keys are round-tripped, because a whole float comes
        // back from JSON as an int (800.0 encodes as `800`), which is true of the
        // pre-existing keys already and is why nothing downstream compares these
        // numbers strictly. The duration below is deliberately not whole.
        $encoded = json_encode(['slow_sql' => $data['slow_sql'], 'slow_ms' => $data['slow_ms']]);
        $this->assertIsString($encoded);
        $this->assertSame([
            'slow_sql' => 'select * from "users" where "id" > ?',
            'slow_ms' => 258439.8,
        ], json_decode((string) $encoded, true));
    }

    /**
     * @test
     */
    public function the_finding_budget_truncates_harder_than_the_capture_budget(): void
    {
        // Two different jobs, two different limits, deliberately not one constant
        // shared between the layers: capture writes one log line inside a worker,
        // while this value is JSON-encoded into the run record for the life of the
        // run AND printed under a finding sentence on a terminal.
        $this->assertSame(200, ProfileDiagnostics::SLOW_SQL_CHARS);
        $this->assertLessThan(PullFromSource::SLOW_QUERY_SQL_CHARS, ProfileDiagnostics::SLOW_SQL_CHARS);

        $long = 'select '.str_repeat('"users"."some_column_name", ', 30)
            .'"users"."id" from "users" where "id" > ? order by "id" asc';

        $data = $this->finding(
            ProfileDiagnostics::from($this->dominantMetrics([
                'slow_query' => ['fetch' => ['sql' => $long, 'ms' => 1.0]],
            ]), null),
            ProfileDiagnostics::CODE_FETCH_DOMINANT
        )['data'];

        $slowSql = (string) $data['slow_sql'];

        // The head is what names the missing index — the verb, then the tables —
        // so it is the half that is kept.
        $this->assertStringStartsWith('select "users"."some_column_name",', $slowSql);
        $this->assertStringEndsWith('...', $slowSql);
        $this->assertLessThanOrEqual(ProfileDiagnostics::SLOW_SQL_CHARS + 3, mb_strlen($slowSql));

        // A statement already inside the budget is passed through untouched: no
        // ellipsis on a query that never needed one.
        $short = $this->finding(
            ProfileDiagnostics::from($this->dominantMetrics([
                'slow_query' => ['fetch' => ['sql' => 'select * from "users" where "id" > ?', 'ms' => 1.0]],
            ]), null),
            ProfileDiagnostics::CODE_FETCH_DOMINANT
        )['data'];

        $this->assertSame('select * from "users" where "id" > ?', $short['slow_sql']);
    }

    /**
     * @test
     */
    public function truncation_never_splits_the_multibyte_placeholder_marker(): void
    {
        // The capture layer collapses a 1000-key IN list to `?×1000` using U+00D7,
        // a MULTIBYTE character, and this layer then cuts the result 100
        // characters shorter. A byte-wise cut landing inside that character would
        // emit invalid UTF-8 into the json_encode of the run record — which fails
        // outright, taking the whole record with it, rather than merely printing
        // badly. So the boundary is swept, not spot-checked.
        for ($prefix = 190; $prefix <= 210; $prefix++) {
            $sql = str_repeat('a', $prefix).' in (?×1000) and "tenant_id" = ? '.str_repeat('b', 300);

            $data = $this->finding(
                ProfileDiagnostics::from($this->dominantMetrics([
                    'slow_query' => ['fetch' => ['sql' => $sql, 'ms' => 1.0]],
                ]), null),
                ProfileDiagnostics::CODE_FETCH_DOMINANT
            )['data'];

            $slowSql = (string) $data['slow_sql'];

            $this->assertTrue(mb_check_encoding($slowSql, 'UTF-8'), "prefix {$prefix} produced invalid UTF-8");
            $this->assertIsString(json_encode($data), "prefix {$prefix} could not be encoded for the run record");
            $this->assertLessThanOrEqual(ProfileDiagnostics::SLOW_SQL_CHARS + 3, mb_strlen($slowSql));
        }
    }

    /**
     * @test
     */
    public function the_statement_is_copied_verbatim_apart_from_trimming_and_truncation(): void
    {
        // Normalising whitespace and collapsing placeholder runs is the CAPTURE
        // layer's job, done once next to the query log. This layer deliberately
        // does not repeat it: a second normalisation pass would be two places to
        // keep in agreement about what an operator is shown, and it would quietly
        // rewrite a value that a different release of the package produced.
        $data = $this->finding(
            ProfileDiagnostics::from($this->dominantMetrics([
                'slow_query' => ['fetch' => ['sql' => "  select 1\nfrom \"users\"  ", 'ms' => 1.0]],
            ]), null),
            ProfileDiagnostics::CODE_FETCH_DOMINANT
        )['data'];

        $this->assertSame("select 1\nfrom \"users\"", $data['slow_sql']);
    }

    /**
     * @test
     */
    public function a_captured_statement_cannot_conjure_a_finding_on_its_own(): void
    {
        // The keys ride along with a verdict; they never cause one. A healthy
        // chunk that happened to capture a statement per phase is still healthy,
        // and a metrics array carrying nothing but slow_query is not a diagnosis.
        $slowQuery = [
            'slow_query' => [
                'fetch' => ['sql' => 'select * from "users" where "id" > ?', 'ms' => 258439.8],
                'filter' => ['sql' => 'select 1', 'ms' => 1.0],
                'index' => ['sql' => 'select * from "roles" where "id" in (?×1000)', 'ms' => 267.3],
            ],
        ];

        $this->assertSame([], ProfileDiagnostics::from($slowQuery, null));
        $this->assertSame([], ProfileDiagnostics::from($slowQuery, 60));

        // Under the dominance threshold, with the slowest statement of the run
        // sitting right there: still no finding, so the huge `ms` cannot promote
        // a healthy split between the phases into a verdict.
        $this->assertSame([], ProfileDiagnostics::from(array_merge($slowQuery, [
            'total_ms' => 1000.0,
            'fetch_ms' => 500.0,
            'index_ms' => 400.0,
        ]), 60));
    }

    /**
     * The codes of a findings list, in the order they were emitted.
     *
     * @param  list<array{code: string, weight: float, data: array<string, scalar>}>  $findings
     * @return list<string>
     */
    private function codes(array $findings): array
    {
        return array_map(static fn (array $finding): string => $finding['code'], $findings);
    }

    /**
     * The one finding with this code, failing the test when it was not emitted.
     *
     * @param  list<array{code: string, weight: float, data: array<string, scalar>}>  $findings
     * @return array{code: string, weight: float, data: array<string, scalar>}
     */
    private function finding(array $findings, string $code): array
    {
        foreach ($findings as $finding) {
            if ($finding['code'] === $code) {
                return $finding;
            }
        }

        $this->fail('Expected a '.$code.' finding, got: '.implode(', ', $this->codes($findings)));
    }

    /**
     * Metrics that trip BOTH dominance rules at once, plus whatever the caller
     * wants to say about `slow_query`.
     *
     * Both at once on purpose: it is the only arrangement that proves the two
     * findings read different phases rather than sharing one lookup. The timings
     * are impossible in the strict sense — 800 ms of fetch and 700 ms of index
     * inside a 1000 ms chunk — which is exactly why they work here: each phase is
     * over the 60% share, so both rules fire from one metrics array and no rule,
     * threshold or weight had to be bent to arrange it.
     *
     * @param  array<string, mixed>  $slowQuery
     * @return array<string, mixed>
     */
    private function dominantMetrics(array $slowQuery = []): array
    {
        return array_merge([
            'fetch_ms' => 800.0,
            'index_ms' => 700.0,
            'bulk_ms' => 650.0,
            'total_ms' => 1000.0,
        ], $slowQuery);
    }
}
