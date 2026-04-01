<?php

namespace Matchish\ScoutElasticSearch\Jobs\Stages;

use Matchish\ScoutElasticSearch\ElasticSearch\Index;
use Matchish\ScoutElasticSearch\ElasticSearch\Params\Indices\Refresh;
use OpenSearch\Client;

/**
 * @internal
 */
final class RefreshIndex implements StageInterface
{
    /**
     * @var Index
     */
    private $index;

    /**
     * RefreshIndex constructor.
     *
     * @param  Index  $index
     */
    public function __construct(Index $index)
    {
        $this->index = $index;
    }

    public function handle(Client $elasticsearch): void
    {
        $params = new Refresh($this->index->name());
        $elasticsearch->indices()->refresh($params->toArray());
    }

    public function estimate(): int
    {
        return 1;
    }

    public function title(): string
    {
        return 'Refreshing index';
    }
}
