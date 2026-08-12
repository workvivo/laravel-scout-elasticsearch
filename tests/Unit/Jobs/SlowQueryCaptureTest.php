<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use Matchish\ScoutElasticSearch\Jobs\Stages\PullFromSource;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The capture half of "which query is slow": {@see PullFromSource::slowestQuery()}
 * reduces one phase's `DB::getQueryLog()` to the single worst statement in it.
 *
 * WHY THIS IS TESTED IN ISOLATION. The method is private and static, so it is
 * exercised through reflection here rather than by running a profiled chunk. That
 * is deliberate: driving it end to end would need a database, a cluster and a
 * real slow query to make the interesting cases (a 1000-key eager load, a log
 * entry from a framework release that dropped a key) reproducible, and none of
 * those would pin the behaviour any harder than a hand-written log array does.
 * The array shape is the real contract — Laravel writes
 * `compact('query', 'bindings', 'time')` per entry — and that is what the cases
 * below are built from.
 *
 * THE PROPERTY THAT MATTERS MOST IS PRIVACY, and it is asserted first. The
 * captured SQL travels into the application log and into the Redis run record,
 * and the `bindings` sitting right next to it in every log entry hold real row
 * data: emails, names, API tokens. Nothing this method returns may ever contain
 * one, which is why the first test asserts on the WHOLE captured structure rather
 * than on its `sql` key: a leak added later would most plausibly arrive as an
 * extra key ('bindings' => [...]) that an assertion narrowed to `sql` would
 * happily ignore.
 *
 * The second property is that collapsing placeholder runs happens BEFORE
 * truncation. An eager load over a 1000-row chunk logs ~3000 characters of
 * `?, ?, ?, ...`; truncating first would return a wall of question marks and
 * throw away the table and join names that are the entire diagnostic value.
 */
final class SlowQueryCaptureTest extends TestCase
{
    /**
     * A recognisable secret, in the two places a query log would really carry
     * one, so a leak into the captured structure is impossible to miss.
     */
    private const SECRET_EMAIL = 'hunter2@example.com';

    private const SECRET_TOKEN = 'sk-live-7f3a9c1eSECRETTOKEN';

    /**
     * @test
     */
    public function the_capture_keeps_the_statement_and_never_a_bound_value(): void
    {
        // The realistic shape: one statement, its bindings, its duration. The
        // duration is the one from the PERF run this feature exists for.
        $captured = $this->slowest([
            [
                'query' => 'select * from "users" where "email" = ? and "api_token" = ? limit 1',
                'bindings' => [self::SECRET_EMAIL, self::SECRET_TOKEN],
                'time' => 258439.8,
            ],
        ]);

        // Asserted as a WHOLE STRUCTURE, not key by key: the leak this test
        // guards against is an extra key appearing beside `sql`, and only an
        // exact comparison catches that.
        $this->assertSame([
            'sql' => 'select * from "users" where "email" = ? and "api_token" = ? limit 1',
            'ms' => 258439.8,
        ], $captured);

        // Spelled out separately because it is the actual requirement, and
        // because a future refactor is far more likely to break this than the
        // comparison above is to be updated thoughtfully.
        $this->assertSame(['sql', 'ms'], array_keys((array) $captured));
        $this->assertArrayNotHasKey('bindings', (array) $captured);

        // A serialized sweep of everything that leaves the method, mirroring what
        // actually happens downstream: this structure is json_encode()d into the
        // Redis run record and printed to a terminal.
        $serialized = (string) json_encode($captured);
        $this->assertStringNotContainsString(self::SECRET_EMAIL, $serialized);
        $this->assertStringNotContainsString(self::SECRET_TOKEN, $serialized);
        $this->assertStringNotContainsString('hunter2', $serialized);
    }

