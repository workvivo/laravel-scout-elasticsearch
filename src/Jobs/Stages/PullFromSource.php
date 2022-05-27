<?php

declare(strict_types=1);

namespace Matchish\ScoutElasticSearch\Jobs\Stages;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\LazyCollection;
use Imtigger\LaravelJobStatus\Trackable;
use Matchish\ScoutElasticSearch\Searchable\ImportSource;

/**
 * @internal
 */
final class PullFromSource
{
    use Queueable;
    private Collection $source;

    public function __construct(Collection $source)
    {
        $this->source = $source;
    }

    public function handle(): void
    {
        echo 'PullFromSource 31: '.date('h:i:s.u')."\n";
        $this->source->each(function ($chunk) {
            $results = $chunk->get()->filter->shouldBeSearchable();
            echo 'PullFromSource 34: '.date('h:i:s.u')."\n";
            if (! $results->isEmpty()) {
                echo 'PullFromSource 36: '.date('h:i:s.u')."\n";
                $results->first()->searchableUsing()->update($results);
                echo 'PullFromSource 38: '.date('h:i:s.u')."\n";
            }
        });
    }

    public function estimate(): int
    {
        return $this->source->count();
    }

    public function title(): string
    {
        return 'Indexing...';
    }

    /**
     * @param ImportSource $source
     * @return LazyCollection
     */
    public static function chunked(ImportSource $source): LazyCollection
    {
        echo 'PullFromSource 59: '.date('h:i:s.u')."\n";

        return $source->chunked()->map(function ($chunks) {
            return new self($chunks);
        });
    }
}
