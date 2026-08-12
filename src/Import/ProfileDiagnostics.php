<?php

declare(strict_types=1);

namespace Matchish\ScoutElasticSearch\Import;

use Illuminate\Support\Str;

/**
 * Turns one profiled chunk's raw metrics into DIAGNOSES.
 *
 * `--profile-samples` already produces every number an operator needs, but only
 * as a log line written inside a queue worker — so the numbers exist and nobody
 * reads them. This class is the interpretation step: it takes the metrics of a
 * single chunk and answers "what is wrong with this import", as machine codes
 * plus the few figures that justify each verdict. Rendering those codes into
 * sentences happens elsewhere; nothing here is user-facing.
 *
 * Deliberately pure — no container, no config, no clock, no I/O. It runs inside
 * a worker on the hot-ish path of a profiled chunk, and it has to be trivially
 * unit testable against hand-written metric arrays.
 *
 * DEFENSIVE BY CONTRACT: the metrics array is assembled by another class
 * (PullFromSource), travels through no schema, and may be produced by a
 * different release of this package than the one reading it. Every value is
 * therefore read through {@see number}, every key treated as optional, and no
 * denominator is used before it is proven non-zero. A drifted metrics array
 * must degrade to fewer findings, never to a division by zero.
 *
 * @phpstan-type Finding array{code: string, weight: float, data: array<string, scalar>}
 */
final class ProfileDiagnostics
{
    /**
     * A relation was lazy-loaded during the chunk: the classic N+1. The single
     * most valuable finding here, because the remedy is concrete — eager-load
     * the relation in makeAllSearchableUsing().
     */
    public const CODE_N_PLUS_ONE = 'n_plus_one';

    /** The chunk took at least as long as the job was allowed to run. */
    public const CODE_EXCEEDS_TIMEOUT = 'chunk_exceeds_timeout';

    /** The chunk burned half its timeout budget or more. */
    public const CODE_NEAR_TIMEOUT = 'chunk_near_timeout';

    /** shouldBeSearchable() issued more than one query, i.e. it queries per model. */
    public const CODE_FILTER_QUERIES = 'filter_queries';

    /** The database fetch owns most of the chunk. */
    public const CODE_FETCH_DOMINANT = 'fetch_dominant';

    /** Serializing plus the bulk request owns most of the chunk. */
    public const CODE_INDEX_DOMINANT = 'index_dominant';

    /** The average document is large enough to be worth trimming. */
    public const CODE_LARGE_PAYLOAD = 'large_payload';

    /**
     * Share of total_ms above which a single phase is called dominant. 0.6 and
     * not 0.5 so that a chunk split roughly evenly between fetching and
     * indexing — which is healthy — is reported as neither.
     */
    public const DOMINANT_SHARE = 0.6;

    /**
     * Average serialized document size, in KB, above which the payload itself is
     * worth a look. Documents this size usually mean whole relations, blobs or
     * casts landed in toSearchableArray().
     */
    public const LARGE_DOCUMENT_KB = 50.0;

    /**
     * The fraction of the timeout at which a chunk is called "near" it. Half a
     * budget spent is the point where normal variance between chunks can still
     * push a later one over.
     */
    public const NEAR_TIMEOUT_SHARE = 0.5;

    /**
     * Character budget for a finding's `slow_sql`.
     *
     * Deliberately harder than the capture side's budget (PullFromSource keeps
     * ~300) and deliberately its own constant rather than a reference to it: the
     * two limits answer different questions. Capture writes one log line inside a
     * worker; this value is JSON-encoded into the Redis run record, held for the
     * life of the run, and printed on a terminal underneath a finding sentence.
     * The head of a statement — the verb, the tables, the joins — is what names
     * the missing index, and it survives 200 characters.
     */
    public const SLOW_SQL_CHARS = 200;

