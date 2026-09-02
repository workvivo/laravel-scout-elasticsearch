<?php

namespace Matchish\ScoutElasticSearch\ElasticSearch\Params;

/**
 * @internal
 */
final class Bulk
{
    /**
     * @var array
     */
    private $indexDocs = [];

    /**
     * @var array
     */
    private $deleteDocs = [];

    /**
     * The index every action in this payload targets, or null to keep using each
     * model's own `searchableAs()` — which is the ALIAS, and therefore the live
     * index the application reads from.
     *
     * Overriding it is how a caller writes documents somewhere the alias does not
     * point (the --probe throwaway index). It has to be a property rather than an
     * argument to {@see toArray} because the payload is assembled by two reduce
     * closures over models, and the model is what would otherwise decide.
     *
     * @var string|null
     */
    private $index = null;

    /**
     * @param  array|object  $docs
     */
    public function delete($docs): void
    {
        if (is_iterable($docs)) {
            foreach ($docs as $doc) {
                $this->delete($doc);
            }
        } else {
            $this->deleteDocs[$docs->getScoutKey()] = $docs;
        }
    }

    /**
     * Send every action in this payload to $index instead of the model's alias.
     *
     * Passing null restores the default, so `into(null)` is indistinguishable
     * from never having called it — the payload is byte-identical.
     *
     * @param  string|null  $index
     * @return $this
     */
    public function into(?string $index): self
    {
        $this->index = $index;

        return $this;
    }

    /**
     * @return array
     */
    public function toArray(): array
    {
        $payload = ['body' => []];
        $payload = collect($this->indexDocs)->reduce(
            function ($payload, $model) {
                if ($model::usesSoftDelete() && config('scout.soft_delete', false)) {
                    $model->pushSoftDeleteMetadata();
                }

                $attributes = $model->getAttributes();
                $routing = array_key_exists('routing', $attributes) ? $model->routing : null;
                $scoutKey = $model->getScoutKey();

                $payload['body'][] = [
                    'index' => [
                        '_index' => $this->index ?? $model->searchableAs(),
                        '_id' => $scoutKey,
                        'routing' => false === empty($routing) ? $routing : $scoutKey,
                    ],
                ];

                $payload['body'][] = array_merge(
                    $model->toSearchableArray(),
                    $model->scoutMetadata(),
                    [
                        '__class_name' => get_class($model),
                    ]
                );

                return $payload;
            }, $payload);

        $payload = collect($this->deleteDocs)->reduce(
            function ($payload, $model) {
                $attributes = $model->getAttributes();
                $routing = array_key_exists('routing', $attributes) ? $model->routing : null;
                $scoutKey = $model->getScoutKey();

                $payload['body'][] = [
                    'delete' => [
                        '_index' => $this->index ?? $model->searchableAs(),
                        '_id' => $scoutKey,
                        'routing' => false === empty($routing) ? $routing : $scoutKey,
                    ],
                ];

                return $payload;
            }, $payload);

        return $payload;
    }

    /**
     * @param  array|object  $docs
     */
    public function index($docs): void
    {
        if (is_iterable($docs)) {
            foreach ($docs as $doc) {
                $this->index($doc);
            }
        } else {
            $this->indexDocs[$docs->getScoutKey()] = $docs;
        }
    }
}
