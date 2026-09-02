<?php

namespace Tests\Integration\Jobs\Stages;

use App\Product;
use Matchish\ScoutElasticSearch\ImportLock;
use Matchish\ScoutElasticSearch\Jobs\Stages\CleanUp;
use Matchish\ScoutElasticSearch\Searchable\DefaultImportSourceFactory;
use stdClass;
use Tests\IntegrationTestCase;

class CleanUpTest extends IntegrationTestCase
{
    public function test_remove_write_index()
    {
        $this->elasticsearch->indices()->create([
            'index' => 'products_old',
            'body' => ['aliases' => ['products' => new stdClass()]],
        ]);
        $this->elasticsearch->indices()->create([
            'index' => 'products_new',
            'body' => ['aliases' => ['products' => ['is_write_index' => true], 'products1' => ['is_write_index' => true]]],
        ]);
        $this->elasticsearch->indices()->create([
            'index' => 'products_third',
            'body' => ['aliases' => ['products' => ['is_write_index' => false]]],
        ]);

        $stage = new CleanUp(DefaultImportSourceFactory::from(Product::class));
        $stage->handle($this->elasticsearch);
        $writeIndexExist = $this->elasticsearch->indices()->exists(['index' => 'products_new']);
        $readIndexExist = $this->elasticsearch->indices()->exists(['index' => 'products_old']);

        $this->assertFalse($writeIndexExist);
        $this->assertTrue($readIndexExist);
    }

    public function test_clean_up_deletes_the_write_index_when_still_the_lock_owner()
    {
        $this->elasticsearch->indices()->create([
            'index' => 'products_new',
            'body' => ['aliases' => ['products' => ['is_write_index' => true]]],
        ]);

        $owner = (new ImportLock('products', 3600))->acquire();

        $stage = new CleanUp(DefaultImportSourceFactory::from(Product::class), $owner);
        $stage->handle($this->elasticsearch);

        $this->assertFalse($this->elasticsearch->indices()->exists(['index' => 'products_new']));
    }

    public function test_clean_up_skips_the_delete_when_the_lease_belongs_to_another_run()
    {
        $this->elasticsearch->indices()->create([
            'index' => 'products_new',
            'body' => ['aliases' => ['products' => ['is_write_index' => true]]],
        ]);

        // Another run holds the lease; our stale owner token must not delete the
        // write index that now belongs to that newer run.
        (new ImportLock('products', 3600))->acquire();

        $stage = new CleanUp(DefaultImportSourceFactory::from(Product::class), 'stale-owner-token');
        $stage->handle($this->elasticsearch);

        $this->assertTrue(
            $this->elasticsearch->indices()->exists(['index' => 'products_new']),
            'CleanUp must not delete a write index once another run owns the lease'
        );
    }
}