    /**
     * Diagnose one profiled chunk.
     *
     * At most one finding per code, so a caller can safely fold the results of
     * thousands of chunks into one row per code.
     *
     * @param  array<string, mixed>  $metrics  One chunk's metrics, as PullFromSource::lastProfile() returns them.
     * @param  int|null  $jobTimeoutSeconds  The timeout the chunk actually ran under; null when unknown.
     * @return list<Finding>
     */
    public static function from(array $metrics, ?int $jobTimeoutSeconds): array
    {
        $findings = [];

        $fetchMs = self::number($metrics, 'fetch_ms');
        $indexMs = self::number($metrics, 'index_ms');
        $bulkMs = self::number($metrics, 'bulk_ms');
        $totalMs = self::number($metrics, 'total_ms');
        $payloadKb = self::number($metrics, 'payload_kb');
        $fetched = self::number($metrics, 'fetched');
        $indexed = self::number($metrics, 'indexed');
        $filterQueries = self::nestedNumber($metrics, 'queries', 'filter');

        $lazy = self::worstLazyLoad($metrics);
        if ($lazy !== null) {
            [$relation, $loads, $relations] = $lazy;

            $findings[] = self::finding(self::CODE_N_PLUS_ONE, (float) $loads, [
                'relation' => $relation,
                'loads' => $loads,
                'relations' => $relations,
            ]);
        }

        // A non-positive timeout is not a timeout: treating 0 as "known" would
        // make every chunk exceed it. Unknown is the honest reading, and it
        // suppresses both timeout rules rather than inventing a verdict.
        $timeout = $jobTimeoutSeconds !== null && $jobTimeoutSeconds > 0 ? $jobTimeoutSeconds : null;

        if ($timeout !== null) {
            $seconds = $totalMs / 1000;
            $data = [
                'total_ms' => round($totalMs, 1),
                'timeout' => $timeout,
                'pct' => self::pct($seconds, (float) $timeout),
            ];

            // Mutually exclusive on purpose: a chunk that already blew the
            // budget must not also be reported as merely approaching it.
            if ($seconds >= $timeout) {
                $findings[] = self::finding(self::CODE_EXCEEDS_TIMEOUT, $totalMs, $data);
            } elseif ($seconds >= $timeout * self::NEAR_TIMEOUT_SHARE) {
                $findings[] = self::finding(self::CODE_NEAR_TIMEOUT, $totalMs, $data);
            }
        }

        // One query for the whole chunk is the healthy shape (or none at all,
        // when shouldBeSearchable() only reads loaded attributes). More than one
        // means the filter itself is talking to the database per model.
        if ($filterQueries > 1) {
            $findings[] = self::finding(self::CODE_FILTER_QUERIES, $filterQueries, [
                'queries' => (int) $filterQueries,
                'fetched' => (int) $fetched,
            ]);
        }

        if ($totalMs > 0 && $fetchMs / $totalMs > self::DOMINANT_SHARE) {
            // The phase's slowest statement is appended (when one was captured)
            // because "the read owns 90% of the chunk" is the one finding where
            // an operator's very next question is "which query?" — and the
            // answer is already in the metrics. Appended, so the pre-existing
            // keys and their order stay exactly as they were for every consumer
            // that reads them positionally or asserts on them.
            $findings[] = self::finding(self::CODE_FETCH_DOMINANT, $fetchMs, array_merge([
                'fetch_ms' => round($fetchMs, 1),
                'total_ms' => round($totalMs, 1),
                'pct' => self::pct($fetchMs, $totalMs),
            ], self::slowQuery($metrics, 'fetch')));
        }

        if ($totalMs > 0 && $indexMs / $totalMs > self::DOMINANT_SHARE) {
            // bulk_ms rides along because it is what splits the two very
            // different causes: cluster round-trip time vs. CPU spent building
            // documents.
            //
            // The index phase's slowest statement matters for a third cause the
            // two timings cannot separate: a query issued while serializing, i.e.
            // a relation resolved inside toSearchableArray().
            $findings[] = self::finding(self::CODE_INDEX_DOMINANT, $indexMs, array_merge([
                'index_ms' => round($indexMs, 1),
                'total_ms' => round($totalMs, 1),
                'bulk_ms' => round($bulkMs, 1),
                'pct' => self::pct($indexMs, $totalMs),
            ], self::slowQuery($metrics, 'index')));
        }

        if ($indexed > 0) {
            $averageKb = $payloadKb / $indexed;

            if ($averageKb > self::LARGE_DOCUMENT_KB) {
                $findings[] = self::finding(self::CODE_LARGE_PAYLOAD, $averageKb, [
                    'avg_kb' => round($averageKb, 1),
                    'payload_kb' => round($payloadKb, 1),
                    'indexed' => (int) $indexed,
                ]);
            }
        }

        return $findings;
    }

