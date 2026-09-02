<?php

namespace Matchish\ScoutElasticSearch\Jobs\Stages;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Matchish\ScoutElasticSearch\Engines\ElasticSearchEngine;
use Matchish\ScoutElasticSearch\Searchable\ImportSource;
use OpenSearch\Client;

/**
 * @internal
 */
final class PullFromSource implements StageInterface
{
    /**
     * Characters of SQL kept for a phase's slowest query before it is cut off
     * with an ellipsis. Bounded because this string lands in a log line (and,
     * further downstream, in a JSON run record) and an untruncated statement
     * from a wide model with a dozen joins can run to several kilobytes.
     *
     * 300 is chosen to be long enough to reach past the select list into the
     * FROM / JOIN / WHERE that actually identify the missing index.
     */
    public const SLOW_QUERY_SQL_CHARS = 300;

    /**
     * How many consecutive `?` placeholders make a "run" worth collapsing.
     *
     * Three, not two: `in (?, ?)` is already readable and collapsing it would
     * lose more than it saves, while `in (?, ?, ?, ...)` over a 1000-row eager
     * load is pure noise that would otherwise consume the whole budget above.
     */
    private const PLACEHOLDER_RUN_MIN = 3;

    /**
     * @var ImportSource
     */
    private $source;

    /**
     * When true, each chunk logs a timing breakdown (fetch / filter / index) so
     * a slow import can be attributed to the DB fetch, the shouldBeSearchable
     * filter, or the Elasticsearch bulk request. Off by default so a normal run
     * stays silent.
     *
     * This is a per-chunk decision, baked in when the stage is built: with
     * --profile-samples only the sampled chunks carry true (see
     * {@see profileStride}), so a 100k-chunk plan can be diagnosed without 100k
     * log lines and 100k extra serialization passes.
     *
     * @var bool
     */
    private $profile;

    /**
     * The metric breakdown of the last profiled chunk this instance handled, or
     * null when it never profiled anything.
     *
     * Exposed (see {@see lastProfile}) rather than published from here on
     * purpose: this stage knows nothing about the run token or the run record,
     * and giving it either would make it a coordination participant. The caller
     * owns both, so the caller decides what to do with the numbers.
     *
     * @var array<string, mixed>|null
     */
    private $lastProfile = null;

    /**
     * The index this stage writes to, or null to write wherever the models'
     * `searchableAs()` alias points — which is what a real import does, since the
     * whole point of the alias is that the new index is already behind it.
     *
     * Only --probe sets it: a probe indexes into a standalone index that is never
     * added to the alias and is deleted afterwards, so it must not go through the
     * alias or the application's own concurrent writes would follow it there and
     * be destroyed with it.
     *
     * @var string|null
     */
    private $targetIndex;

    /**
     * @param  ImportSource  $source
     * @param  bool  $profile
     * @param  string|null  $targetIndex
     */
    public function __construct(ImportSource $source, bool $profile = false, ?string $targetIndex = null)
    {
        $this->source = $source;
        $this->profile = $profile;
        $this->targetIndex = $targetIndex;
    }

    public function handle(Client $elasticsearch = null): void
    {
        if ($this->profile) {
            $this->handleProfiled();

            return;
        }

        $results = $this->source->get()->filter(function (Model $item): bool {
            return $this->shouldBeSearchable($item);
        });

        $this->updateSearchIndex($results);
    }

