<?php

namespace Matchish\ScoutElasticSearch\Jobs\Stages;

use Matchish\ScoutElasticSearch\ElasticSearch\Params\Indices\Alias\Get as GetAliasParams;
use Matchish\ScoutElasticSearch\ElasticSearch\Params\Indices\Delete as DeleteIndexParams;
use Matchish\ScoutElasticSearch\ImportLock;
use Matchish\ScoutElasticSearch\Searchable\ImportSource;
use OpenSearch\Client;
use OpenSearch\Common\Exceptions\Missing404Exception;

/**
 * @internal
 */
final class CleanUp implements StageInterface
{
    /**
     * @var ImportSource
     */
    private $source;

    /**
     * Owner token of the import lock this run holds. When set, the write-index
     * delete below is skipped unless we still own the lease — so a run whose
     * lease has lapsed (and been re-acquired by a newer run) can never delete an
     * index that now belongs to that newer run. Null keeps the pre-existing
     * unguarded behaviour (sequential path / direct construction in tests).
     *
     * @var string|null
     */
    private $owner;

    /**
     * @param  ImportSource  $source
     * @param  string|null  $owner
     */
    public function __construct(ImportSource $source, ?string $owner = null)
    {
        $this->source = $source;
        $this->owner = $owner;
    }

    public function handle(Client $elasticsearch): void
    {
        $source = $this->source;
        $params = GetAliasParams::anyIndex($source->searchableAs());
        try {
            $response = $elasticsearch->indices()->getAlias($params->toArray());
        } catch (Missing404Exception $e) {
            $response = [];
        }
        foreach ($response as $indexName => $data) {
            foreach ($data['aliases'] as $alias => $config) {
                if (array_key_exists('is_write_index', $config) && $config['is_write_index']) {
                    // Do not delete the previous run's write index if we have
                    // lost the lease — another import now owns this model.
                    if ($this->owner !== null
                        && ! ImportLock::isHeldBy($source->searchableAs(), $this->owner)) {
                        logger()->warning('scout:import CleanUp skipped: no longer lock owner', [
                            'searchable' => $source->searchableAs(),
                            'index' => (string) $indexName,
                        ]);

                        return;
                    }

                    $params = new DeleteIndexParams((string) $indexName);
                    $elasticsearch->indices()->delete($params->toArray());
                    continue 2;
                }
            }
        }
    }

    public function title(): string
    {
        return 'Clean up';
    }

    public function estimate(): int
    {
        return 1;
    }
}
