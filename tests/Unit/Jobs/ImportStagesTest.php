<?php

namespace Tests\Unit\Jobs;

use App\Product;
use Matchish\ScoutElasticSearch\Jobs\ImportStages;
use Matchish\ScoutElasticSearch\Jobs\Stages\CleanLastId;
use Matchish\ScoutElasticSearch\Jobs\Stages\CleanUp;
use Matchish\ScoutElasticSearch\Jobs\Stages\CreateWriteIndex;
use Matchish\ScoutElasticSearch\Jobs\Stages\PullFromSource;
use Matchish\ScoutElasticSearch\Jobs\Stages\RefreshIndex;
use Matchish\ScoutElasticSearch\Jobs\Stages\SwitchToNewAndRemoveOldIndex;
use Matchish\ScoutElasticSearch\Searchable\DefaultImportSourceFactory;
use Tests\TestCase;

class ImportStagesTest extends TestCase
{
    /**
     * @test
     */
    public function no_pull_stages_if_no_searchables()
    {
        $stages = ImportStages::fromSource(DefaultImportSourceFactory::from(Product::class));
        $this->assertEquals(5, $stages->count());
        $this->assertInstanceOf(CleanUp::class, $stages->get(0));
        $this->assertInstanceOf(CreateWriteIndex::class, $stages->get(1));
        $this->assertInstanceOf(CleanLastId::class, $stages->get(2));
        $this->assertInstanceOf(RefreshIndex::class, $stages->get(3));
        $this->assertInstanceOf(SwitchToNewAndRemoveOldIndex::class, $stages->get(4));
    }

    /**
     * @test
     */
    public function stages()
    {
        factory(Product::class, 10)->create();
        $stages = ImportStages::fromSource(DefaultImportSourceFactory::from(Product::class));
        $this->assertEquals(9, $stages->count());
        $this->assertInstanceOf(CleanUp::class, $stages->get(0));
        $this->assertInstanceOf(CreateWriteIndex::class, $stages->get(1));
        $this->assertInstanceOf(CleanLastId::class, $stages->get(2));
        $this->assertInstanceOf(PullFromSource::class, $stages->get(3));
        $this->assertInstanceOf(PullFromSource::class, $stages->get(4));
        $this->assertInstanceOf(PullFromSource::class, $stages->get(5));
        $this->assertInstanceOf(PullFromSource::class, $stages->get(6));
        $this->assertInstanceOf(RefreshIndex::class, $stages->get(7));
        $this->assertInstanceOf(SwitchToNewAndRemoveOldIndex::class, $stages->get(8));
    }
}
