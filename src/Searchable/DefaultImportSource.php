<?php

namespace Matchish\ScoutElasticSearch\Searchable;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Collection;
use Matchish\ScoutElasticSearch\Database\Scopes\ChunkScope;

final class DefaultImportSource implements ImportSource
{
    const DEFAULT_CHUNK_SIZE = 500;

    /**
     * @var class-string<Model>
     */
    private $className;
    /**
     * @var array<int, Scope>
     */
    private $scopes;
    /**
     * Per-run chunk size override. Null falls back to the scout.chunk.searchable
     * config. Carried on the source so it survives serialization to the queue
     * workers that build the chunks.
     *
     * @var int|null
     */
    private $chunkSize;
    /**
     * When true, chunk boundaries are planned from a bare key query that skips
     * makeAllSearchableUsing and the injected scopes (the eager-load joins /
     * filters). The per-chunk fetch still applies them, so this only widens the
     * planned ranges — coverage stays complete and no extra rows are indexed —
     * while making each planning query a cheap index-only key scan.
     *
     * @var bool
     */
    private $fastPlan;

    /**
     * DefaultImportSource constructor.
     *
     * @param  class-string<Model>  $className
     * @param  array<int, Scope>  $scopes
     * @param  int|null  $chunkSize
     * @param  bool  $fastPlan
     */
    public function __construct(string $className, array $scopes = [], ?int $chunkSize = null, bool $fastPlan = false)
    {
        $this->className = $className;
        $this->scopes = $scopes;
        $this->chunkSize = $chunkSize;
        $this->fastPlan = $fastPlan;
    }

    /**
     * Return a copy of this source that chunks by the given size instead of the
     * configured default.
     */
    public function withChunkSize(?int $chunkSize): self
    {
        return new static($this->className, $this->scopes, $chunkSize, $this->fastPlan);
    }

    /**
     * Return a copy of this source that plans chunk boundaries without the
     * eager-load join / filters (faster planning on joined models).
     */
    public function withFastPlan(bool $fastPlan = true): self
    {
        return new static($this->className, $this->scopes, $this->chunkSize, $fastPlan);
    }

    /**
     * Return the chunk source for one planned boundary pair.
     *
     * This is how a paged fan-out rebuilds a chunk from bounds it parked
     * earlier instead of holding every chunk source in memory: the ChunkScope
     * goes on top of this source's own scopes, exactly as the planner does, so a
     * rebuilt chunk queries precisely what the planner intended.
     *
     * @param  mixed  $start  exclusive lower bound, null for the first chunk
     * @param  mixed  $end  inclusive upper bound
     */
    public function withChunkBounds($start, $end): self
    {
        return new static($this->className, array_merge($this->scopes, [new ChunkScope($start, $end)]), $this->chunkSize, $this->fastPlan);
    }

    public function syncWithSearchUsingQueue(): ?string
    {
        return $this->callStringScoutMethod('syncWithSearchUsingQueue');
    }

    public function syncWithSearchUsing(): ?string
    {
        return $this->callStringScoutMethod('syncWithSearchUsing');
    }

    public function searchableAs(): string
    {
        $searchableAs = $this->callStringScoutMethod('searchableAs');

        return $searchableAs ?? $this->model()->getTable();
    }

    /**
     * @return Collection<int, ImportSource>
     */
    public function chunked(): Collection
    {
        /** @var Collection<int, ImportSource> $chunks */
        $chunks = $this->chunkBounds()->map(function (array $bounds) {
            return $this->withChunkBounds($bounds[0], $bounds[1]);
        });

        return $chunks;
    }

    /**
     * The planned chunk boundaries as [start, end] pairs — the same planning
     * chunked() does, without materialising an ImportSource per chunk. A fan-out
     * over millions of chunks can park these (they are plain scalars) and
     * rebuild each source on demand with withChunkBounds().
     *
     * The pair index is the chunk id: pairs are ordered and contiguous, exactly
     * as chunked() returns them.
     *
     * @return Collection<int, array{0:mixed, 1:mixed}>
     */
    public function chunkBounds(): Collection
    {
        $chunkSize = $this->resolveChunkSize();
        $key = $this->model()->getQualifiedKeyName();

        // Auto-increment integer PKs are the common case, and they let us plan
        // chunk boundaries arithmetically from a single MIN/MAX aggregate —
        // dramatically faster than the seek loop on tables with millions of
        // rows (2 queries instead of one per chunk). Any other key shape
        // (UUIDs, string keys, non-incrementing snowflake IDs) has no
        // meaningful arithmetic step over its key-space, so we fall back to
        // the seek loop which walks real keys.
        $upperBounds = $this->canPlanArithmetically()
            ? $this->boundariesArithmetic($key, $chunkSize)
            : $this->boundariesSeek($key, $chunkSize);

        return $this->pairBoundaries($upperBounds);
    }