    /**
     * Same work as {@see handle}, wrapped in per-phase timers and a structured
     * log line. Kept separate so the hot path carries no timing overhead.
     *
     * While profiling, lazy loading is flagged (not thrown) so any relation a
     * model resolves on demand — typically inside toSearchableArray during the
     * index phase — is captured by class::relation with a hit count, and each
     * phase's DB query count is recorded. Together these pinpoint an N+1: the
     * phase whose query count balloons, and the exact relation to eager-load in
     * makeAllSearchableUsing.
     *
     * Each phase also records its slowest statement (SQL only, see
     * {@see slowestQuery}), because a query count answers "how many" but never
     * "which one" — and on a slow fetch, which one is the whole question.
     */
    private function handleProfiled(): void
    {
        // Cleared up front so a run that throws mid-chunk cannot leave the
        // previous chunk's numbers behind for a caller to read and attribute to
        // this one.
        $this->lastProfile = null;

        $lazyLoads = [];
        $wasPreventing = Model::preventsLazyLoading();
        Model::handleLazyLoadingViolationUsing(function ($model, $relation) use (&$lazyLoads) {
            $key = get_class($model).'::'.$relation;
            $lazyLoads[$key] = ($lazyLoads[$key] ?? 0) + 1;
        });
        // Flagging (not throwing) means the relation still loads, so the import
        // stays correct; we only observe that it happened.
        Model::preventLazyLoading();

        // Returns [result, query count, slowest query]. The query log was already
        // being enabled, counted and thrown away here; the slowest entry is read
        // off the same array before it is flushed, so a phase's worst statement
        // costs one extra pass over data that already exists in memory. The count
        // alone tells an operator "the fetch ran 5 queries and took 258 seconds"
        // without saying WHICH of the 5 — that is the gap this closes.
        $countQueries = function (callable $work): array {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $result = $work();
            $log = DB::getQueryLog();
            $queries = count($log);
            $slow = self::slowestQuery($log);
            DB::flushQueryLog();

            return [$result, $queries, $slow];
        };

        try {
            $t0 = microtime(true);
            [$fetched, $fetchQueries, $fetchSlow] = $countQueries(function () {
                return $this->source->get();
            });
            $t1 = microtime(true);

            [$results, $filterQueries, $filterSlow] = $countQueries(function () use ($fetched) {
                return $fetched->filter(function (Model $item): bool {
                    return $this->shouldBeSearchable($item);
                });
            });
            $t2 = microtime(true);

            $indexed = 0;
            $serializeMs = 0.0;
            $bulkMs = 0.0;
            $bytes = 0;
            [, $indexQueries, $indexSlow] = $countQueries(function () use ($results, &$indexed, &$serializeMs, &$bulkMs, &$bytes) {
                if ($results->isEmpty()) {
                    return;
                }

                // Serialization pass on its own: build each document as it will
                // be sent and measure the CPU cost + payload size. update()
                // repeats this work internally; on a profiled chunk the extra
                // pass is an acceptable diagnostic cost. It is also the reason
                // --profile-samples exists: paying it on every chunk of a
                // 100k-chunk plan distorts the very timings being measured.
                $s0 = microtime(true);
                foreach ($results as $model) {
                    $bytes += strlen((string) json_encode($model->toSearchableArray()));
                }
                $serializeMs = (microtime(true) - $s0) * 1000;

                // The bulk request itself. This still re-serializes internally,
                // so bulk_ms is an upper bound on the network/cluster time —
                // subtract serialize_ms for the real round-trip estimate.
                $b0 = microtime(true);
                $this->updateSearchIndex($results);
                $bulkMs = (microtime(true) - $b0) * 1000;

                $indexed = $results->count();
            });
            $t3 = microtime(true);
        } finally {
            DB::disableQueryLog();
            Model::preventLazyLoading($wasPreventing);
        }

        $ms = function (float $from, float $to): float {
            return round(($to - $from) * 1000, 1);
        };

        // Assembled once and used twice — the log line for whoever tails the
        // worker, and lastProfile() for whoever holds the run token. Two copies
        // would eventually disagree, and a diagnosis drawn from a stale copy is
        // worse than no diagnosis.
        $profile = [
            'source' => get_class($this->source),
            'fetched' => $fetched->count(),
            'indexed' => $indexed,
            'fetch_ms' => $ms($t0, $t1),
            'filter_ms' => $ms($t1, $t2),
            'index_ms' => $ms($t2, $t3),
            // Breakdown of index_ms: CPU to build the documents vs. the bulk
            // request (which includes its own serialization — see above).
            'serialize_ms' => round($serializeMs, 1),
            'bulk_ms' => round($bulkMs, 1),
            'payload_kb' => round($bytes / 1024, 1),
            'total_ms' => $ms($t0, $t3),
            'queries' => [
                'fetch' => $fetchQueries,
                'filter' => $filterQueries,
                'index' => $indexQueries,
            ],
            // The slowest statement of each phase, or null when the phase issued
            // no query at all. This is what turns "fetch took 258s over 5
            // queries" into a statement an operator can paste into EXPLAIN.
            // SQL ONLY, never bindings — see slowestQuery().
            'slow_query' => [
                'fetch' => $fetchSlow,
                'filter' => $filterSlow,
                'index' => $indexSlow,
            ],
            // Empty means nothing lazy-loaded: the index time is genuine
            // serialization + the Elasticsearch bulk request, not an N+1.
            'lazy_loads' => $lazyLoads,
        ];

        $this->lastProfile = $profile;

        logger()->info('scout:import chunk profile', $profile);
    }

