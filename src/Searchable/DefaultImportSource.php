<?php

namespace Matchish\ScoutElasticSearch\Searchable;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Matchish\ScoutElasticSearch\Database\Scopes\ChunkScope;

final class DefaultImportSource implements ImportSource
{
    const DEFAULT_CHUNK_SIZE = 500;

    /**
     * @var string
     */
    private $className;
    /**
     * @var array
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
     * @param  string  $className
     * @param  array  $scopes
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

    public function syncWithSearchUsingQueue(): ?string
    {
        return $this->model()->syncWithSearchUsingQueue();
    }

    public function syncWithSearchUsing(): ?string
    {
        return $this->model()->syncWithSearchUsing();
    }

    public function searchableAs(): string
    {
        return $this->model()->searchableAs();
    }

    public function chunked(): Collection
    {
        // Guard against a misconfigured chunk size: 0 or a negative value would
        // make limit($chunkSize) return nothing, leaving $bounds empty and
        // silently importing into an empty index. A per-run override (the
        // command's --chunk option) takes precedence over the config.
        $chunkSize = max(1, (int) ($this->chunkSize ?? config('scout.chunk.searchable', self::DEFAULT_CHUNK_SIZE)));
        $key = $this->model()->getQualifiedKeyName();

        // Build the chunk boundaries by seeking through the primary keys rather
        // than counting rows and paginating by offset. Each chunk then imports
        // its slice with a key-range seek (WHERE key > ? AND key <= ?) instead
        // of OFFSET, so keyset paging costs the same for the first chunk and the
        // last one — imports no longer slow down as they progress through a
        // large table.
        //
        // Only the key column is read, and only one chunk of keys is held at a
        // time, so this stays O(chunk size) in memory instead of loading every
        // primary key at once — safe on tables with millions of rows. Each
        // boundary is the last key of a chunk (its inclusive upper bound) and
        // becomes the exclusive lower bound of the next chunk. reorder() drops
        // any competing ORDER BY (e.g. from a model global scope or
        // makeAllSearchableUsing) that would otherwise make the key sequence
        // non-monotonic and cause chunks to skip or duplicate rows.
        //
        // In fast-plan mode the boundaries come from a bare key query
        // (planningQuery) that skips the eager-load join/filters; the per-chunk
        // fetch still applies them, so ranges only widen and coverage is
        // unchanged (see planningQuery()).
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

        if ($bounds->isEmpty()) {
            return collect();
        }

        // Using real keys (not arithmetic offsets) keeps the ranges correct even
        // when keys are sparse because of deletes, and lets each chunk stage run
        // independently on a queue with no shared cursor state.
        return $bounds->map(function ($end, $index) use ($bounds) {
            $start = $index === 0 ? null : $bounds->get($index - 1);
            $chunkScope = new ChunkScope($start, $end);

            return new static($this->className, array_merge($this->scopes, [$chunkScope]), $this->chunkSize, $this->fastPlan);
        });
    }

    /**
     * @return mixed
     */
    private function model()
    {
        return new $this->className;
    }

    private function newQuery(): Builder
    {
        $query = $this->className::__callStatic('makeAllSearchableUsing', [$this->model()->newQuery()]);

        $softDelete = $this->className::usesSoftDelete() && config('scout.soft_delete', false);

        $query
            ->when($softDelete, function ($query) {
                return $query->withTrashed();
            })
            ->orderBy($this->model()->getQualifiedKeyName());

        $scopes = $this->scopes;

        return collect($scopes)->reduce(function ($instance, $scope) {
            $instance->withGlobalScope(get_class($scope), $scope);

            return $instance;
        }, $query);
    }

    /**
     * Bare key query for fast-plan boundary building: the base model query with
     * only the soft-delete handling that the fetch uses, and none of the
     * eager-load join / filters from makeAllSearchableUsing or the injected
     * scopes.
     *
     * This is safe because those clauses can only ever *restrict* the fetched
     * rows (an inner join or where narrows the set) or *decorate* them (eager
     * load), never add rows outside the table's key space. So planning over the
     * bare keys always covers every key the fetch could return — ranges may be
     * wider (a few lighter chunks), but nothing is skipped, and the fetch still
     * applies the join/filters so no extra records are indexed.
     */
    private function planningQuery(): Builder
    {
        $query = $this->model()->newQuery();

        $softDelete = $this->className::usesSoftDelete() && config('scout.soft_delete', false);

        return $query->when($softDelete, function ($query) {
            return $query->withTrashed();
        });
    }

    public function get(): EloquentCollection
    {
        return $this->newQuery()->get();
    }
}
