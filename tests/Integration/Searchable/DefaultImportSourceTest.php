<?php

namespace Tests\Integration\Searchable;

use App\Post;
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

    public function test_with_chunk_size_overrides_the_configured_chunk_size()
    {
        $this->createProducts(10);

        // Config default is 3 (=> 4 chunks); the override wins.
        $withOverride = (new DefaultImportSource(Product::class))->withChunkSize(100);
        $this->assertCount(1, $withOverride->chunked());

        $smaller = (new DefaultImportSource(Product::class))->withChunkSize(5);
        $this->assertCount(2, $smaller->chunked());

        // Coverage is still complete with an overridden size.
        $importedKeys = $smaller->chunked()
            ->flatMap(fn (DefaultImportSource $chunk) => $chunk->get()->modelKeys())
            ->sort()
            ->values();
        $this->assertEquals(Product::orderBy('id')->pluck('id')->all(), $importedKeys->all());
    }

    public function test_non_positive_chunk_override_falls_back_to_a_safe_size()
    {
        $this->createProducts(4);

        // 0 or negative must not silently produce zero chunks (empty index).
        $this->assertGreaterThan(0, (new DefaultImportSource(Product::class))->withChunkSize(0)->chunked()->count());
    }

    public function test_fast_plan_indexes_the_same_records_as_join_aware_planning()
    {
        $dispatcher = Post::getEventDispatcher();
        Post::unsetEventDispatcher();

        // Post::makeAllSearchableUsing filters to published — a filtering clause
        // behaves exactly like a filtering inner join for planning purposes.
        // Interleave published/draft so the two strategies produce genuinely
        // different chunk boundaries: join-aware planning chunks over published
        // rows only, fast planning chunks over every row.
        for ($i = 0; $i < 12; $i++) {
            factory(Post::class)->states($i % 2 === 0 ? 'published' : 'draft')->create();
        }

        Post::setEventDispatcher($dispatcher);

        $indexedKeys = function (DefaultImportSource $source) {
            return $source->chunked()
                ->flatMap(fn (DefaultImportSource $chunk) => $chunk->get()
                    ->filter(fn ($model) => $model->shouldBeSearchable())
                    ->modelKeys())
                ->sort()
                ->values()
                ->all();
        };

        $joinAware = new DefaultImportSource(Post::class);
        $fast = (new DefaultImportSource(Post::class))->withFastPlan();

        // Whether the two strategies produce different chunk counts is an
        // implementation detail (seek planning over an interleaved published
        // set gives fewer chunks; arithmetic planning over min..max gives the
        // same count as the fast path). The invariant that matters is that
        // both strategies index exactly the same records — the published set
        // in full and never a draft — because the per-chunk fetch still runs
        // makeAllSearchableUsing and shouldBeSearchable regardless of how
        // wide the planned ranges are.
        $published = Post::where('status', 'published')->orderBy('id')->pluck('id')->all();
        $this->assertEquals($published, $indexedKeys($joinAware));
        $this->assertEquals($published, $indexedKeys($fast));
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

    /**
     * Regression test: a model global scope that adds a WHERE, JOIN, or extra
     * ORDER BY turns the planning key scan into a filtered/joined/filesorted
     * scan on large tables. Fast-plan mode must strip global scopes at
     * planning time so the key scan stays index-friendly; coverage stays
     * complete because the per-chunk fetch still applies them.
     *
     * Exercises both WHERE and ORDER BY scopes together so it fails if either
     * kind ever leaks back into the planning query.
     */
    public function test_fast_plan_planning_query_does_not_include_model_global_scope_filters(): void
    {
        $this->createProducts(6);

        Product::addGlobalScope('test_filter_by_price', function (Builder $builder) {
            $builder->where('price', '>=', 0);
        });
        // Mirrors the exact real-world pattern the caller reported:
        //   static::addGlobalScope('sortCreatedAt', fn ($b) => $b->orderBy('created_at', 'desc'));
        // 'created_at' is the timestamps column Eloquent adds to Product by
        // default, so this ORDER BY is executable against the schema.
        Product::addGlobalScope('test_order_by_created_at', function (Builder $builder) {
            $builder->orderBy('created_at', 'desc');
        });

        try {
            $source = (new DefaultImportSource(Product::class))->withFastPlan();

            DB::connection()->enableQueryLog();
            $chunks = $source->chunked();
            $planningQueries = collect(DB::connection()->getQueryLog())->pluck('query');
            DB::connection()->disableQueryLog();

            // Chunk-planning select is the one that pluck($key)s from the model
            // table; the global scope's WHERE and ORDER BY must both be absent
            // from every planning query.
            $planningSelects = $planningQueries->filter(fn ($q) => stripos($q, 'select') === 0);
            $this->assertNotEmpty($planningSelects, 'Expected at least one planning select in the query log');
            foreach ($planningSelects as $q) {
                $this->assertStringNotContainsString('price', $q, "Planning query leaked global scope WHERE: $q");
                $this->assertStringNotContainsString('created_at', $q, "Planning query leaked global scope ORDER BY: $q");
            }

            // And coverage is intact: every row is still visited despite
            // planning without the scopes.
            $importedKeys = $chunks
                ->flatMap(fn (DefaultImportSource $chunk) => $chunk->get()->modelKeys())
                ->sort()
                ->values();
            $this->assertEquals(Product::orderBy('id')->pluck('id')->all(), $importedKeys->all());
        } finally {
            $this->removeGlobalScopeFromProduct('test_filter_by_price');
            $this->removeGlobalScopeFromProduct('test_order_by_created_at');
        }
    }

    /**
     * @test
     */
    public function arithmetic_planning_issues_a_single_aggregate_pair_regardless_of_row_count(): void
    {
        // 10 products with chunk size 3 => 4 chunks. Seek planning would emit
        // 4 planning selects; arithmetic must emit exactly two aggregates
        // (MIN, MAX) regardless of how many chunks we end up with.
        $this->createProducts(10);

        DB::connection()->enableQueryLog();
        $chunks = (new DefaultImportSource(Product::class))->chunked();
        $queries = collect(DB::connection()->getQueryLog())->pluck('query');
        DB::connection()->disableQueryLog();

        $aggregates = $queries->filter(fn ($q) => stripos($q, 'min(') !== false || stripos($q, 'max(') !== false);
        $this->assertCount(2, $aggregates, 'Arithmetic planning must emit exactly MIN + MAX, got: '.$queries->implode(' | '));

        // No key-walking selects during planning — the only per-chunk selects
        // should be fetches, which we did not trigger here. Match the seek
        // signature quote-agnostically: `select <quote>products<quote>.<quote>id<quote>` +
        // an `order by ... limit N` tail.
        $planningSelects = $queries->filter(fn ($q) => $this->looksLikeKeyWalkingSelect($q, 'products', 'id'));
        $this->assertCount(0, $planningSelects, 'Arithmetic planning must not walk the key column');

        // Coverage stays exact.
        $importedKeys = $chunks
            ->flatMap(fn (DefaultImportSource $chunk) => $chunk->get()->modelKeys())
            ->sort()
            ->values();
        $this->assertEquals(Product::orderBy('id')->pluck('id')->all(), $importedKeys->all());
    }

    /**
     * @test
     */
    public function arithmetic_planner_falls_back_when_declared_int_key_stores_non_numeric_values(): void
    {
        // BookWithCustomKey declares $keyType='int' and $incrementing=true by
        // inheritance from Model, but its custom_key column actually holds
        // UUIDs (see BookFactory). This misconfiguration is common in older
        // codebases. Arithmetic would cast UUIDs to (int) 0 and index zero
        // rows — the fallback catches it after MIN() reveals a non-numeric
        // value and re-plans via the seek loop, which works for any
        // orderable column type.
        $dispatcher = \App\Book::getEventDispatcher();
        \App\Book::unsetEventDispatcher();
        factory(\App\Book::class, 6)->create();
        \App\Book::setEventDispatcher($dispatcher);

        $source = new DefaultImportSource(\App\BookWithCustomKey::class);

        // Same coverage as seek planning would give — no rows silently lost
        // to a bogus arithmetic range.
        $importedKeys = $source->chunked()
            ->flatMap(fn (DefaultImportSource $chunk) => $chunk->get()->modelKeys())
            ->sort()
            ->values();
        $expected = \App\BookWithCustomKey::orderBy('custom_key')->pluck('custom_key')->sort()->values();

        $this->assertEquals($expected->all(), $importedKeys->all());
        $this->assertCount(6, $importedKeys);
    }

    /**
     * @test
     */
    public function non_incrementing_int_key_falls_back_to_seek_planning(): void
    {
        // NonIncrementingProduct is defined at the bottom of this file; it
        // shares the products table but declares $incrementing = false,
        // mirroring how apps model manually-assigned integer IDs (snowflake,
        // ULID-int, imported ids). Arithmetic over the key range would be
        // meaningful in theory, but Laravel exposes $incrementing as the
        // clean opt-out signal — respect it and take the seek path.
        $this->createProducts(6); // rows share the products table

        DB::connection()->enableQueryLog();
        (new DefaultImportSource(NonIncrementingProduct::class))->chunked();
        $queries = collect(DB::connection()->getQueryLog())->pluck('query');
        DB::connection()->disableQueryLog();

        // Seek path: no aggregates, at least one key-walking select.
        $aggregates = $queries->filter(fn ($q) => stripos($q, 'min(') !== false || stripos($q, 'max(') !== false);
        $this->assertCount(0, $aggregates, 'Non-incrementing model must not use arithmetic planning; got: '.$queries->implode(' | '));

        $planningSelects = $queries->filter(fn ($q) => $this->looksLikeKeyWalkingSelect($q, 'products', 'id'));
        $this->assertGreaterThan(0, $planningSelects->count(), 'Non-incrementing model must fall back to the seek loop. Queries: '.$queries->implode(' | '));
    }

    /**
     * True when the query matches the shape of a seek-loop planning select:
     * `SELECT <table>.<key> FROM ... ORDER BY <table>.<key> ASC LIMIT N`.
     * Identifier-quote agnostic so the test passes on both MySQL (backticks)
     * and SQLite / PostgreSQL (double quotes).
     */
    private function looksLikeKeyWalkingSelect(string $query, string $table, string $key): bool
    {
        $normalized = preg_replace('/[`"\'\[\]]/', '', strtolower($query));

        return str_starts_with($normalized, "select {$table}.{$key} from")
            && str_contains($normalized, 'limit ');
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

/**
 * Test-only variant of Product that shares its table but declares itself
 * non-incrementing — the opt-out signal an app uses to say "my int PK is not
 * densely allocated." Deliberately kept outside tests/laravel/app so
 * SearchableListFactory does not pick it up as a discoverable searchable.
 */
class NonIncrementingProduct extends Product
{
    protected $table = 'products';

    public $incrementing = false;

    public function searchableAs()
    {
        return 'non_incrementing_products';
    }
}
