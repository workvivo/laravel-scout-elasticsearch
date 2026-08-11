<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use Matchish\ScoutElasticSearch\Import\ProfileDiagnostics;
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
}
