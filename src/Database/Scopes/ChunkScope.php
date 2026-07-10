<?php

namespace Matchish\ScoutElasticSearch\Database\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class ChunkScope implements Scope
{
    /**
     * @var mixed
     */
    private $start;
    /**
     * @var mixed
     */
    private $end;

    /**
     * ChunkScope constructor.
     *
     * @param  mixed  $start
     * @param  mixed  $end
     */
    public function __construct($start, $end)
    {
        $this->start = $start;
        $this->end = $end;
    }

    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * @param  Builder<Model>  $builder
     * @param  Model  $model
     * @return void
     */
    public function apply(Builder $builder, Model $model)
    {
        $start = $this->start;
        $end = $this->end;
        $key = $model->getQualifiedKeyName();
        $builder
            ->when(! is_null($start), function ($query) use ($start, $key) {
                return $query->where($key, '>', $start);
            })
            ->when(! is_null($end), function ($query) use ($end, $key) {
                return $query->where($key, '<=', $end);
            });
    }

    public function key(): string
    {
        return static::class;
    }
}
