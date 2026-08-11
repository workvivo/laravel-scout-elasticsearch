<?php

declare(strict_types=1);

namespace Matchish\ScoutElasticSearch\Import;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Matchish\ScoutElasticSearch\ElasticSearch\Index;
use Matchish\ScoutElasticSearch\ElasticSearch\Params\Indices\Create;
use Matchish\ScoutElasticSearch\ImportLock;
use Matchish\ScoutElasticSearch\Jobs\RollbackImportJob;
use Matchish\ScoutElasticSearch\Jobs\Stages\PullFromSource;
use Matchish\ScoutElasticSearch\Searchable\DefaultImportSource;
use Matchish\ScoutElasticSearch\Searchable\ImportSource;
use OpenSearch\Client;
use Throwable;

/**
 * Answers the two questions an operator actually has about a large import —
 * "how long does one chunk take?" and "is there an N+1 in
 * toSearchableArray()?" — in seconds instead of hours.
 *
 * `--profile-samples` can already answer both, but only from inside a real
 * import — even `--profile-samples=5` still runs the WHOLE import to reach those
 * five chunks, and reports only into a worker's log. (Sampling bounds the
 * diagnostic cost; it never shortens the run.) This class measures a HANDFUL of
 * chunks, in-process and synchronously, and throws the result away: no queue, no
 * workers, no run record, no import lease, no alias change.
 *
 * THE INDEX IS THE WHOLE DESIGN. {@see \Matchish\ScoutElasticSearch\ElasticSearch\Params\Bulk}
 * indexes into `searchableAs()`, i.e. the ALIAS, so a probe that reused the
 * normal import machinery would either write into the live index or — far worse
 * — go through {@see \Matchish\ScoutElasticSearch\Jobs\Stages\CreateWriteIndex},
 * which makes the new index the alias's `is_write_index` and would divert the
 * APPLICATION's own concurrent `searchable()` writes into an index this class
 * then DELETES. A real import survives that only because it ends by promoting
 * the index; a probe never promotes anything. So the probe writes to a
 * STANDALONE index that is never aliased (see {@see probeIndexName}), reached
 * through PullFromSource's target-index seam, and deletes exactly that one index
 * on the way out.
 *
 * Side-effect budget, in full: create one index, write sampled chunks into it,
 * delete it. It dispatches nothing, and it neither takes nor releases the import
 * lease — a probe must not block a real import, nor be blocked by one. It does
 * NOTICE a running import and says so, because a concurrent import contends for
 * the same database and cluster and skews every number here.
 *
 * Numbers come from the production measurement path verbatim
 * ({@see PullFromSource::handleProfiled}) and are interpreted by the production
 * rules ({@see ProfileDiagnostics}), so what the probe reports is what a real
 * import would report.
 *
 * @phpstan-type Sample array{chunk_id: int, metrics: array<string, mixed>|null, error: string|null}
 * @phpstan-type ProbeFinding array{code: string, count: int, weight: float, data: array<string, scalar>}
 * @phpstan-type Aggregates array{measured: int, failed: int, fetched: int, indexed: int, min_ms: float, median_ms: float, mean_ms: float, max_ms: float, mean_production_ms: float, overhead_ms: float}
 * @phpstan-type Estimate array{chunks: int, measured: int, mean_seconds: float, measured_mean_seconds: float, overhead_seconds: float, serial_seconds: float}
 * @phpstan-type Plan array{total: int, chunk_size: int|null, resolve: callable(int): (ImportSource|null)}
 * @phpstan-type ProbeReport array{searchable: string, index: string, created: bool, torn_down: bool, ok: bool, error: string|null, total_chunks: int, chunk_size: int|null, sampled: list<int>, samples: list<Sample>, aggregates: Aggregates, findings: list<ProbeFinding>, estimate: Estimate|null, warnings: list<string>}
 */
final class ImportProbe
{
    /**
     * @var ImportSource
     */
    private $source;

    public function __construct(ImportSource $source)
    {
        $this->source = $source;
    }