    /**
     * @test
     */
    public function a_thousand_bound_values_stay_out_of_a_collapsed_eager_load(): void
    {
        // The worst case for privacy is also the worst case for length: an eager
        // load whose bindings are a thousand rows of real data. The count is
        // reported, the values are not.
        $emails = [];
        for ($i = 0; $i < 1000; $i++) {
            $emails[] = "user{$i}@example.com";
        }

        $captured = $this->slowest([
            [
                'query' => 'select * from "users" where "email" in ('.implode(', ', array_fill(0, 1000, '?')).')',
                'bindings' => $emails,
                'time' => 91.4,
            ],
        ]);

        $this->assertSame([
            'sql' => 'select * from "users" where "email" in (?×1000)',
            'ms' => 91.4,
        ], $captured);

        $this->assertStringNotContainsString('@example.com', (string) json_encode($captured));
    }

    /**
     * @test
     */
    public function the_slowest_entry_wins_wherever_it_sits_in_the_log(): void
    {
        // Five queries, the slow one in the middle: exactly the PERF case, where
        // the operator was told "fetch: 5 queries, 258 seconds" and could not
        // tell which of the five owned the 258 seconds.
        $captured = $this->slowest([
            ['query' => 'select 1 /* first */', 'bindings' => [], 'time' => 0.4],
            ['query' => 'select 2 /* second */', 'bindings' => [], 'time' => 12.0],
            ['query' => 'select 3 /* the slow one */', 'bindings' => [], 'time' => 258439.8],
            ['query' => 'select 4 /* fourth */', 'bindings' => [], 'time' => 7.5],
            ['query' => 'select 5 /* last */', 'bindings' => [], 'time' => 250000.0],
        ]);

        $this->assertSame(['sql' => 'select 3 /* the slow one */', 'ms' => 258439.8], $captured);

        // And it is genuinely a maximum, not "the first" or "the last": the same
        // log with the order reversed picks the same statement.
        $reversedCapture = $this->slowest([
            ['query' => 'select 5 /* last */', 'bindings' => [], 'time' => 250000.0],
            ['query' => 'select 4 /* fourth */', 'bindings' => [], 'time' => 7.5],
            ['query' => 'select 3 /* the slow one */', 'bindings' => [], 'time' => 258439.8],
            ['query' => 'select 2 /* second */', 'bindings' => [], 'time' => 12.0],
            ['query' => 'select 1 /* first */', 'bindings' => [], 'time' => 0.4],
        ]);

        $this->assertSame(['sql' => 'select 3 /* the slow one */', 'ms' => 258439.8], $reversedCapture);
    }

    /**
     * @test
     */
    public function a_tie_resolves_to_the_earliest_of_the_equally_slow_statements(): void
    {
        // Ties are not exotic: a chunk whose queries all cost the same is the
        // normal shape of a healthy fetch, and the timings are rounded floats. The
        // answer has to be deterministic, or the same run reports a different
        // statement on every chunk and the operator learns nothing.
        $captured = $this->slowest([
            ['query' => 'select /* earliest */ 1', 'bindings' => [], 'time' => 5.0],
            ['query' => 'select /* also 5ms */ 2', 'bindings' => [], 'time' => 5.0],
            ['query' => 'select /* also 5ms */ 3', 'bindings' => [], 'time' => 5.0],
        ]);

        $this->assertSame(['sql' => 'select /* earliest */ 1', 'ms' => 5.0], $captured);

        // The same rule at the degenerate end: every entry reads as 0.0 ms
        // (missing and unusable durations), so the first entry still wins rather
        // than the last one silently overwriting it.
        $allZero = $this->slowest([
            ['query' => 'select /* earliest */ 1'],
            ['query' => 'select 2', 'time' => 0.0],
            ['query' => 'select 3', 'time' => 'unknown'],
        ]);

        $this->assertSame(['sql' => 'select /* earliest */ 1', 'ms' => 0.0], $allZero);
    }

