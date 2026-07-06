<?php

namespace Tests\Integration\Searchable;

use App\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\DB;
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

    public function test_chunked_covers_every_searchable_exactly_once()
    {
        // scout.chunk.searchable is 3 in the test environment (see TestCase).
        $this->createProducts(10);

        $source = new DefaultImportSource(Product::class);
        $chunks = $source->chunked();

        // 10 rows / chunk size 3 => 4 chunks (3 + 3 + 3 + 1).
        $this->assertCount(4, $chunks);

        $importedKeys = $chunks
            ->flatMap(fn (DefaultImportSource $chunk) => $chunk->get()->modelKeys())
            ->sort()
            ->values();

        $expectedKeys = Product::orderBy('id')->pluck('id');

        // Every key is imported once — no gaps, no duplicates across chunks.
        $this->assertEquals($expectedKeys->all(), $importedKeys->all());
    }

    public function test_chunked_seeks_by_key_range_instead_of_offset()
    {
        $this->createProducts(10);

        $source = new DefaultImportSource(Product::class);
        $lastChunk = $source->chunked()->last();

        DB::connection()->enableQueryLog();
        $lastChunk->get();
        $select = collect(DB::connection()->getQueryLog())->last()['query'];
        DB::connection()->disableQueryLog();

        // Keyset pagination: bounded by the primary key, never by OFFSET —
        // this is what keeps the last chunk as fast as the first one.
        $this->assertStringNotContainsStringIgnoringCase('offset', $select);
        $this->assertStringContainsString('>', $select);
        $this->assertStringContainsString('<=', $select);
    }

    public function test_chunked_handles_sparse_keys_after_deletes()
    {
        $this->createProducts(12);

        // Soft-delete a few rows in the middle so the searchable set has gaps
        // in its primary keys. Arithmetic offsets would still work here, but a
        // keyset built from real keys must too.
        Product::whereIn('id', [4, 5, 8])->delete();

        $source = new DefaultImportSource(Product::class);
        $chunks = $source->chunked();

        $importedKeys = $chunks
            ->flatMap(fn (DefaultImportSource $chunk) => $chunk->get()->modelKeys())
            ->sort()
            ->values();

        $expectedKeys = Product::orderBy('id')->pluck('id');

        $this->assertEquals($expectedKeys->all(), $importedKeys->all());
        $this->assertCount(9, $importedKeys);
    }

    public function test_chunked_does_not_silently_skip_rows_when_chunk_size_is_non_positive()
    {
        // A misconfigured chunk size must not produce zero chunks (which would
        // import into an empty index); it falls back to a chunk size of 1.
        $this->app['config']->set('scout.chunk.searchable', 0);
        $this->createProducts(3);

        $chunks = (new DefaultImportSource(Product::class))->chunked();

        $this->assertCount(3, $chunks);

        $importedKeys = $chunks
            ->flatMap(fn (DefaultImportSource $chunk) => $chunk->get()->modelKeys())
            ->sort()
            ->values();

        $this->assertEquals(Product::orderBy('id')->pluck('id')->all(), $importedKeys->all());
    }

    /**
     * Regression test: a model global scope that adds an ORDER BY on a column
     * other than the primary key must not break chunking. Keyset boundaries are
     * built from a key-only ordering (reorder()), so chunks stay self-contained
     * and cover every row — with no shared cursor state between them.
     */
    public function test_chunked_visits_every_row_when_model_has_ordering_global_scope(): void
    {
        $dispatcher = Product::getEventDispatcher();
        Product::unsetEventDispatcher();

        $this->app['config']->set('scout.chunk.searchable', 2);

        // Rows whose `price` is inversely correlated with `id`, so ORDER BY
        // price ASC is the exact reverse of ORDER BY id ASC — the simplest
        // shape that would expose a boundary computed on the wrong column.
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
            $source = new DefaultImportSource(Product::class);

            // No cache priming, no manual cursor — chunks run independently.
            $seen = $source->chunked()
                ->flatMap(fn (DefaultImportSource $chunk) => $chunk->get()->modelKeys())
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->sort()
                ->values()
                ->all();

            $missing = array_values(array_diff($expectedIds, $seen));

            $this->assertSame(
                [],
                $missing,
                'Chunked import dropped rows: ['.implode(',', $missing).']. '
                .'Visited ids: ['.implode(',', $seen).']. '
                .'Expected: ['.implode(',', $expectedIds).'].'
            );
        } finally {
            $this->removeGlobalScopeFromProduct('test_order_by_price');
        }
    }

    private function createProducts(int $amount): void
    {
        $dispatcher = Product::getEventDispatcher();
        Product::unsetEventDispatcher();

        factory(Product::class, $amount)->create();

        Product::setEventDispatcher($dispatcher);
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
