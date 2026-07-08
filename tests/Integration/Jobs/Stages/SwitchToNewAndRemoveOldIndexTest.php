<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs\Stages;

use App\Product;
use Matchish\ScoutElasticSearch\ElasticSearch\Index;
use Matchish\ScoutElasticSearch\ImportLock;
use Matchish\ScoutElasticSearch\Jobs\Stages\SwitchToNewAndRemoveOldIndex;
use Matchish\ScoutElasticSearch\Searchable\DefaultImportSourceFactory;
use stdClass;
use Tests\IntegrationTestCase;

final class SwitchToNewAndRemoveOldIndexTest extends IntegrationTestCase
{
    /**
     * @test
     */
    public function switch_to_new_and_remove_old_index(): void
    {
        $this->elasticsearch->indices()->create([
            'index' => 'products_new',
            'body' => ['aliases' => ['products' => ['is_write_index' => true]]],
        ]);
        $this->elasticsearch->indices()->create([
            'index' => 'products_old',
            'body' => ['aliases' => ['products' => new stdClass()]],
        ]);

        $stage = new SwitchToNewAndRemoveOldIndex(DefaultImportSourceFactory::from(Product::class), new Index('products_new'));
        $stage->handle($this->elasticsearch);

        $newIndexExist = $this->elasticsearch->indices()->exists(['index' => 'products_new']);
        $oldIndexExist = $this->elasticsearch->indices()->exists(['index' => 'products_old']);
        $alias = $this->elasticsearch->indices()->getAlias(['index' => 'products_new']);

        $this->assertTrue($newIndexExist);
        $this->assertFalse($oldIndexExist);
        $this->assertEquals(['products_new' => [
            'aliases' => ['products' => []],
        ]], $alias);
    }

    /**
     * @test
     */
    public function switch_promotes_the_index_while_this_run_still_owns_the_lease(): void
    {
        $this->elasticsearch->indices()->create([
            'index' => 'products_new',
            'body' => ['aliases' => ['products' => ['is_write_index' => true]]],
        ]);
        $this->elasticsearch->indices()->create([
            'index' => 'products_old',
            'body' => ['aliases' => ['products' => new stdClass()]],
        ]);

        $owner = (new ImportLock('products', 3600))->acquire();

        $stage = new SwitchToNewAndRemoveOldIndex(DefaultImportSourceFactory::from(Product::class), new Index('products_new'), $owner);
        $stage->handle($this->elasticsearch);

        $this->assertTrue($this->elasticsearch->indices()->exists(['index' => 'products_new']));
        $this->assertFalse($this->elasticsearch->indices()->exists(['index' => 'products_old']));
        $this->assertEquals(['products_new' => [
            'aliases' => ['products' => []],
        ]], $this->elasticsearch->indices()->getAlias(['index' => 'products_new']));
    }

    /**
     * @test
     */
    public function switch_is_skipped_when_the_lease_belongs_to_a_newer_run(): void
    {
        // The state at the moment a stale run reaches the swap: the currently-live
        // index still serving reads, plus a newer run's in-progress write index.
        // (The stale run's own target index is already gone — the newer run's
        // CleanUp deleted it before creating this one, which is why the two write
        // indices never coexist.) The newer index carries this model's write
        // alias, so an unguarded swap would `remove_index` it.
        $this->elasticsearch->indices()->create([
            'index' => 'products_old',
            'body' => ['aliases' => ['products' => new stdClass()]],
        ]);
        $this->elasticsearch->indices()->create([
            'index' => 'products_newer',
            'body' => ['aliases' => ['products' => ['is_write_index' => true]]],
        ]);

        // The newer run now holds the lease; the stale run carries an old token
        // and its target index name no longer exists.
        (new ImportLock('products', 3600))->acquire();

        $stage = new SwitchToNewAndRemoveOldIndex(DefaultImportSourceFactory::from(Product::class), new Index('products_stale'), 'stale-owner-token');
        $stage->handle($this->elasticsearch);

        // The newer run's in-progress index must survive — this is the race the
        // ownership guard closes.
        $this->assertTrue(
            $this->elasticsearch->indices()->exists(['index' => 'products_newer']),
            'stale run must not delete a newer run\'s in-progress write index'
        );
        // The still-live old index must remain untouched too — no swap happened.
        $this->assertTrue($this->elasticsearch->indices()->exists(['index' => 'products_old']));
        $this->assertEquals(
            ['is_write_index' => true],
            $this->elasticsearch->indices()->getAlias(['index' => 'products_newer'])['products_newer']['aliases']['products'],
            'the newer run\'s write alias must be left intact — no swap happened'
        );
    }
}