    /**
     * The worst entry of the lazy_loads map: [relation, loads, distinct count].
     *
     * Null when nothing lazy-loaded, or when the map carries no entry with a
     * usable hit count. Entries whose count is not numeric are dropped rather
     * than counted as zero, so `relations` stays a count of relations we can
     * actually vouch for.
     *
     * @param  array<string, mixed>  $metrics
     * @return array{0: string, 1: int, 2: int}|null
     */
    private static function worstLazyLoad(array $metrics): ?array
    {
        $lazyLoads = $metrics['lazy_loads'] ?? null;

        if (! is_array($lazyLoads) || $lazyLoads === []) {
            return null;
        }

        $worstRelation = null;
        $worstLoads = -1.0;
        $relations = 0;

        foreach ($lazyLoads as $relation => $loads) {
            if (! is_numeric($loads)) {
                continue;
            }

            $relations++;
            $loads = (float) $loads;

            // Strict >, so the first relation reaching the worst count wins and
            // the answer does not depend on map ordering.
            if ($loads > $worstLoads) {
                $worstLoads = $loads;
                // Cast because PHP silently turns an integer-like array key
                // into an int, and this value is reported as a string.
                $worstRelation = (string) $relation;
            }
        }

        if ($worstRelation === null) {
            return null;
        }

        return [$worstRelation, (int) max(0.0, $worstLoads), $relations];
    }

    /**
     * One phase's slowest statement as finding data: `['slow_sql' => string,
     * 'slow_ms' => float]`, or an empty array when the phase captured nothing.
     *
     * Empty and not `['slow_sql' => null, ...]` on purpose. `data` is typed
     * `array<string, scalar>` — a contract the store (which drops non-scalars
     * silently) and the renderer both rely on — so "absent" has to be expressed
     * by absence. Renderers therefore test for the key, never for its emptiness.
     *
     * PRIVACY: the value copied here is PullFromSource's `sql`, which is Laravel's
     * query string with `?` placeholders. The sibling `bindings` array is never
     * captured upstream and must never be plumbed through here: this string is
     * JSON-encoded into the Redis run record and printed to a terminal, so a
     * bound value reaching it would leak real row data (emails, names, tokens)
     * into two places that neither redact nor expire it.
     *
     * Defensive like the rest of the class: `slow_query` may be missing entirely
     * (metrics from a release before capture existed), not an array, or carry a
     * phase entry that is null or malformed. Every one of those degrades to "no
     * slow query", which costs the operator a hint and nothing else.
     *
     * @param  array<string, mixed>  $metrics
     * @param  string  $phase  'fetch' or 'index'
     * @return array<string, scalar>
     */
    private static function slowQuery(array $metrics, string $phase): array
    {
        $slowQueries = $metrics['slow_query'] ?? null;

        if (! is_array($slowQueries)) {
            return [];
        }

        $entry = $slowQueries[$phase] ?? null;

        if (! is_array($entry)) {
            return [];
        }

        $sql = $entry['sql'] ?? null;

        // A non-string, or a statement that compacted down to nothing, is not
        // something an operator can paste into EXPLAIN. Reporting `slow_ms`
        // without the SQL would be a duration with no subject — the phase timing
        // already says that — so both keys are dropped together.
        if (! is_string($sql) || trim($sql) === '') {
            return [];
        }

        return [
            // Str::limit truncates by character, not by byte, which matters
            // because the collapsed-placeholder marker upstream uses a multibyte
            // '×': a byte-wise cut could land inside it and emit broken UTF-8
            // into a JSON encode that would then fail outright.
            'slow_sql' => Str::limit(trim($sql), self::SLOW_SQL_CHARS),
            'slow_ms' => round(self::number($entry, 'ms'), 1),
        ];
    }

    /**
     * @param  array<string, scalar>  $data
     * @return Finding
     */
    private static function finding(string $code, float $weight, array $data): array
    {
        return [
            'code' => $code,
            'weight' => $weight,
            'data' => $data,
        ];
    }

    /**
     * $part as a percentage of $whole, or 0.0 when there is no whole to divide
     * by. Every percentage in this class goes through here precisely so no rule
     * has to remember to guard its own denominator.
     */
    private static function pct(float $part, float $whole): float
    {
        if ($whole <= 0) {
            return 0.0;
        }

        return round($part / $whole * 100, 1);
    }

    /**
     * A metric read as a number: 0.0 for missing keys, nulls, arrays, objects
     * and non-numeric strings.
     *
     * @param  array<array-key, mixed>  $metrics
     */
    private static function number(array $metrics, string $key): float
    {
        $value = $metrics[$key] ?? null;

        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * The same read, one level down (the `queries` sub-map), tolerating a
     * `queries` that is missing or not an array at all.
     *
     * @param  array<string, mixed>  $metrics
     */
    private static function nestedNumber(array $metrics, string $key, string $inner): float
    {
        $nested = $metrics[$key] ?? null;

        return is_array($nested) ? self::number($nested, $inner) : 0.0;
    }
}