    /**
     * Measure $samples chunks and report.
     *
     * Never throws: everything an operator could want to know — including that
     * the probe itself could not run, and including a probe index that is still
     * lying around because its deletion failed — travels back in the report.
     * `ok` is the only thing a caller has to branch on; findings are diagnoses
     * about a probe that ran, so they say nothing about `ok`.
     *
     * $jobTimeoutSeconds is the timeout a real chunk would run under (null when
     * unknown), passed straight to {@see ProfileDiagnostics} so "this chunk is
     * near its timeout" means here exactly what it means during an import.
     *
     * @return ProbeReport
     */
    public function run(int $samples, ?int $jobTimeoutSeconds): array
    {
        $index = $this->probeIndex();

        $report = [
            'searchable' => $this->source->searchableAs(),
            'index' => $index->name(),
            'created' => false,
            'torn_down' => false,
            'ok' => false,
            'error' => null,
            'total_chunks' => 0,
            'chunk_size' => null,
            'sampled' => [],
            'samples' => [],
            'aggregates' => self::noAggregates(),
            'findings' => [],
            'estimate' => null,
            'warnings' => $this->concurrentImportWarnings(),
        ];

        // Planning first, because it is read-only and it is what the
        // extrapolation rests on: no plan means no chunk count to multiply by,
        // and nothing worth creating an index for. Failing here also means
        // there is nothing to tear down yet.
        try {
            $plan = $this->plan();
        } catch (Throwable $e) {
            $report['error'] = self::describe($e);

            return $report;
        }

        $report['total_chunks'] = $plan['total'];
        $report['chunk_size'] = $plan['chunk_size'];

        // An empty plan is a successful probe with nothing to say: no index is
        // created, so none has to be deleted.
        if ($plan['total'] === 0) {
            $report['ok'] = true;

            return $report;
        }

        $report['sampled'] = self::sampleIds($plan['total'], $samples);

        try {
            $this->createProbeIndex($index);
        } catch (Throwable $e) {
            $report['error'] = self::describe($e);

            return $report;
        }

        // From here on an index exists, so from here on the finally below owns
        // deleting it.
        $report['created'] = true;
        $report['ok'] = true;
        $resolve = $plan['resolve'];

        try {
            foreach ($report['sampled'] as $chunkId) {
                // Per chunk, so one pathological chunk (a serialization error, a
                // model whose relation blows up) costs its own row and nothing
                // else: the other samples' numbers are the reason the operator
                // ran this.
                try {
                    $chunkSource = $resolve($chunkId);

                    if ($chunkSource === null) {
                        $report['samples'][] = self::failedSample($chunkId, self::message('probe_chunk_missing'));

                        continue;
                    }

                    // Profiled (true) and redirected (the probe index): the
                    // profiled path is the production measurement code, and the
                    // redirect is what keeps the write off the alias.
                    $stage = new PullFromSource($chunkSource, true, $index->name());
                    $stage->handle();

                    $metrics = $stage->lastProfile();

                    $report['samples'][] = [
                        'chunk_id' => $chunkId,
                        'metrics' => $metrics,
                        'error' => $metrics === null ? self::message('probe_chunk_unmeasured') : null,
                    ];
                } catch (Throwable $e) {
                    $report['samples'][] = self::failedSample($chunkId, self::describe($e));
                }
            }
        } finally {
            // THE ONE GUARANTEE THIS CLASS MUST NOT BREAK. An undeleted probe
            // index is invisible (never aliased, nothing queries it) but it
            // costs disk forever, so it is deleted on every exit path from the
            // sampling loop — normal return, an escaping Throwable, or a
            // deletion that itself fails, which becomes a warning naming the
            // index so an operator can finish the job by hand.
            $warning = $this->removeProbeIndex($index);

            if ($warning === null) {
                $report['torn_down'] = true;
            } else {
                $report['warnings'][] = $warning;
            }
        }

        $report['aggregates'] = self::aggregate($report['samples']);
        $report['findings'] = self::diagnose($report['samples'], $jobTimeoutSeconds);
        $report['estimate'] = self::estimate($plan['total'], $report['aggregates']);

        return $report;
    }