    /**
     * @test
     */
    public function a_thousand_placeholder_eager_load_reports_the_count_and_keeps_the_query_around_it(): void
    {
        // THE POINT OF COLLAPSING BEFORE TRUNCATING. Both ends of the statement
        // are what an operator needs — the join on the left, the ordering on the
        // right — and the 1000 placeholders in between are pure noise that would
        // otherwise consume the entire character budget.
        $head = 'select "users".* from "users" inner join "roles" on "roles"."id" = "users"."role_id" where "users"."id" in (';
        $tail = ') order by "users"."id" asc';

        $captured = $this->slowest([
            [
                'query' => $head.implode(', ', array_fill(0, 1000, '?')).$tail,
                'bindings' => range(1, 1000),
                'time' => 12.5,
            ],
        ]);

        // The count survives as a marker, and everything that identifies the
        // query survives with it.
        $this->assertSame(['sql' => $head.'?×1000'.$tail, 'ms' => 12.5], $captured);

        $sql = (string) $captured['sql'];
        $this->assertStringContainsString('inner join "roles"', $sql);
        $this->assertStringContainsString('order by "users"."id" asc', $sql);

        // The marker is the multiplication sign U+00D7, not an ASCII 'x' — pinned
        // because the renderers print it verbatim and because the JSON encode of
        // the run record is where a broken multibyte cut would surface.
        $this->assertStringContainsString('?×1000', $sql);
        $this->assertStringNotContainsString('?x1000', $sql);
        $this->assertTrue(mb_check_encoding($sql, 'UTF-8'));

        // Well inside the budget once collapsed, which is the whole gain: the raw
        // statement was over ten times the limit.
        $this->assertLessThanOrEqual(PullFromSource::SLOW_QUERY_SQL_CHARS, mb_strlen($sql));
        $this->assertStringNotContainsString('...', $sql);
    }

    /**
     * @test
     */
    public function truncation_happens_after_collapsing_so_the_interesting_head_survives(): void
    {
        // A statement long enough to be cut even AFTER its placeholders collapse,
        // so both mechanisms are visible in one case.
        $raw = 'select * from "users" where "id" in ('
            .implode(', ', array_fill(0, 1000, '?')).') and '
            .str_repeat('"tenant_id" = ? and ', 30)
            .'"active" = ? order by "id" asc';

        $captured = $this->slowest([['query' => $raw, 'bindings' => range(1, 1031), 'time' => 3.0]]);
        $sql = (string) $captured['sql'];

        // What the operator gets: the verb, the table, the fact that it was a
        // 1000-key IN, and the beginning of the rest of the predicate.
        $this->assertStringStartsWith('select * from "users" where "id" in (?×1000) and "tenant_id" = ?', $sql);
        $this->assertStringEndsWith('...', $sql);

        // Bounded, ellipsis included. Str::limit() rtrims before appending, so the
        // ceiling is the budget plus the three dots and never more.
        $this->assertLessThanOrEqual(PullFromSource::SLOW_QUERY_SQL_CHARS + 3, mb_strlen($sql));
        $this->assertTrue(mb_check_encoding($sql, 'UTF-8'));

        // THE CONTRAST THAT JUSTIFIES THE ORDER. Truncating the raw statement to
        // the same budget first — the naive implementation — reaches nothing past
        // the placeholder list, so the predicate the collapse preserved above
        // would have been lost entirely.
        $naive = mb_substr($raw, 0, PullFromSource::SLOW_QUERY_SQL_CHARS);
        $this->assertStringNotContainsString('"tenant_id" = ?', $naive);
        $this->assertStringContainsString('"tenant_id" = ?', $sql);
    }

    /**
     * @test
     */
    public function a_run_of_two_placeholders_is_left_alone_and_three_collapse(): void
    {
        // The PLACEHOLDER_RUN_MIN boundary. `in (?, ?)` is already readable and
        // collapsing it would cost more clarity than it saves, so the marker only
        // starts paying for itself at three.
        $this->assertSame(
            ['sql' => 'select * from "users" where "id" in (?)', 'ms' => 1.0],
            $this->slowest([['query' => 'select * from "users" where "id" in (?)', 'time' => 1.0]])
        );

        $this->assertSame(
            ['sql' => 'select * from "users" where "id" in (?, ?)', 'ms' => 1.0],
            $this->slowest([['query' => 'select * from "users" where "id" in (?, ?)', 'time' => 1.0]])
        );

        $this->assertSame(
            ['sql' => 'select * from "users" where "id" in (?×3)', 'ms' => 1.0],
            $this->slowest([['query' => 'select * from "users" where "id" in (?, ?, ?)', 'time' => 1.0]])
        );

        // A collapsed run does not swallow the placeholders around it that are not
        // part of it: the trailing `= ?` is a separate parameter and stays.
        $this->assertSame(
            ['sql' => 'select * from "users" where "id" in (?×4) and "tenant_id" = ?', 'ms' => 1.0],
            $this->slowest([
                ['query' => 'select * from "users" where "id" in (?, ?, ?, ?) and "tenant_id" = ?', 'time' => 1.0],
            ])
        );
    }

