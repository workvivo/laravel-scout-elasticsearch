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
     * DefaultImportSource constructor.
     *
     * @param  string  $className
     * @param  array  $scopes
     */
    public function __construct(string $className, array $scopes = [])
    {
        $this->className = $className;
        $this->scopes = $scopes;
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
        // silently importing into an empty index.
        $chunkSize = max(1, (int) config('scout.chunk.searchable', self::DEFAULT_CHUNK_SIZE));
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
        $bounds = collect();
        $last = null;

        do {
            $query = $this->newQuery()->reorder()->orderBy($key)->limit($chunkSize);

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

            return new static($this->className, array_merge($this->scopes, [$chunkScope]));
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

    public function get(): EloquentCollection
    {
        return $this->newQuery()->get();
    }
}
