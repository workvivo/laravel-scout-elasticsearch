<?php

namespace Matchish\ScoutElasticSearch\Jobs;

use Illuminate\Support\Collection;
use Matchish\ScoutElasticSearch\ElasticSearch\Index;
use Matchish\ScoutElasticSearch\Jobs\Stages\CleanUp;
use Matchish\ScoutElasticSearch\Jobs\Stages\CreateWriteIndex;
use Matchish\ScoutElasticSearch\Jobs\Stages\PullFromSource;
use Matchish\ScoutElasticSearch\Jobs\Stages\RefreshIndex;
use Matchish\ScoutElasticSearch\Jobs\Stages\SwitchToNewAndRemoveOldIndex;
use Matchish\ScoutElasticSearch\Searchable\ImportSource;

/**
 * @extends Collection<int, \Matchish\ScoutElasticSearch\Jobs\Stages\StageInterface>
 */
class ImportStages extends Collection
{
    /**
     * @param  ImportSource  $source
     * @param  bool  $profile
     * @param  string|null  $owner
     * @return Collection<int, \Matchish\ScoutElasticSearch\Jobs\Stages\StageInterface>
     */
    public static function fromSource(ImportSource $source, bool $profile = false, ?string $owner = null)
    {
        $index = Index::fromSource($source);

        /** @var array<int, \Matchish\ScoutElasticSearch\Jobs\Stages\StageInterface> $pullStages */
        $pullStages = PullFromSource::chunked($source, $profile)->all();

        return new self(array_merge([
            new CleanUp($source, $owner),
            new CreateWriteIndex($source, $index),
        ], $pullStages, [
            new RefreshIndex($index),
            new SwitchToNewAndRemoveOldIndex($source, $index, $owner),
        ]));
    }
}
