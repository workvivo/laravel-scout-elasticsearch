<?php

namespace Matchish\ScoutElasticSearch\Jobs\Stages;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Matchish\ScoutElasticSearch\Searchable\ImportSource;
use OpenSearch\Client;

/**
 * @internal
 */
final class PullFromSource implements StageInterface
{
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
     * @var bool
     */
    private $profile;

    /**
     * @param  ImportSource  $source
     * @param  bool  $profile
     */
    public function __construct(ImportSource $source, bool $profile = false)
    {
        $this->source = $source;
        $this->profile = $profile;
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
     */
    private function handleProfiled(): void
    {
        $lazyLoads = [];
        $wasPreventing = Model::preventsLazyLoading();
        Model::handleLazyLoadingViolationUsing(function ($model, $relation) use (&$lazyLoads) {
            $key = get_class($model).'::'.$relation;
            $lazyLoads[$key] = ($lazyLoads[$key] ?? 0) + 1;
        });
        // Flagging (not throwing) means the relation still loads, so the import
        // stays correct; we only observe that it happened.
        Model::preventLazyLoading();

        $countQueries = function (callable $work): array {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $result = $work();
            $queries = count(DB::getQueryLog());
            DB::flushQueryLog();

            return [$result, $queries];
        };

        try {
            $t0 = microtime(true);
            [$fetched, $fetchQueries] = $countQueries(function () {
                return $this->source->get();
            });
            $t1 = microtime(true);

            [$results, $filterQueries] = $countQueries(function () use ($fetched) {
                return $fetched->filter(function (Model $item): bool {
                    return $this->shouldBeSearchable($item);
                });
            });
            $t2 = microtime(true);

            $indexed = 0;
            $serializeMs = 0.0;
            $bulkMs = 0.0;
            $bytes = 0;
            [, $indexQueries] = $countQueries(function () use ($results, &$indexed, &$serializeMs, &$bulkMs, &$bytes) {
                if ($results->isEmpty()) {
                    return;
                }

                // Serialization pass on its own: build each document as it will
                // be sent and measure the CPU cost + payload size. update()
                // repeats this work internally; in --profile mode the extra
                // pass is an acceptable diagnostic cost.
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

        logger()->info('scout:import chunk profile', [
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
            // Empty means nothing lazy-loaded: the index time is genuine
            // serialization + the Elasticsearch bulk request, not an N+1.
            'lazy_loads' => $lazyLoads,
        ]);
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
     * @param  ImportSource  $source
     * @param  bool  $profile
     * @return Collection<int, self>
     */
    public static function chunked(ImportSource $source, bool $profile = false): Collection
    {
        return $source->chunked()->map(function (ImportSource $chunk) use ($profile): self {
            return new static($chunk, $profile);
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
        $engine->update($models);
    }
}