    /**
     * The slowest entry of one phase's query log, as
     * ['sql' => string, 'ms' => float], or null when the phase logged nothing.
     *
     * PRIVACY — DELIBERATE AND LOAD-BEARING: only the `query` string is read,
     * which Laravel stores with `?` placeholders, and the sibling `bindings`
     * array is never touched. Bindings hold real row data (emails, names, API
     * tokens) and this value travels into the application log and into the Redis
     * run record, where it is neither redacted nor short-lived. Anyone extending
     * this must keep it placeholders-only.
     *
     * Defensive about the log's shape on purpose: the query log is owned by the
     * framework, not by us, and its entries have gained and lost keys across
     * releases. A drifted entry degrades to '' / 0.0 rather than throwing inside
     * a diagnostic that only exists to explain a slow import.
     *
     * @param  array<array-key, mixed>  $log  DB::getQueryLog() for a single phase
     * @return array{sql: string, ms: float}|null
     */
    private static function slowestQuery(array $log): ?array
    {
        $slowest = null;
        $slowestMs = null;

        foreach ($log as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $time = $entry['time'] ?? null;
            $ms = is_numeric($time) ? (float) $time : 0.0;

            // Strictly greater, so the FIRST of several equally slow statements
            // wins: on a chunk whose queries are all the same cost, the earliest
            // one is the one an operator can reason about in isolation.
            if ($slowestMs !== null && $ms <= $slowestMs) {
                continue;
            }

            $sql = $entry['query'] ?? null;

            $slowest = [
                'sql' => self::compactSql(is_string($sql) ? $sql : ''),
                'ms' => round($ms, 1),
            ];
            $slowestMs = $ms;
        }

        return $slowest;
    }

