<?php

namespace Matchish\ScoutElasticSearch\Searchable;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Matchish\ScoutElasticSearch\Database\Scopes\PageScope;

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
     * @param string $className
     * @param array $scopes
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
        echo 'DefaultImportSource 50: '.date('h:i:s.u')."\n";
        $query = $this->newQuery();
        $totalSearchables = $query->count();
        echo 'DefaultImportSource 54: '.date('h:i:s.u')."\n";

        if ($totalSearchables) {
            $chunkSize = (int) config('scout.chunk.searchable', self::DEFAULT_CHUNK_SIZE);
            $totalChunks = (int) ceil($totalSearchables / $chunkSize);

            return collect(range(1, $totalChunks))->map(function ($page) use ($chunkSize) {
                $chunkScope = new PageScope($page, $chunkSize);

                return new static($this->className, array_merge($this->scopes, [$chunkScope]));
            });
        } else {
            return collect();
        }
        echo 'DefaultImportSource 68: '.date('h:i:s.u')."\n";
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
        echo 'DefaultImportSource 81: '.date('h:i:s.u')."\n";

        $query = $this->model()->newQuery()->setEagerLoads([])->withoutGlobalScopes();
        $softDelete = $this->className::usesSoftDelete() && config('scout.soft_delete', false);

        $searchableCountRelations = null;

        if (method_exists($this, 'searchableCountRelations')) {
            $searchableCountRelations = $this->searchableCountRelations();
        }
        echo 'DefaultImportSource 91: '.date('h:i:s.u')."\n";
        $query
            ->when($softDelete, function ($query) {
                return $query->withTrashed();
            })
            ->when($searchableCountRelations, function ($query) use ($searchableCountRelations) {
                return $query->withCount($searchableCountRelations);
            })
            ->orderBy($this->model()->getKeyName());
        echo 'DefaultImportSource 100: '.date('h:i:s.u')."\n";

        $scopes = $this->scopes;

        return collect($scopes)->reduce(function ($instance, $scope) {
            $instance->withGlobalScope(get_class($scope), $scope);

            return $instance;
        }, $query);
    }

    public function get(): EloquentCollection
    {
        echo 'DefaultImportSource 113: '.date('h:i:s.u')."\n";
        /** @var EloquentCollection $models */
        $models = $this->newQuery()->get();

        return $models;
    }
}
