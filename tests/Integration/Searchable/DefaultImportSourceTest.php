<?php

namespace Tests\Integration\Searchable;

use App\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Cache;
use Matchish\ScoutElasticSearch\Searchable\DefaultImportSource;
use Tests\TestCase;

class DefaultImportSourceTest extends TestCase
{
    public function test_new_query_has_injected_scopes()
    {
        $dispatcher = Product::getEventDispatcher();
        Product::unsetEventDispatcher();

        $iphonePromoUsedAmount = rand(1, 5);
        $iphonePromoNewAmount = rand(6, 10);

        factory(Product::class, $iphonePromoUsedAmount)->states(['iphone', 'promo', 'used'])->create();
        factory(Product::class, $iphonePromoNewAmount)->states(['iphone', 'promo', 'new'])->create();

        Product::setEventDispatcher($dispatcher);
        $source = new DefaultImportSource(Product::class, [new UsedScope()]);
        $products = $source->get();
        $this->assertEquals($iphonePromoUsedAmount, $products->count());
    }

    /**
     * Regression test: when a model has a global scope that adds an ORDER BY
     * on a column other than the primary key, PageScope's keyset pagination
     * on `id` is silently broken and rows are skipped across chunks.
     */
    public function test_chunked_iteration_visits_every_row_when_model_has_ordering_global_scope(): void
    {
        $dispatcher = Product::getEventDispatcher();
        Product::unsetEventDispatcher();

        $this->app['config']->set('scout.chunk.searchable', 2);

        // Create rows whose `price` is inversely correlated with `id`, so a
        // competing ORDER BY price ASC produces a row order that is the exact
        // reverse of ORDER BY id ASC. This is the simplest deterministic
        // shape that exposes the chunking bug.
        $totalRows = 10;
        for ($i = 1; $i <= $totalRows; $i++) {
            factory(Product::class)->create(['price' => $totalRows - $i]);
        }

        $expectedIds = Product::query()->orderBy('id')->pluck('id')->all();

        Product::addGlobalScope('test_order_by_price', function (Builder $builder) {
            $builder->orderBy('price', 'asc');
        });

        Product::setEventDispatcher($dispatcher);

        try {
            Cache::forget('scout_import_last_id');

            $source = new DefaultImportSource(Product::class);
            $chunks = $source->chunked();

            $seen = [];

            foreach ($chunks as $chunkSource) {
                $results = $chunkSource->get();
                if ($results->isEmpty()) {
                    continue;
                }
                foreach ($results as $row) {
                    $seen[] = (int) $row->id;
                }
                Cache::put('scout_import_last_id', $results->last()->getKey());
            }

            $uniqueSeen = array_values(array_unique($seen));
            sort($uniqueSeen);

            $missing = array_values(array_diff($expectedIds, $uniqueSeen));

            $this->assertSame(
                [],
                $missing,
                'Chunked import dropped rows: ['.implode(',', $missing).']. '
                .'Visited ids: ['.implode(',', $uniqueSeen).']. '
                .'Expected: ['.implode(',', $expectedIds).'].'
            );
        } finally {
            Cache::forget('scout_import_last_id');
            $this->removeGlobalScopeFromProduct('test_order_by_price');
        }
    }

    private function removeGlobalScopeFromProduct(string $identifier): void
    {
        $ref = new \ReflectionClass(Model::class);
        $prop = $ref->getProperty('globalScopes');
        $prop->setAccessible(true);
        $all = $prop->getValue();
        if (isset($all[Product::class][$identifier])) {
            unset($all[Product::class][$identifier]);
            $prop->setValue(null, $all);
        }
    }
}

class UsedScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $builder
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     */
    public function apply(Builder $builder, Model $model)
    {
        $builder->where('type', 'used');
    }
}