    /**
     * One log line's worth of SQL: single-spaced, placeholder runs collapsed,
     * then truncated.
     *
     * The order matters. An eager load over a 1000-row chunk produces
     * `where "id" in (?, ?, ?, ... x1000)`, which is ~3000 characters of nothing
     * before any truncation limit is reached — truncating first would return a
     * wall of question marks and cut off the table names and joins that are the
     * entire diagnostic value. So runs of {@see PLACEHOLDER_RUN_MIN} or more
     * comma-separated placeholders collapse to a marker naming the count
     * (`in (?×1000)`), which both shortens the statement and preserves the fact
     * that it was a 1000-key IN — itself a finding.
     *
     * Whitespace is normalised first so that placeholders split across newlines
     * still read as one run, and so one query stays one log line.
     */
    private static function compactSql(string $sql): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($sql));
        if (! is_string($normalized)) {
            $normalized = $sql;
        }

        $collapsed = preg_replace_callback(
            '/\?(?:\s*,\s*\?){'.(self::PLACEHOLDER_RUN_MIN - 1).',}/',
            function (array $matches): string {
                return '?×'.substr_count($matches[0], '?');
            },
            $normalized
        );
        if (! is_string($collapsed)) {
            $collapsed = $normalized;
        }

        return Str::limit($collapsed, self::SLOW_QUERY_SQL_CHARS);
    }

    /**
     * The metrics of the chunk this instance just profiled, or null when it did
     * not profile (profiling off, or not a sampled chunk).
     *
     * Keys: source, fetched, indexed, fetch_ms, filter_ms, index_ms,
     * serialize_ms, bulk_ms, payload_kb, total_ms, queries (fetch|filter|index),
     * slow_query (fetch|filter|index => ['sql' => string, 'ms' => float]|null)
     * and lazy_loads ("Class::relation" => hit count).
     *
     * @return array<string, mixed>|null
     */
    public function lastProfile(): ?array
    {
        return $this->lastProfile;
    }

    public function estimate(): int
    {
        return 1;
    }

    public function title(): string
    {
        return 'Indexing...';
    }

    /**
     * How often a chunk should be profiled: 0 = never profile, 1 = profile every
     * chunk, N > 1 = profile every Nth chunk.
     *
     * $samples is a target *number of samples*, not a stride, because the
     * operator cannot know the chunk count in advance — it is derived from the
     * MIN/MAX key span, not the row count. Asking for more samples than there are
     * chunks degrades to "every chunk" rather than dividing by zero or, worse,
     * profiling nothing.
     *
     * `--profile-samples=all` is not a special case here: the command resolves
     * `all` to PHP_INT_MAX, which intdiv()s to 0 and so lands on the same
     * max(1, ...) = "every chunk" the paragraph above describes.
     *
     * @param  int  $total  chunks in the plan
     * @param  bool  $profile  whether this run profiles at all — the internal
     *                         bool the jobs carry, which the command derives
     *                         from the presence of a usable --profile-samples
     *                         target rather than from a flag of its own
     * @param  int|null  $samples  the --profile-samples target, null when absent
     */
    public static function profileStride(int $total, bool $profile, ?int $samples): int
    {
        $sampled = $samples !== null && $samples > 0;

        // Neither profiling nor a sample target: profiling is off entirely.
        if (! $profile && ! $sampled) {
            return 0;
        }

        // Profiling is on but nothing usable to sample by (no target, a
        // nonsensical count, or an unplanned/empty total), so profile every
        // chunk rather than dividing by zero or silently profiling nothing.
        if (! $sampled || $total <= 0) {
            return 1;
        }

        return max(1, intdiv($total, (int) $samples));
    }

    /**
     * Whether the chunk at this position in the plan is one of the sampled ones.
     *
     * Deciding by position (and not, say, at random) is what makes the choice
     * reproducible: any hop, and the reaper, can recompute the same stride and
     * arrive at the same answer for the same chunk id.
     */
    public static function shouldProfile(int $chunkId, int $stride): bool
    {
        return $stride > 0 && $chunkId % $stride === 0;
    }

    /**
     * @param  ImportSource  $source
     * @param  bool  $profile
     * @param  int|null  $profileSamples
     * @return Collection<int, self>
     */
    public static function chunked(ImportSource $source, bool $profile = false, ?int $profileSamples = null): Collection
    {
        $chunks = $source->chunked();
        $stride = self::profileStride($chunks->count(), $profile, $profileSamples);

        // The sampling decision keys off the chunk's *position* in the plan, not
        // the collection key, so it matches the chunk ids the run record accounts
        // by (and the ones the fan-out and the reaper recompute from).
        $position = 0;

        return $chunks->map(function (ImportSource $chunk) use ($stride, &$position): self {
            return new static($chunk, self::shouldProfile($position++, $stride));
        });
    }

    private function shouldBeSearchable(Model $model): bool
    {
        return (bool) $model->{'shouldBeSearchable'}();
    }

    /**
     * @param  EloquentCollection<int, Model>  $models
     */
    private function updateSearchIndex(EloquentCollection $models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        $engine = $models->first()->{'searchableUsing'}();

        // Redirection is only possible on our own engine, and only it knows how
        // to triage a bulk error. Any other engine (or no target index at all)
        // takes the unchanged contract path, so the normal import is byte-for-byte
        // the request it has always been.
        if (null !== $this->targetIndex && $engine instanceof ElasticSearchEngine) {
            $engine->updateInto($models, $this->targetIndex);

            return;
        }

        $engine->update($models);
    }
}
