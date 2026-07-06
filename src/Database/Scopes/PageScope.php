<?php

namespace Matchish\ScoutElasticSearch\Database\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Offset-based page scope. No longer used by the import pipeline, which seeks
 * by key range (see {@see \Matchish\ScoutElasticSearch\Database\Scopes\ChunkScope}).
 * Kept for backwards compatibility only.
 *
 * @internal
 */
class PageScope implements Scope
{
    /**
     * @var int
     */
    private $page;
    /**
     * @var int
     */
    private $perPage;

    /**
     * PageScope constructor.
     *
     * @param  int  $page
     * @param  int  $perPage
     */
    public function __construct(int $page, int $perPage)
    {
        $this->page = $page;
        $this->perPage = $perPage;
    }

    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * @param  Builder  $builder
     * @param  Model  $model
     * @return void
     */
    public function apply(Builder $builder, Model $model)
    {
        $builder->forPage($this->page, $this->perPage);
    }
}