    /**
     * Which chunk ids to measure: spread across the plan with the SAME stride
     * math a `--profile-samples` import uses ({@see PullFromSource::profileStride}
     * and {@see PullFromSource::shouldProfile}), so the probe measures precisely
     * the chunks that production sampling would have measured.
     *
     * Deliberately not the first $samples chunks: chunk 0 sits on the lowest
     * keys with the coldest caches, and a run of consecutive early chunks says
     * nothing about the heavy tail that decides whether a chunk trips its
     * timeout. A plan smaller than the sample target is simply measured whole.
     *
     * @return list<int>
     */
    public static function sampleIds(int $total, int $samples): array
    {
        if ($total <= 0) {
            return [];
        }

        // A non-positive target would ask for nothing at all; the command
        // already substitutes its default, and this keeps the class honest when
        // called directly.
        $samples = min(max(1, $samples), $total);
        $stride = PullFromSource::profileStride($total, true, $samples);

        $ids = [];
        for ($i = 0; $i < $samples; $i++) {
            $id = $i * $stride;

            if ($id > $total - 1) {
                break;
            }

            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * Plan the chunks: the total (which the extrapolation multiplies by), the
     * chunk size when it can be read off the plan, and a resolver that builds
     * the source for one chunk id.
     *
     * The built-in source is planned from bounds — plain scalars, so a
     * million-chunk plan costs a list of numbers and a chunk source is rebuilt
     * only for the few ids actually sampled, exactly as the paged fan-out does.
     * A custom ImportSource keeps its own chunking, so there it falls back to
     * chunked() and indexes into that.
     *
     * @return Plan
     */
    private function plan(): array
    {
        $source = $this->source;

        if ($source instanceof DefaultImportSource) {
            $bounds = $source->chunkBounds()->values();

            return [
                'total' => $bounds->count(),
                'chunk_size' => self::plannedSpan($bounds),
                'resolve' => function (int $chunkId) use ($source, $bounds): ?ImportSource {
                    $pair = $bounds->get($chunkId);

                    return $pair === null ? null : $source->withChunkBounds($pair[0], $pair[1]);
                },
            ];
        }

        $chunks = $source->chunked()->values();

        return [
            'total' => $chunks->count(),
            // Unknowable for a custom source: it owns its own chunking and
            // nothing here may assume its boundaries mean rows.
            'chunk_size' => null,
            'resolve' => function (int $chunkId) use ($chunks): ?ImportSource {
                return $chunks->get($chunkId);
            },
        ];
    }

    /**
     * Rows per chunk as the PLAN sees it, read off the second boundary pair.
     *
     * Bounds are (exclusive start, inclusive end], so `end - start` is the
     * planned key span of that chunk — and for the arithmetic planner that span
     * IS the chunk size. The second pair specifically: the first has no lower
     * bound at all, and the last is clamped to MAX(key) and so is usually
     * shorter than a chunk. Non-numeric keys (UUID/ULID plans) and plans too
     * short to have a middle pair have no answer, and null is reported rather
     * than a guess.
     *
     * @param  Collection<int, array{0:mixed, 1:mixed}>  $bounds
     */
    private static function plannedSpan(Collection $bounds): ?int
    {
        if ($bounds->count() < 3) {
            return null;
        }

        $pair = $bounds->get(1);

        if ($pair === null || ! is_numeric($pair[0]) || ! is_numeric($pair[1])) {
            return null;
        }

        $span = (int) $pair[1] - (int) $pair[0];

        return $span > 0 ? $span : null;
    }

    /**
     * The throwaway index this probe writes to.
     *
     * Naming carries two obligations. It MUST start with `searchableAs().'_'`,
     * because that prefix is the guard
     * {@see RollbackImportJob::removeUnpromotedIndex} uses to refuse to delete
     * anything that is not this model's own unpromoted index — the probe reuses
     * that guarded delete instead of calling the client itself. And it says
     * `probe` out loud, so an operator who finds one in `_cat/indices` after an
     * interrupted run knows what it is. time() + randomness keeps two
     * simultaneous probes of the same model in separate indices; lower-cased
     * because index names must be.
     *
     * Settings and mappings come from {@see Index::fromSource}, so the probe
     * measures bulk requests against the same shard count, refresh interval and
     * mappings a real write index would have — a probe against default settings
     * would time a cluster nobody is going to run. Only fromSource's generated
     * NAME is dropped, and no alias is ever added to what comes back.
     */
    private function probeIndex(): Index
    {
        $template = Index::fromSource($this->source)->config();

        $settings = $template['settings'] ?? null;
        $mappings = $template['mappings'] ?? null;

        return new Index(
            $this->probeIndexName(),
            is_array($settings) ? $settings : null,
            is_array($mappings) ? $mappings : null
        );
    }

    private function probeIndexName(): string
    {
        return $this->source->searchableAs().'_probe_'.time().'_'.Str::lower(Str::random(6));
    }

    /**
     * Create the probe index — {@see \Matchish\ScoutElasticSearch\Jobs\Stages\CreateWriteIndex}'s
     * creation semantics with its alias step deliberately left out, which is the
     * one difference that makes a probe safe (see the class docblock).
     */
    private function createProbeIndex(Index $index): void
    {
        $params = new Create($index->name(), $index->config());

        app(Client::class)->indices()->create($params->toArray());
    }

    /**
     * Delete the probe index. Returns null on success, or the warning to report
     * when it could not be deleted.
     *
     * No owner is passed on purpose: the lease check inside
     * removeUnpromotedIndex() exists so a run that lost its lease cannot delete
     * an index that now belongs to someone else, and a probe holds no lease at
     * all — passing one would make deleting our own throwaway index depend on a
     * lock we deliberately never took. The check that actually protects
     * anything, the `searchableAs().'_'` prefix guard, is unconditional there.
     */
    private function removeProbeIndex(Index $index): ?string
    {
        try {
            RollbackImportJob::removeUnpromotedIndex($this->source, $index, null);

            return null;
        } catch (Throwable $e) {
            return self::message('probe_warning_teardown_failed', [
                'index' => $index->name(),
                'reason' => self::describe($e),
            ]);
        }
    }

    /**
     * Warn when an import of this model looks like it is running: it competes
     * for the same rows and the same cluster, so every timing below is inflated
     * by an amount nobody can subtract.
     *
     * Presence of the lease key is all that can be observed —
     * {@see ImportLock::isHeldBy} needs the owner token, which is intentionally
     * never handed out. And a cache store that cannot answer costs the operator
     * this warning, never the probe: a caveat is not worth failing a
     * measurement that is otherwise perfectly good.
     *
     * @return list<string>
     */
    private function concurrentImportWarnings(): array
    {
        try {
            $key = ImportLock::keyFor($this->source->searchableAs());

            if (Cache::get($key) === null) {
                return [];
            }

            return [self::message('probe_warning_import_running', [
                'searchable' => $this->source->searchableAs(),
                'key' => $key,
            ])];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Fold the measured chunks into the few numbers that describe them: the
     * spread (min/median/max) because chunk cost is never uniform, and the mean
     * because it is the only average an extrapolation may use.
     *
     * @param  list<Sample>  $samples
     * @return Aggregates
     */
    private static function aggregate(array $samples): array
    {
        $totals = [];
        $productions = [];
        $fetched = 0;
        $indexed = 0;
        $failed = 0;

        foreach ($samples as $sample) {
            $metrics = $sample['metrics'];

            if ($metrics === null) {
                $failed++;

                continue;
            }

            $total = self::number($metrics, 'total_ms');
            $totals[] = $total;

            // What a production chunk would have cost. Profiling serializes every
            // document one extra time purely to separate serialize_ms from
            // bulk_ms (see PullFromSource::handleProfiled), and that pass is
            // inside total_ms — so total_ms overstates an unprofiled chunk by
            // roughly serialize_ms. Multiplying the measured mean by the chunk
            // count would carry that overhead into the estimate hundreds of
            // times over, which is exactly the number an operator sizes their
            // timeouts and their maintenance window from. Clamped at zero: a
            // serialize_ms above total_ms can only be a drifted payload, and a
            // negative chunk cost is never the more useful answer.
            $productions[] = max(0.0, $total - self::number($metrics, 'serialize_ms'));

            $fetched += (int) self::number($metrics, 'fetched');
            $indexed += (int) self::number($metrics, 'indexed');
        }

        if ($totals === []) {
            $aggregates = self::noAggregates();
            $aggregates['failed'] = $failed;

            return $aggregates;
        }

        sort($totals);
        $count = count($totals);
        $meanMs = array_sum($totals) / $count;
        $meanProductionMs = array_sum($productions) / count($productions);

        return [
            'measured' => $count,
            'failed' => $failed,
            'fetched' => $fetched,
            'indexed' => $indexed,
            'min_ms' => round($totals[0], 1),
            'median_ms' => round(self::median($totals), 1),
            'mean_ms' => round($meanMs, 1),
            'max_ms' => round($totals[$count - 1], 1),
            'mean_production_ms' => round($meanProductionMs, 1),
            'overhead_ms' => round(max(0.0, $meanMs - $meanProductionMs), 1),
        ];
    }

    /**
     * @return Aggregates
     */
    private static function noAggregates(): array
    {
        return [
            'measured' => 0,
            'failed' => 0,
            'fetched' => 0,
            'indexed' => 0,
            'min_ms' => 0.0,
            'median_ms' => 0.0,
            'mean_ms' => 0.0,
            'max_ms' => 0.0,
            'mean_production_ms' => 0.0,
            'overhead_ms' => 0.0,
        ];
    }

    /**
     * @param  list<float>  $sorted  Non-empty, ascending.
     */
    private static function median(array $sorted): float
    {
        $count = count($sorted);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? $sorted[$middle]
            : ($sorted[$middle - 1] + $sorted[$middle]) / 2;
    }

    /**
     * Diagnose every measured chunk with the production rules and fold the
     * results into one entry per code: how many sampled chunks hit it, plus the
     * WORST example measured, since that is the one worth printing and the one
     * whose numbers make the case.
     *
     * @param  list<Sample>  $samples
     * @return list<ProbeFinding>
     */
    private static function diagnose(array $samples, ?int $jobTimeoutSeconds): array
    {
        /** @var array<string, ProbeFinding> $worst */
        $worst = [];

        foreach ($samples as $sample) {
            $metrics = $sample['metrics'];

            if ($metrics === null) {
                continue;
            }

            foreach (ProfileDiagnostics::from($metrics, $jobTimeoutSeconds) as $finding) {
                $code = $finding['code'];
                $seen = $worst[$code] ?? null;

                if ($seen === null) {
                    $worst[$code] = [
                        'code' => $code,
                        'count' => 1,
                        'weight' => $finding['weight'],
                        'data' => $finding['data'],
                    ];

                    continue;
                }

                $seen['count']++;

                // Weight is each rule's own "how bad is this" scale, so the
                // heaviest occurrence is the representative one.
                if ($finding['weight'] > $seen['weight']) {
                    $seen['weight'] = $finding['weight'];
                    $seen['data'] = $finding['data'];
                }

                $worst[$code] = $seen;
            }
        }

        return array_values($worst);
    }

    /**
     * The extrapolation: mean measured chunk x every chunk in the plan.
     *
     * Serial seconds only. Dividing across N workers is the caller's business,
     * because N is an operator's answer ("how many workers will I run"), not a
     * measurement — and a number this class did not measure has no place inside
     * its own arithmetic.
     *
     * This is a projection from a handful of chunks onto possibly millions, and
     * it uses the mean precisely because a mean is what scales; the min/median/
     * max alongside it are what tell an operator how much to trust it.
     *
     * @param  Aggregates  $aggregates
     * @return Estimate|null
     */
    private static function estimate(int $totalChunks, array $aggregates): ?array
    {
        if ($aggregates['measured'] === 0) {
            return null;
        }

        // Extrapolate from the production-equivalent mean, not the measured one:
        // an import does not pay profiling's extra serialization pass, so
        // multiplying the measured mean by the chunk count inflates the answer by
        // that overhead on every single chunk. Both means travel in the report so
        // the renderer can show the gap rather than leave an operator wondering
        // why the estimate disagrees with the table above it.
        $meanSeconds = $aggregates['mean_production_ms'] / 1000;

        return [
            'chunks' => $totalChunks,
            'measured' => $aggregates['measured'],
            'mean_seconds' => round($meanSeconds, 3),
            'measured_mean_seconds' => round($aggregates['mean_ms'] / 1000, 3),
            'overhead_seconds' => round($aggregates['overhead_ms'] / 1000, 3),
            'serial_seconds' => round($meanSeconds * $totalChunks, 1),
        ];
    }

    /**
     * @return Sample
     */
    private static function failedSample(int $chunkId, string $error): array
    {
        return [
            'chunk_id' => $chunkId,
            'metrics' => null,
            'error' => $error,
        ];
    }

    /**
     * A metric read as a number, tolerating everything: the metrics array is
     * free-form diagnostic output, not a schema.
     *
     * @param  array<array-key, mixed>  $metrics
     */
    private static function number(array $metrics, string $key): float
    {
        $value = $metrics[$key] ?? null;

        return is_numeric($value) ? (float) $value : 0.0;
    }

    private static function describe(Throwable $e): string
    {
        $message = $e->getMessage();

        return $message !== '' ? $message : get_class($e);
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
