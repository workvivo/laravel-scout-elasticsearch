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
     * @param  int|null  $profileSamples  profile roughly this many chunks, spread
     *                                    across the plan, instead of every one
     * @return Collection<int, \Matchish\ScoutElasticSearch\Jobs\Stages\StageInterface>
     */
    public static function fromSource(ImportSource $source, bool $profile = false, ?string $owner = null, ?int $profileSamples = null)
    {
        $index = Index::fromSource($source);

        // Sampling matters just as much here as on the --parallel path: a
        // sequential import of the same table has the identical chunk count, so
        // profiling every chunk floods the log exactly the same way.
        /** @var array<int, \Matchish\ScoutElasticSearch\Jobs\Stages\StageInterface> $pullStages */
        $pullStages = PullFromSource::chunked($source, $profile, $profileSamples)->all();

        return new self(array_merge([
            new CleanUp($source, $owner),
            new CreateWriteIndex($source, $index),
        ], $pullStages, [
            new RefreshIndex($index),
            new SwitchToNewAndRemoveOldIndex($source, $index, $owner),
        ]));
    }
}