    /**
     * @test
     */
    public function whitespace_is_normalised_so_one_query_stays_one_log_line(): void
    {
        // A statement built by a query builder over several lines, or written into
        // a heredoc by hand. The captured value goes into a single log line and
        // into a terminal, so newlines and runs of spaces and tabs become single
        // spaces and the outer whitespace goes away.
        $captured = $this->slowest([
            [
                'query' => "\n  select *\n\tfrom  \"users\"\r\n  where \"id\"   =  ?\n  limit 1000  \n",
                'bindings' => [42],
                'time' => 2.5,
            ],
        ]);

        $this->assertSame(['sql' => 'select * from "users" where "id" = ? limit 1000', 'ms' => 2.5], $captured);

        $sql = (string) $captured['sql'];
        $this->assertStringNotContainsString("\n", $sql);
        $this->assertStringNotContainsString("\t", $sql);
        $this->assertStringNotContainsString('  ', $sql);
    }

    /**
     * @test
     */
    public function placeholders_split_over_several_lines_still_read_as_one_run(): void
    {
        // Normalising whitespace BEFORE looking for runs is what makes this work:
        // a formatted IN list is the same run as a single-line one, and would
        // otherwise escape collapsing entirely — the exact case (an eager load)
        // where collapsing matters most.
        $captured = $this->slowest([
            [
                'query' => "select *\nfrom \"roles\"\nwhere \"id\" in (\n    ?,\n    ?,\n    ?,\n    ?\n)",
                'bindings' => [1, 2, 3, 4],
                'time' => 4.0,
            ],
        ]);

        $this->assertSame(['sql' => 'select * from "roles" where "id" in ( ?×4 )', 'ms' => 4.0], $captured);
    }

    /**
     * @test
     */
    public function an_empty_log_captures_nothing(): void
    {
        // The normal case for a phase that issued no query at all — a
        // shouldBeSearchable() reading only loaded attributes, or an empty chunk
        // that never reached the bulk request. Null, not an empty array, because
        // every consumer distinguishes "no query" from "a query with no SQL".
        $this->assertNull($this->slowest([]));
    }