    /**
     * Resolve the chunk size with clear precedence: the per-run --chunk
     * override, then a per-model config entry, then a package-wide default,
     * then Scout's global default (500). Fewer, larger chunks mean fewer
     * queued jobs on large tables. Absent config keys reproduce today's
     * behaviour exactly.
     *
     * Guard against a misconfigured 0/negative value, which would make
     * limit($chunkSize) return nothing and silently import into an empty
     * index.
     */
    private function resolveChunkSize(): int
    {
        $chunkSize = $this->chunkSize
            ?? config("elasticsearch.import.chunk.{$this->searchableAs()}")
            ?? config('elasticsearch.import.chunk.default')
            ?? config('scout.chunk.searchable', self::DEFAULT_CHUNK_SIZE);

        return max(1, is_numeric($chunkSize) ? (int) $chunkSize : self::DEFAULT_CHUNK_SIZE);
    }

    /**
     * Turn a list of inclusive upper bounds into [start, end] pairs. Each chunk
     * starts exclusively at the previous chunk's upper bound, and the first
     * chunk has no lower bound at all, so the pairs tile the key space with no
     * gap and no overlap.
     *
     * @param  Collection<int, mixed>  $bounds
     * @return Collection<int, array{0:mixed, 1:mixed}>
     */
    private function pairBoundaries(Collection $bounds): Collection
    {
        /** @var Collection<int, array{0:mixed, 1:mixed}> $pairs */
        $pairs = $bounds->map(function ($end, $index) use ($bounds) {
            return [$index === 0 ? null : $bounds->get($index - 1), $end];
        });

        return $pairs;
    }

    /**
     * True when chunk boundaries can be computed from `MIN/MAX(key) + N*chunk`
     * arithmetic instead of walking real keys. Requires an incrementing
     * integer primary key — Laravel's default. Models with `$incrementing =
     * false` (e.g. snowflake IDs) or `$keyType = 'string'` (UUID / ULID)
     * opt themselves out automatically.
     */
    private function canPlanArithmetically(): bool
    {
        $model = $this->model();

        return $model->getKeyType() === 'int' && $model->getIncrementing() === true;
    }

    /**
     * Arithmetic planning: one MIN + one MAX query, then N chunk boundaries
     * generated in memory. Coverage is complete because the fetch still filters
     * per-chunk; sparse keys just produce some lighter (or empty) chunks.
     *
     * @return Collection<int, mixed> inclusive upper bounds, in ascending order
     */
    private function boundariesArithmetic(string $key, int $chunkSize): Collection
    {
        $planningQuery = $this->fastPlan ? $this->planningQuery() : $this->newQuery();

        // Aggregate() internally strips orders/limits/offsets from the local
        // builder, so any scope-injected ORDER BY that survives to execute
        // time has no effect on a single-row aggregate result.
        $min = $planningQuery->min($key);

        if ($min === null) {
            return collect();
        }

        // Defensive fallback: canPlanArithmetically() trusts the model's
        // $keyType / $incrementing declaration, but some real-world codebases
        // store UUIDs (or other non-numeric values) in a column while
        // leaving those defaults at 'int'/true. Casting a UUID to int would
        // silently produce garbage boundaries and index zero rows. Verify
        // the actual value is numeric; if not, fall back to seek planning
        // which walks real keys and works for any orderable column.
        if (! is_numeric($min)) {
            return $this->boundariesSeek($key, $chunkSize);
        }

        $max = $planningQuery->max($key);

        if (! is_numeric($max)) {
            return $this->boundariesSeek($key, $chunkSize);
        }

        $min = (int) $min;
        $max = (int) $max;

        // Boundaries are inclusive upper bounds. First chunk covers keys up to
        // min+chunkSize-1, and each subsequent boundary steps by chunkSize
        // until we reach (or pass) max. The final boundary is clamped to max
        // so the last chunk always closes exactly on the highest real key.
        /** @var Collection<int, mixed> $bounds */
        $bounds = collect();
        $upper = $min + $chunkSize - 1;
        while ($upper < $max) {
            $bounds->push($upper);
            $upper += $chunkSize;
        }
        $bounds->push($max);

        return $bounds;
    }

