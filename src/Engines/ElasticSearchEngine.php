<?php

namespace Matchish\ScoutElasticSearch\Engines;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\Builder as BaseBuilder;
use Laravel\Scout\Engines\Engine;
use Matchish\ScoutElasticSearch\ElasticSearch\HitsIteratorAggregate;
use Matchish\ScoutElasticSearch\ElasticSearch\Params\Bulk;
use Matchish\ScoutElasticSearch\ElasticSearch\Params\Indices\Refresh;
use Matchish\ScoutElasticSearch\ElasticSearch\Params\Search as SearchParams;
use Matchish\ScoutElasticSearch\ElasticSearch\SearchFactory;
use Matchish\ScoutElasticSearch\ElasticSearch\SearchResults;
use ONGR\ElasticsearchDSL\Query\MatchAllQuery;
use ONGR\ElasticsearchDSL\Search;
use OpenSearch\Client;
use OpenSearch\Common\Exceptions\ServerErrorResponseException;

final class ElasticSearchEngine extends Engine
{
    /**
     * The ElasticSearch client.
     *
     * @var Client
     */
    protected $elasticsearch;

    /**
     * Create a new engine instance.
     *
     * @param  Client  $elasticsearch
     * @return void
     */
    public function __construct(Client $elasticsearch)
    {
        $this->elasticsearch = $elasticsearch;
    }

    /**
     * @inheritdoc
     */
    public function update($models)
    {
        $this->performUpdate($models, null);
    }

    /**
     * Index the models into an explicit index instead of the alias
     * `searchableAs()` resolves to.
     *
     * A sibling of {@see update} rather than an extra argument on it: update()
     * implements Scout's Engine contract, so its signature is not ours to widen.
     * Callers that need the redirection (--probe writing to a throwaway index it
     * will delete, which must never become the alias's write index) ask for it by
     * name; everyone else keeps the contract method and the alias.
     *
     * @param  \Illuminate\Database\Eloquent\Collection|iterable  $models
     * @param  string  $index
     * @return void
     */
    public function updateInto($models, string $index)
    {
        $this->performUpdate($models, $index);
    }

    /**
     * The one body {@see update} and {@see updateInto} share, so the error triage
     * can never drift between the two entry points.
     *
     * @param  \Illuminate\Database\Eloquent\Collection|iterable  $models
     * @param  string|null  $index  null = each model's own searchableAs()
     * @return void
     */
    private function performUpdate($models, ?string $index)
    {
        $params = new Bulk();
        $params->into($index);
        $params->index($models);
        $response = $this->elasticsearch->bulk($params->toArray());
        if (array_key_exists('errors', $response) && $response['errors']) {
            // The raw response stays on the previous exception (nothing is lost),
            // but it can be megabytes of successful items and never says WHICH
            // document was rejected. Name the offending ids in the message so a
            // log line is actionable on its own.
            $error = new ServerErrorResponseException(json_encode($response, JSON_PRETTY_PRINT));
            throw new \Exception($this->bulkErrorMessage($response), $error->getCode(), $error);
        }
    }

