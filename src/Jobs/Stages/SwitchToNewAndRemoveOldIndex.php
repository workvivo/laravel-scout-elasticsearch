<?php

namespace Matchish\ScoutElasticSearch\Jobs\Stages;

use OpenSearch\Client;
use Matchish\ScoutElasticSearch\ElasticSearch\Index;
use Matchish\ScoutElasticSearch\ElasticSearch\Params\Indices\Alias\Get;
use Matchish\ScoutElasticSearch\ElasticSearch\Params\Indices\Alias\Update;
use Matchish\ScoutElasticSearch\ImportLock;
use Matchish\ScoutElasticSearch\Searchable\ImportSource;

/**
 * @internal
 */
final class SwitchToNewAndRemoveOldIndex implements StageInterface
{
    /**
     * @var ImportSource
     */
    private $source;
    /**
     * @var Index
     */
    private $index;

    /**
     * Owner token of the import lock this run holds. When set, the alias swap is
     * skipped unless we still own the lease — so a run whose lease has lapsed
     * (and been re-acquired by a newer run) can never promote its own stale index
     * or `remove_index` the newer run's in-progress write index, which still
     * carries this model's write alias and would otherwise be deleted below. Null
     * keeps the pre-existing unguarded behaviour (direct construction in tests).
     *
     * @var string|null
     */
    private $owner;

    /**
     * @param  ImportSource  $source
     * @param  Index  $index
     * @param  string|null  $owner
     */
    public function __construct(ImportSource $source, Index $index, ?string $owner = null)
    {
        $this->source = $source;
        $this->index = $index;
        $this->owner = $owner;
    }

    public function handle(Client $elasticsearch): void
    {
        $source = $this->source;

        // Confirm we still own the lease before the swap. This is check-then-act,
        // not atomic — a newer run could acquire between here and updateAliases —
        // but that window is a few milliseconds and the lease TTL remains the real
        // backstop; the same shape guards CleanUp and rollback.
        if ($this->owner !== null
            && ! ImportLock::isHeldBy($source->searchableAs(), $this->owner)) {
            logger()->warning('scout:import switch skipped: no longer lock owner', [
                'searchable' => $source->searchableAs(),
                'index' => $this->index->name(),
            ]);

            return;
        }

        $params = Get::anyIndex($source->searchableAs());
        $response = $elasticsearch->indices()->getAlias($params->toArray());

        $params = new Update();
        foreach ($response as $indexName => $alias) {
            if ($indexName != $this->index->name()) {
                $params->removeIndex((string) $indexName);
            } else {
                $params->add((string) $indexName, $source->searchableAs());
            }
        }
        $elasticsearch->indices()->updateAliases($params->toArray());
    }

    public function estimate(): int
    {
        return 1;
    }

    public function title(): string
    {
        return 'Switching to the new index';
    }
}