    /**
     * @test
     */
    public function a_drifted_log_entry_degrades_instead_of_throwing(): void
    {
        // The query log belongs to the framework, not to this package: its entries
        // have gained and lost keys across releases, and this code runs inside a
        // diagnostic that only exists to explain a slow import. Throwing here
        // would turn "your import is slow" into "your import is broken".

        // A missing duration reads as 0.0 rather than dropping the statement: the
        // statement is the useful half.
        $this->assertSame(
            ['sql' => 'select * from "users"', 'ms' => 0.0],
            $this->slowest([['query' => 'select * from "users"']])
        );

        // Unusable durations of every flavour, all read as 0.0.
        foreach ([null, 'quite slow', ['12'], true, false, new \stdClass()] as $time) {
            $captured = $this->slowest([['query' => 'select 1', 'time' => $time]]);

            $this->assertSame(
                ['sql' => 'select 1', 'ms' => 0.0],
                $captured,
                'time: '.gettype($time)
            );
        }

        // A numeric string still counts: everything a worker touches can arrive
        // as a string after a JSON or Redis round trip.
        $this->assertSame(
            ['sql' => 'select 1', 'ms' => 12.3],
            $this->slowest([['query' => 'select 1', 'time' => '12.3']])
        );

        // A missing or unusable statement leaves an empty string and keeps the
        // duration, rather than fabricating SQL or throwing on the concatenation.
        $this->assertSame(['sql' => '', 'ms' => 5.0], $this->slowest([['time' => 5.0]]));

        foreach ([null, 42, ['select 1'], 1.5, true] as $query) {
            $this->assertSame(
                ['sql' => '', 'ms' => 5.0],
                $this->slowest([['query' => $query, 'time' => 5.0]]),
                'query: '.gettype($query)
            );
        }

        // Entries that are not arrays at all are skipped, not read: a log of
        // nothing but junk captures nothing...
        $this->assertNull($this->slowest(['select 1', 42, null, true, new \stdClass()]));

        // ...and junk mixed in with real entries does not stop the real slowest
        // one from being found.
        $this->assertSame(
            ['sql' => 'select /* the slow one */ 2', 'ms' => 99.9],
            $this->slowest([
                'not an entry',
                ['query' => 'select 1', 'time' => 1.0],
                null,
                ['query' => 'select /* the slow one */ 2', 'time' => 99.9],
                42,
            ])
        );

        // An entirely empty entry: neither key present. Still no throw, and still
        // a structurally valid capture.
        $this->assertSame(['sql' => '', 'ms' => 0.0], $this->slowest([[]]));
    }

    /**
     * @test
     */
    public function only_the_statement_and_its_duration_ever_leave_the_capture(): void
    {
        // Laravel 11 added `connection` and `connectionName` to log entries, and a
        // future release may add more. The capture is a whitelist of two keys, so
        // a new sibling key — including one holding data — cannot ride along by
        // accident. This is the structural half of the privacy requirement.
        $captured = $this->slowest([
            [
                'query' => 'select * from "users" where "email" = ?',
                'bindings' => [self::SECRET_EMAIL],
                'time' => 8.0,
                'connection' => 'mysql',
                'connectionName' => 'mysql',
                'raw_sql' => 'select * from "users" where "email" = \''.self::SECRET_EMAIL.'\'',
            ],
        ]);

        $this->assertSame([
            'sql' => 'select * from "users" where "email" = ?',
            'ms' => 8.0,
        ], $captured);
        $this->assertStringNotContainsString(self::SECRET_EMAIL, (string) json_encode($captured));
    }

    /**
     * @test
     */
    public function the_captured_duration_is_rounded_like_every_other_timing(): void
    {
        // Same convention as fetch_ms / bulk_ms, so a slow query's duration can be
        // compared with the phase timing it sits under without one of them
        // carrying six decimals of noise from a microtime subtraction.
        $this->assertSame(
            ['sql' => 'select 1', 'ms' => 258439.8],
            $this->slowest([['query' => 'select 1', 'time' => 258439.83912]])
        );

        $this->assertSame(
            ['sql' => 'select 1', 'ms' => 0.0],
            $this->slowest([['query' => 'select 1', 'time' => 0.04]])
        );
    }

    /**
     * @test
     */
    public function the_truncation_budget_is_published_because_it_bounds_a_log_line(): void
    {
        // Public on purpose: it is the ceiling on a value that lands in the
        // application log and, truncated further, in the Redis run record, so the
        // layers above bound their own output against it rather than guessing.
        $this->assertSame(300, PullFromSource::SLOW_QUERY_SQL_CHARS);
    }

    /**
     * The slowest entry of a phase's query log, as the private static helper
     * computes it.
     *
     * Reflection rather than a public seam: the method is an implementation
     * detail of one profiled chunk, and widening its visibility purely so a test
     * can reach it would publish a shape that has no consumer outside this file.
     *
     * @param  array<array-key, mixed>  $log
     * @return array<string, mixed>|null
     */
    private function slowest(array $log): ?array
    {
        $method = new ReflectionMethod(PullFromSource::class, 'slowestQuery');
        $method->setAccessible(true);

        /** @var array<string, mixed>|null $captured */
        $captured = $method->invoke(null, $log);

        return $captured;
    }
}
