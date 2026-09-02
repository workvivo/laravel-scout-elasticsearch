<?php

declare(strict_types=1);

namespace Matchish\ScoutElasticSearch\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Matchish\ScoutElasticSearch\ElasticSearch\Index;
use Matchish\ScoutElasticSearch\ImportLock;
use Matchish\ScoutElasticSearch\Searchable\ImportSource;
use OpenSearch\Client;
use OpenSearch\Common\Exceptions\Missing404Exception;

final class RollbackImportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private ImportSource $source;
    private Index $index;
    private ?string $lockOwner;

    public int $tries = 3;
    public ?int $timeout = null;

    public function __construct(ImportSource $source, Index $index, ?string $lockOwner = null)
    {
        $this->source = $source;
        $this->index = $index;
        $this->lockOwner = $lockOwner;
    }

    public function handle(): void
    {
        self::removeUnpromotedIndex($this->source, $this->index, $this->lockOwner);

        if ($this->lockOwner !== null) {
            ImportLock::release($this->source->searchableAs(), $this->lockOwner);
        }
    }

    public static function removeUnpromotedIndex(ImportSource $source, Index $index, ?string $owner = null): void
    {
        $name = $index->name();
        $expectedPrefix = $source->searchableAs().'_';

        $isMultiTarget = $name === '' || $name === '_all' || strpbrk($name, '*,?') !== false;
        $belongsToModel = strpos($name, $expectedPrefix) === 0 && strlen($name) > strlen($expectedPrefix);

        if ($isMultiTarget || ! $belongsToModel) {
            return;
        }

        if ($owner !== null && ! ImportLock::isHeldBy($source->searchableAs(), $owner)) {
            logger()->warning('scout:import rollback skipped: no longer lock owner', [
                'index' => $name,
            ]);

            return;
        }

        $elasticsearch = app(Client::class);

        try {
            $elasticsearch->indices()->delete([
                'index' => $name,
                'expand_wildcards' => 'none',
            ]);
        } catch (Missing404Exception $e) {
            //
        }
    }
}