    /**
     * Summarise the failing items of a bulk response as "id: type: reason".
     *
     * A bulk response reports one item per action, each keyed by the action
     * name (index/create/update/delete), and only the failing ones carry an
     * `error` member. The list is capped so a batch where every document fails
     * cannot produce an unbounded exception message.
     *
     * @param  array  $response
     * @return string
     */
    private function bulkErrorMessage($response): string
    {
        $cap = 10;
        $failures = [];
        $total = 0;

        foreach ($response['items'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            // Each item is a single-element map: ['index' => [...]].
            $result = reset($item);
            if (! is_array($result) || ! isset($result['error'])) {
                continue;
            }

            $total++;
            if (count($failures) >= $cap) {
                continue;
            }

            $error = is_array($result['error']) ? $result['error'] : ['reason' => (string) $result['error']];
            $failures[] = implode(': ', array_filter([
                isset($result['_id']) ? (string) $result['_id'] : '?',
                $error['type'] ?? null,
                $error['reason'] ?? null,
            ]));
        }

        if (0 === $total) {
            // `errors: true` with no per-item error is not expected; fall back
            // to the historic message rather than claiming zero failures.
            return 'Bulk update error';
        }

        $message = 'Bulk update error: '.$total.' of '.count($response['items'] ?? []).' items failed: '
            .implode('; ', $failures);

        if ($total > count($failures)) {
            $message .= ' (+'.($total - count($failures)).' more)';
        }

        return $message;
    }

    /**
     * @inheritdoc
     */
    public function delete($models)
    {
        $params = new Bulk();
        $params->delete($models);
        $this->elasticsearch->bulk($params->toArray());
    }

    /**
     * @inheritdoc
     */
    public function flush($model)
    {
        $indexName = $model->searchableAs();
        $exist = $this->elasticsearch->indices()->exists(['index' => $indexName]);
        if ($exist) {
            $body = (new Search())->addQuery(new MatchAllQuery())->toArray();
            $params = new SearchParams($indexName, $body);
            $this->elasticsearch->deleteByQuery($params->toArray());
            $this->elasticsearch->indices()->refresh((new Refresh($indexName))->toArray());
        }
    }

    /**
     * @inheritdoc
     */
    public function search(BaseBuilder $builder)
    {
        return $this->performSearch($builder, []);
    }

    /**
     * @inheritdoc
     */
    public function paginate(BaseBuilder $builder, $perPage, $page)
    {
        return $this->performSearch($builder, [
            'from' => ($page - 1) * $perPage,
            'size' => $perPage,
        ]);
    }

    /**
     * @inheritdoc
     */
    public function mapIds($results)
    {
        return collect($results['hits']['hits'])->pluck('_id');
    }

    /**
     * @inheritdoc
     */
    public function map(BaseBuilder $builder, $results, $model)
    {
        $hits = app()->makeWith(
            HitsIteratorAggregate::class,
            [
                'results' => $results,
                'callback' => $builder->queryCallback,
            ]
        );

        return new Collection($hits);
    }

    /**
     * Map the given results to instances of the given model via a lazy collection.
     *
     * @param  BaseBuilder  $builder
     * @param  mixed  $results
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return LazyCollection
     */
    public function lazyMap(Builder $builder, $results, $model)
    {
        if ((new \ReflectionClass($model))->isAnonymous()) {
            throw new \Error('Not implemented for MixedSearch');
        }

        if (count($results['hits']['hits']) === 0) {
            return LazyCollection::make($model->newCollection());
        }

        $objectIds = collect($results['hits']['hits'])->pluck('_id')->values()->all();
        $objectIdPositions = array_flip($objectIds);

        return $model->queryScoutModelsByIds(
            $builder,
            $objectIds
        )->cursor()->filter(function ($model) use ($objectIds) {
            return in_array($model->getScoutKey(), $objectIds);
        })->sortBy(function ($model) use ($objectIdPositions) {
            return $objectIdPositions[$model->getScoutKey()];
        })->values();
    }

    /**
     * Create a search index.
     *
     * @param  string  $name
     * @param  array  $options
     * @return mixed
     */
    public function createIndex($name, array $options = [])
    {
        throw new \Error('Not implemented');
    }

    /**
     * Delete a search index.
     *
     * @param  string  $name
     * @return mixed
     */
    public function deleteIndex($name)
    {
        throw new \Error('Not implemented');
    }

    /**
     * @inheritdoc
     */
    public function getTotalCount($results)
    {
        return $results['hits']['total']['value'];
    }

    /**
     * @param  BaseBuilder  $builder
     * @param  array  $options
     * @return SearchResults|mixed
     */
    private function performSearch(BaseBuilder $builder, $options = [])
    {
        $searchBody = SearchFactory::create($builder, $options);
        if ($builder->callback) {
            /** @var callable */
            $callback = $builder->callback;

            return call_user_func(
                $callback,
                $this->elasticsearch,
                $searchBody
            );
        }

        $model = $builder->model;
        $indexName = $builder->index ?: $model->searchableAs();
        $params = new SearchParams($indexName, $searchBody->toArray());

        return $this->elasticsearch->search($params->toArray());
    }
}
