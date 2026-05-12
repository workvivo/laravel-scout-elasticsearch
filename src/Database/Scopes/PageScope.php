<?php

namespace Matchish\ScoutElasticSearch\Database\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Cache;

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
        $keyName = $model->getKeyName();

        if ($keyName === 'id') {
            // Keyset pagination on `id` requires `id` to be the only ORDER BY.
            // forPageAfterId clears existing orders for `id` but leaves other
            // orderings in place, so any competing ORDER BY (e.g. from a model
            // global scope or makeAllSearchableUsing) would become the primary
            // sort and cause chunks to skip or duplicate rows. reorder() drops
            // them all before forPageAfterId sets its own.
            $builder->reorder();
            $builder->forPageAfterId($this->perPage, Cache::get('scout_import_last_id', 0), $model->getTable().'.id');
        } else {
            $builder->forPage($this->page, $this->perPage);
        }
    }
}
