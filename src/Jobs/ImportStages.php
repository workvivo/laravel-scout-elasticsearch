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

class ImportStages extends Collection
{
    /**
     * @param  ImportSource  $source
     * @param  bool  $profile
     * @param  string|null  $owner
     * @return Collection
     */
    public static function fromSource(ImportSource $source, bool $profile = false, ?string $owner = null)
    {
        $index = Index::fromSource($source);

        return (new self([
            new CleanUp($source, $owner),
            new CreateWriteIndex($source, $index),
            PullFromSource::chunked($source, $profile),
            new RefreshIndex($index),
            new SwitchToNewAndRemoveOldIndex($source, $index),
        ]))->flatten()->filter();
    }
}
