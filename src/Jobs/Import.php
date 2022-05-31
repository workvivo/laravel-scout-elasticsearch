<?php

declare(strict_types=1);

namespace Matchish\ScoutElasticSearch\Jobs;

use Elasticsearch\Client;
use Illuminate\Bus\Queueable;
use Imtigger\LaravelJobStatus\Trackable;
use Matchish\ScoutElasticSearch\ProgressReportable;
use Matchish\ScoutElasticSearch\Searchable\ImportSource;

/**
 * @internal
 */
final class Import
{
    use Trackable;
    use Queueable;
    use ProgressReportable;

    /**
     * @var ImportSource
     */
    private $source;

    /**
     * @param ImportSource $source
     */
    public function __construct(ImportSource $source)
    {
        $this->source = $source;
    }

    public function handle(): void
    {
        list($stages, $estimate) = $this->stages();

        $this->progressMax = $estimate;
        $stages->each(function ($stage) {
            if (! is_iterable($stage)) {
                // @phpstan-ignore-next-line
                app()->call([$stage, 'handle']);

                return;
            }
            $queues = $this->source->syncWithSearchUsingQueues();
            foreach ($stage as $key => $sub) {
                $queue = $queues[$key % count($queues)];
                dispatch((new TrackableJob())->chain([$sub])->allOnConnection($this->source->syncWithSearchUsing())
                ->allOnQueue($queue));
            }
        });
    }

    private function stages(): array
    {
        return ImportStages::fromSource($this->source);
    }
}
