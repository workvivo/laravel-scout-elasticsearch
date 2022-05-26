<?php

namespace Matchish\ScoutElasticSearch\Jobs\Stages;

use Illuminate\Support\Collection;
use Matchish\ScoutElasticSearch\Searchable\ImportSource;

/**
 * @internal
 */
final class PullFromSource
{
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
        echo 'PullFromSource 28: '.date('h:i:s.u')."\n";
        $results = $this->source->get()->filter->shouldBeSearchable();
        echo 'PullFromSource 30: '.date('h:i:s.u')."\n";
        if (! $results->isEmpty()) {
            echo 'PullFromSource 32: '.date('h:i:s.u')."\n";
            $results->first()->searchableUsing()->update($results);
            echo 'PullFromSource 34: '.date('h:i:s.u')."\n";
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
     * @param ImportSource $source
     * @return Collection
     */
    public static function chunked(ImportSource $source): Collection
    {
        echo 'PullFromSource 54: '.date('h:i:s.u')."\n";

        return $source->chunked()->map(function ($chunk) {
            return new static($chunk);
        });
        echo 'PullFromSource 59: '.date('h:i:s.u')."\n";
    }
}