    /**
     * Seek planning: walk the primary key in chunkSize-sized batches, capturing
     * the last key of each batch as an inclusive upper bound. This is the
     * general-purpose fallback for any key shape (string, UUID, sparse int),
     * where arithmetic over key-space would either be undefined or produce
     * pathologically many empty chunks.
     *
     * @return Collection<int, mixed> inclusive upper bounds, in ascending order
     */
    private function boundariesSeek(string $key, int $chunkSize): Collection
    {
        // Only the key column is read, and only one chunk of keys is held at
        // a time, so this stays O(chunk size) in memory instead of loading
        // every primary key at once — safe on tables with millions of rows.
        // reorder() drops any competing ORDER BY (e.g. from
        // makeAllSearchableUsing) that would otherwise make the key sequence
        // non-monotonic and cause chunks to skip or duplicate rows. Model
        // global scopes' ORDER BY clauses, added lazily by applyScopes at
        // execute time, can still land here in non-fast-plan mode; the
        // primary ORDER BY on the key stays leftmost so keyset ordering is
        // preserved. In fast-plan mode planningQuery() already strips them.
        /** @var Collection<int, mixed> $bounds */
        $bounds = collect();
        $last = null;

        do {
            $planningQuery = $this->fastPlan ? $this->planningQuery() : $this->newQuery();
            $query = $planningQuery->reorder()->orderBy($key)->limit($chunkSize);

            if ($last !== null) {
                $query->where($key, '>', $last);
            }

            $keys = $query->pluck($key);

            if ($keys->isEmpty()) {
                break;
            }

            $last = $keys->last();
            $bounds->push($last);
        } while ($keys->count() === $chunkSize);

        return $bounds;
    }

    /**
     * @return Model
     */
    private function model(): Model
    {
        return new $this->className;
    }

    /**
     * @return Builder<Model>
     */
    private function newQuery(): Builder
    {
        /** @var Builder<Model> $query */
        $query = $this->className::__callStatic('makeAllSearchableUsing', [$this->model()->newQuery()]);

        $softDelete = $this->usesSoftDelete() && config('scout.soft_delete', false);

        if ($softDelete) {
            $query->{'withTrashed'}();
        }

        $query->orderBy($this->model()->getQualifiedKeyName());

        foreach ($this->scopes as $scope) {
            $query->withGlobalScope(get_class($scope), $scope);
        }

        return $query;
    }

    /**
     * Bare key query for fast-plan boundary building: the base model query with
     * only the soft-delete handling that the fetch uses, and none of the
     * eager-load join / filters from makeAllSearchableUsing, the injected
     * scopes, or the model's own registered global scopes.
     *
     * Stripping model global scopes matters on large tables where a WHERE
     * added by a scope (tenant filter, published-only, custom SoftDeletes,
     * etc.) turns the planning key scan into a full table/join scan.
     * withoutGlobalScopes() removes them — including SoftDeletingScope — so
     * the soft-delete branch below is redundant when they are stripped but is
     * kept for consistency with newQuery(); withTrashed() is idempotent.
     *
     * This is safe because those clauses can only ever *restrict* the fetched
     * rows (an inner join or where narrows the set) or *decorate* them (eager
     * load), never add rows outside the table's key space. So planning over the
     * bare keys always covers every key the fetch could return — ranges may be
     * wider (a few lighter chunks), but nothing is skipped, and the fetch still
     * applies the join/filters/scopes so no extra records are indexed.
     *
     * @return Builder<Model>
     */
    private function planningQuery(): Builder
    {
        $query = $this->model()->newQuery()->withoutGlobalScopes();

        $softDelete = $this->usesSoftDelete() && config('scout.soft_delete', false);

        if ($softDelete) {
            $query->{'withTrashed'}();
        }

        return $query;
    }

    /**
     * @return EloquentCollection<int, Model>
     */
    public function get(): EloquentCollection
    {
        return $this->newQuery()->get();
    }

    private function usesSoftDelete(): bool
    {
        return method_exists($this->className, 'usesSoftDelete')
            && (bool) forward_static_call([$this->className, 'usesSoftDelete']);
    }

    private function callStringScoutMethod(string $method): ?string
    {
        $value = $this->model()->{$method}();

        return is_string($value) ? $value : null;
    }
}
