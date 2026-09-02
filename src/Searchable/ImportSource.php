<?php

namespace Matchish\ScoutElasticSearch\Searchable;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

interface ImportSource
{
    public function syncWithSearchUsingQueue(): ?string;

    public function syncWithSearchUsing(): ?string;

    public function searchableAs(): string;

    /**
     * @return Collection<int, ImportSource>
     */
    public function chunked(): Collection;

    /**
     * @return EloquentCollection<int, \Illuminate\Database\Eloquent\Model>
     */
    public function get(): EloquentCollection;
}
