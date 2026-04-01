<?php

namespace Matchish\ScoutElasticSearch\Jobs\Stages;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Matchish\ScoutElasticSearch\Searchable\ImportSource;
use OpenSearch\Client;

/**
 * @internal
 */
final class PullFromSource implements StageInterface
{
    /**
     * @var ImportSource
     */
    private $source;

    /**
     * @param  ImportSource  $source
     */
    public function __construct(ImportSource $source)
    {
        $this->source = $source;
    }

    public function handle(Client $elasticsearch = null): void
    {
        $results = $this->source->get()->filter(function ($item) {
            return $item->shouldBeSearchable();
        });

        if (! $results->isEmpty()) {
            $last_key = $results->last()->getKey();

            Cache::put('scout_import_last_id', $results->last()->getKey());

            $results->first()->searchableUsing()->update($results);
        }
    }

    public function estimate(): int
    {
        return 1;
    }

    public function title(): string
    {
        return 'Indexing...';
    }

    /**
     * @param  ImportSource  $source
     * @return Collection
     */
    public static function chunked(ImportSource $source): Collection
    {
        return $source->chunked()->map(function ($chunk) {
            return new static($chunk);
        });
    }
}
