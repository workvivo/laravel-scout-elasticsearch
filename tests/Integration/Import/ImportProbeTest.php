<?php

declare(strict_types=1);

namespace Tests\Integration\Import;

use App\Product;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Matchish\ScoutElasticSearch\ElasticSearch\Params\Bulk;
use Matchish\ScoutElasticSearch\Import\ImportProbe;
use Matchish\ScoutElasticSearch\Import\ProfileDiagnostics;
use Matchish\ScoutElasticSearch\ImportLock;
use Matchish\ScoutElasticSearch\Searchable\DefaultImportSource;
use Matchish\ScoutElasticSearch\Searchable\ImportSource;
use Matchish\ScoutElasticSearch\Searchable\ImportSourceFactory;
use RuntimeException;
use stdClass;
use Tests\IntegrationTestCase;

/**
 * `--probe` measured against a real cluster.
 *
 * The probe exists to answer "how long does one chunk take?" and "is there an
 * N+1 in toSearchableArray()?" in seconds, by really indexing a handful of real
 * chunks. That means it really writes documents — and everything that makes it
 * SAFE to do that on a production table is a property of exactly one thing: the
 * index it writes to.
 *
 * So the first three tests here are safety tests, and they matter more than
 * everything that follows:
 *
 *  1. THE ALIAS IS NEVER TOUCHED. `Bulk` targets `searchableAs()`, i.e. the
 *     ALIAS, and `CreateWriteIndex` would make a new index the alias's
 *     `is_write_index` — which would divert the APPLICATION's own concurrent
 *     `searchable()` writes into an index the probe then DELETES, losing them
 *     permanently. {@see the_probe_never_touches_the_live_alias} pins the alias
 *     both after the probe and, through the per-chunk log seam, WHILE the probe
 *     index exists, which is the only window in which the damage could happen.
 *  2. THE PROBE INDEX IS ALWAYS DELETED, including when a sampled chunk throws
 *     — and the throw is reported, not swallowed.
 *  3. THE LIVE INDEX IS NEVER WRITTEN: the sampled documents land in the
 *     throwaway index (counted mid-run, before teardown removes it) and the live
 *     document count does not move.
 *
 * Timing-derived numbers depend on the machine and the cluster, so nothing here
 * asserts a duration. What is asserted about the measurements is their SHAPE and
 * their internal consistency (which chunks were sampled, the row counts, and the
 * arithmetic tying the aggregates to the extrapolation), plus the one finding
 * that can be made deterministic: the N+1 provoked by {@see ProbeLazyProduct}.
 */
final class ImportProbeTest extends IntegrationTestCase
{
    /** Chunk size is 3 (see TestCase), so 5 rows plan as 3 + 2. */
    private const PRODUCTS = 5;

    private const CHUNKS = 2;

    public function setUp(): void
    {
        parent::setUp();

        // SCOUT_QUEUE_TIMEOUT is what the command hands the probe as the chunk
        // timeout, and it decides whether the two timeout rules in
        // ProfileDiagnostics can fire at all. Pinned to unset so the set of
        // findings a probe reports here cannot depend on how fast this machine
        // happens to be.
        config()->set('elasticsearch.queue.timeout', null);
    }

    // ---- 1. the alias -------------------------------------------------------

    /**
     * @test
     */
    public function the_probe_never_touches_the_live_alias(): void
    {
        $this->products(self::PRODUCTS);
        $this->importForReal();

        $aliasBefore = $this->aliasMap();
        $this->assertNotSame([], $aliasBefore, 'The premise of this test is a live alias to protect.');

        // Observed at the one moment it could go wrong: the probe index exists,
        // has been written to, and has not been deleted yet. Recorded rather
        // than asserted inline, because a throw in here would be caught by the
        // probe's own per-chunk guard and turn into a failed sample instead of a
        // failed test (the aggregates are asserted below to rule that out).
        $observed = [];
        $this->onEachProbedChunk(function () use (&$observed): void {
            $probeIndex = $this->soleProbeIndex();

            $observed[] = [
                'probe_index' => $probeIndex,
                'in_live_alias' => $probeIndex !== null && $this->elasticsearch->indices()->existsAlias([
                    'name' => 'products',
                    'index' => $probeIndex,
                ]),
                'alias' => $this->aliasMap(),
            ];
        });

        $report = (new ImportProbe($this->source()))->run(3, null);

        $this->assertSame(0, $report['aggregates']['failed'], 'Every sample must have been measured.');
        $this->assertCount(self::CHUNKS, $observed, 'The mid-flight observation has to have happened.');

        foreach ($observed as $snapshot) {
            $this->assertNotNull($snapshot['probe_index'], 'The probe must write to an index of its own.');
            $this->assertFalse(
                $snapshot['in_live_alias'],
                'The probe index must never be added to the alias: an aliased probe index becomes the write index and swallows the application\'s own searchable() writes, which the probe then deletes.'
            );
            $this->assertEquals(
                $aliasBefore,
                $snapshot['alias'],
                'The live alias must not gain, lose or re-point a single index while the probe runs.'
            );
        }

        // And after: same indices, same write index, and nothing named like a
        // probe anywhere near the alias.
        $this->assertEquals($aliasBefore, $this->aliasMap());

        foreach (array_keys($this->aliasMap()) as $index) {
            $this->assertStringNotContainsString('_probe_', (string) $index);
        }
    }

    // ---- 2. the throwaway index --------------------------------------------

    /**
     * @test
     */
    public function a_successful_probe_deletes_its_index_and_dispatches_nothing(): void
    {
        Bus::fake();
        $this->products(self::PRODUCTS);

        $report = (new ImportProbe($this->source()))->run(3, null);

        // Nothing queued, nothing to wait for: the probe is synchronous and
        // in-process by design, so an operator gets the answer without a worker.
        Bus::assertNothingDispatched();

        $this->assertTrue($report['created']);
        $this->assertTrue($report['torn_down']);
        $this->assertSame([], $report['warnings']);

        // The name is what makes the teardown legal: removeUnpromotedIndex()
        // refuses anything not prefixed with searchableAs().'_', and `probe`
        // makes a leftover self-explanatory in _cat/indices.
        $this->assertStringStartsWith('products_probe_', $report['index']);

        $this->assertFalse(
            $this->elasticsearch->indices()->exists(['index' => $report['index']]),
            'The probe index must not survive the probe.'
        );
        $this->assertSame([], $this->probeIndices(), 'No probe index may be left anywhere on the cluster.');
    }

    /**
     * @test
     */
    public function a_sample_that_throws_still_deletes_the_probe_index_and_reports_the_failure(): void
    {
        $this->products(self::PRODUCTS);

        // A model whose toSearchableArray() explodes: the failure mode a probe
        // is most likely to hit on a real table, and the one where an
        // unconditional teardown matters most — a probe that leaves its index
        // behind every time serialization fails leaks an index per attempt.
        $report = (new ImportProbe($this->source(ExplodingProduct::class)))->run(3, null);

        $this->assertTrue(
            $report['ok'],
            'The probe RAN — it created its index and worked the plan — so it is not a failed probe; the failure belongs to the samples.'
        );
        $this->assertTrue($report['created']);
        $this->assertTrue($report['torn_down'], 'Teardown must run from the finally even when a sample threw.');
        $this->assertFalse($this->elasticsearch->indices()->exists(['index' => $report['index']]));
        $this->assertSame([], $this->probeIndices());

        // Surfaced, not swallowed: every sampled chunk is accounted for, with
        // the reason it produced no numbers.
        $this->assertCount(self::CHUNKS, $report['samples']);

        foreach ($report['samples'] as $sample) {
            $this->assertNull($sample['metrics']);
            $this->assertStringContainsString('toSearchableArray exploded', (string) $sample['error']);
        }

        // Nothing was measured, so nothing may be claimed: no aggregates, no
        // extrapolation built on zero samples, and no clean bill of health.
        $this->assertSame(0, $report['aggregates']['measured']);
        $this->assertSame(self::CHUNKS, $report['aggregates']['failed']);
        $this->assertNull($report['estimate']);
        $this->assertSame([], $report['findings']);
    }

    // ---- 3. the live index --------------------------------------------------

    /**
     * @test
     */
    public function the_sampled_documents_land_in_the_probe_index_and_never_in_the_live_one(): void
    {
        $this->products(self::PRODUCTS);
        $this->importForReal();

        $liveBefore = $this->indexedCount('products');
        $this->assertSame(self::PRODUCTS, $liveBefore);

        // Counted while the probe index still exists — the only place the
        // documents can be observed at all, since the probe deletes the index on
        // the way out. One observation per sampled chunk, so the counts also
        // show the documents arriving chunk by chunk.
        $counts = [];
        $this->onEachProbedChunk(function () use (&$counts): void {
            $probeIndex = $this->soleProbeIndex();

            $counts[] = $probeIndex === null ? null : $this->indexedCount($probeIndex);
        });

        $report = (new ImportProbe($this->source()))->run(2, null);

        $this->assertSame(0, $report['aggregates']['failed'], 'Every sample must have been measured.');
        $this->assertSame(
            [3, self::PRODUCTS],
            $counts,
            'Chunk 0 (3 rows) then chunk 1 (2 more) must be visible in the probe index, and nowhere else.'
        );

        $this->assertSame(
            $liveBefore,
            $this->indexedCount('products'),
            'THE LIVE INDEX IS NOT WRITTEN: a probe adds no document to what the application reads.'
        );
    }

    // ---- sampling -----------------------------------------------------------

    /**
     * @test
     */
    public function sampling_spreads_across_the_plan_instead_of_taking_the_first_chunks(): void
    {
        // Chunk 0 sits on the lowest keys with the coldest caches and a run of
        // consecutive early chunks says nothing about the heavy tail that
        // decides whether a chunk trips its timeout, so the stride math is a
        // correctness property of the probe, not a detail.
        $this->assertSame([0, 33, 66], ImportProbe::sampleIds(100, 3));
        $this->assertSame([0, 2, 4, 6], ImportProbe::sampleIds(10, 4));

        // Degenerate inputs that must still produce something measurable.
        $this->assertSame([0, 1], ImportProbe::sampleIds(2, 3), 'A plan smaller than the target is measured whole.');
        $this->assertSame([0], ImportProbe::sampleIds(7, 0), 'A non-positive target still measures one chunk.');
        $this->assertSame([], ImportProbe::sampleIds(0, 3), 'An empty plan samples nothing.');

        // The same spread against a real 10-chunk plan (30 rows at chunk 3).
        $this->products(30);

        $report = (new ImportProbe($this->source()))->run(3, null);

        $this->assertSame(10, $report['total_chunks']);
        $this->assertSame(3, $report['chunk_size']);
        $this->assertSame([0, 3, 6], $report['sampled']);
        $this->assertNotSame([0, 1, 2], $report['sampled'], 'The first N chunks are exactly what must NOT be sampled.');
        $this->assertSame([0, 3, 6], array_column($report['samples'], 'chunk_id'));

        // Three chunks of three rows: the sampled chunks were really worked, and
        // only they were.
        $this->assertSame(9, $report['aggregates']['fetched']);
        $this->assertSame(9, $report['aggregates']['indexed']);
    }

    /**
     * @test
     */
    public function a_plan_smaller_than_the_sample_count_probes_every_chunk(): void
    {
        $this->products(self::PRODUCTS);

        $report = (new ImportProbe($this->source()))->run(50, null);

        $this->assertSame(self::CHUNKS, $report['total_chunks']);
        $this->assertSame([0, 1], $report['sampled'], 'Asking for more samples than there are chunks measures all of them, never zero.');
        $this->assertSame(self::PRODUCTS, $report['aggregates']['fetched']);
        $this->assertSame(self::CHUNKS, $report['aggregates']['measured']);
    }

    // ---- the report ---------------------------------------------------------

    /**
     * @test
     */
    public function the_report_carries_the_plan_the_metrics_the_aggregates_the_estimate_and_deduped_findings(): void
    {
        $this->products(self::PRODUCTS);

        $report = (new ImportProbe($this->source(ProbeLazyProduct::class)))->run(3, null);

        $this->assertTrue($report['ok']);
        $this->assertSame('products', $report['searchable']);
        $this->assertSame(self::CHUNKS, $report['total_chunks'], 'The extrapolation is meaningless without the total.');
        $this->assertSame([0, 1], $report['sampled']);

        // Per-sample metrics are PullFromSource::lastProfile() verbatim — the
        // production measurement path — so the numbers the probe prints are the
        // numbers a real import would have produced.
        $this->assertCount(self::CHUNKS, $report['samples']);

        $first = $report['samples'][0];
        $this->assertSame(0, $first['chunk_id']);
        $this->assertNull($first['error']);
        $this->assertNotNull($first['metrics']);
        $this->assertSame(DefaultImportSource::class, $first['metrics']['source']);
        $this->assertSame(3, $first['metrics']['fetched']);
        $this->assertSame(3, $first['metrics']['indexed']);

        foreach (['fetch_ms', 'filter_ms', 'index_ms', 'serialize_ms', 'bulk_ms', 'payload_kb', 'total_ms', 'queries', 'lazy_loads'] as $key) {
            $this->assertArrayHasKey($key, $first['metrics']);
        }

        $aggregates = $report['aggregates'];
        $this->assertSame(self::CHUNKS, $aggregates['measured']);
        $this->assertSame(0, $aggregates['failed']);
        $this->assertSame(self::PRODUCTS, $aggregates['fetched']);
        $this->assertSame(self::PRODUCTS, $aggregates['indexed']);
        $this->assertGreaterThan(0, $aggregates['mean_ms']);
        $this->assertLessThanOrEqual($aggregates['median_ms'], $aggregates['min_ms']);
        $this->assertLessThanOrEqual($aggregates['max_ms'], $aggregates['median_ms']);
        $this->assertLessThanOrEqual($aggregates['max_ms'], $aggregates['mean_ms']);
        $this->assertGreaterThanOrEqual($aggregates['min_ms'], $aggregates['mean_ms']);

        // The extrapolation: the mean chunk times every chunk in the plan, in
        // serial seconds only — dividing across workers is the command's job,
        // because the worker count is an operator's assumption and not something
        // the probe measured.
        $estimate = $report['estimate'];
        $this->assertNotNull($estimate);
        $this->assertSame(self::CHUNKS, $estimate['chunks']);
        $this->assertSame(self::CHUNKS, $estimate['measured']);
        // The extrapolation runs off the PRODUCTION-equivalent mean, not the
        // measured one. Profiling serializes every document a second time to
        // split serialize_ms from bulk_ms, and that pass sits inside total_ms —
        // multiplying the measured mean by the chunk count would carry the
        // overhead into the estimate once per chunk. The measured mean travels
        // alongside it so the renderer can explain the gap.
        $this->assertSame(round($aggregates['mean_production_ms'] / 1000, 3), $estimate['mean_seconds']);
        $this->assertSame(round($aggregates['mean_ms'] / 1000, 3), $estimate['measured_mean_seconds']);
        $this->assertSame(round($aggregates['mean_production_ms'] / 1000 * self::CHUNKS, 1), $estimate['serial_seconds']);

        // Serialization cannot cost negative time, so the production mean never
        // exceeds the measured one, and the reported overhead is exactly the gap.
        $this->assertLessThanOrEqual($aggregates['mean_ms'], $aggregates['mean_production_ms']);
        $this->assertSame(
            round($aggregates['mean_ms'] - $aggregates['mean_production_ms'], 1),
            $aggregates['overhead_ms']
        );
        $this->assertSame(round($aggregates['overhead_ms'] / 1000, 3), $estimate['overhead_seconds']);

        // Both sampled chunks lazy-load `variants`, so the two diagnoses fold
        // into ONE row per code carrying the occurrence count and the WORST
        // example — chunk 0 (3 rows, 3 loads), not the smaller chunk 1.
        $codes = array_column($report['findings'], 'code');
        $this->assertContains(ProfileDiagnostics::CODE_N_PLUS_ONE, $codes);
        $this->assertCount(
            1,
            array_keys($codes, ProfileDiagnostics::CODE_N_PLUS_ONE, true),
            'Findings are deduped by code: two chunks tripping the same rule is one row, not two.'
        );

        $nPlusOne = $report['findings'][array_search(ProfileDiagnostics::CODE_N_PLUS_ONE, $codes, true)];
        $this->assertSame(self::CHUNKS, $nPlusOne['count']);
        $this->assertSame(3.0, $nPlusOne['weight'], 'The kept example is the heaviest one measured.');
        $this->assertSame(ProbeLazyProduct::class.'::variants', $nPlusOne['data']['relation']);
        $this->assertSame(3, $nPlusOne['data']['loads']);
        $this->assertSame(1, $nPlusOne['data']['relations']);
    }

    /**
     * @test
     */
    public function an_empty_plan_is_a_successful_probe_that_creates_nothing(): void
    {
        $report = (new ImportProbe($this->source()))->run(3, null);

        $this->assertTrue($report['ok'], 'Nothing to measure is not a failure.');
        $this->assertSame(0, $report['total_chunks']);
        $this->assertSame([], $report['sampled']);
        $this->assertSame([], $report['samples']);
        $this->assertNull($report['estimate']);

        // No index was created, so none had to be deleted — and the report says
        // exactly that rather than claiming a teardown it never performed.
        $this->assertFalse($report['created']);
        $this->assertFalse($report['torn_down']);
        $this->assertSame([], $this->probeIndices());
    }

    /**
     * @test
     */
    public function a_probe_index_that_cannot_be_created_fails_the_probe_and_leaves_nothing_behind(): void
    {
        // The one class of failure that is a FAILED PROBE rather than a failed
        // sample: without its own index the probe has nowhere safe to write, and
        // writing anywhere else is precisely what it must never do. A mapping
        // the cluster rejects is the cheapest way to reach that state.
        $this->app['config']->set('elasticsearch.indices.mappings.products', [
            'properties' => ['price' => ['type' => 'no_such_type']],
        ]);
        $this->products(self::PRODUCTS);

        $report = (new ImportProbe($this->source()))->run(3, null);

        $this->assertFalse($report['ok']);
        $this->assertNotNull($report['error']);
        $this->assertFalse($report['created']);
        $this->assertSame([], $report['samples']);
        $this->assertSame([], $this->probeIndices());
    }

    /**
     * @test
     */
    public function a_held_import_lease_is_warned_about_but_stops_nothing_and_is_left_alone(): void
    {
        $this->products(self::PRODUCTS);

        // A real import for this model is running. The probe must not take the
        // lease (it would block a real import, or be blocked by one) and must
        // not fail either — but every number it reports is inflated by the
        // contention, which is worth saying out loud.
        $owner = (new ImportLock('products', 60))->acquire();
        $this->assertNotNull($owner);

        $report = (new ImportProbe($this->source()))->run(2, null);

        $warnings = implode("\n", $report['warnings']);
        $this->assertStringContainsString('appears to be running', $warnings);
        $this->assertStringContainsString(ImportLock::keyFor('products'), $warnings);

        // Warned, not aborted: the measurements are still there.
        $this->assertTrue($report['ok']);
        $this->assertSame(self::CHUNKS, $report['aggregates']['measured']);
        $this->assertNotNull($report['estimate']);
        $this->assertTrue($report['torn_down']);

        $this->assertTrue(
            ImportLock::isHeldBy('products', (string) $owner),
            'The probe may neither acquire nor release the lease: the running import still owns it afterwards.'
        );
    }

    // ---- the redirection seam the whole design rests on ---------------------

    /**
     * @test
     */
    public function a_default_bulk_payload_still_targets_the_alias(): void
    {
        // The safety property in reverse: every existing caller gets a
        // byte-identical payload, so adding the probe's redirection cannot have
        // moved a single production write.
        $bulk = new Bulk();
        $bulk->index($this->product(2));
        $bulk->delete($this->product(3));

        $this->assertSame([
            ['index' => ['_index' => 'products', '_id' => 2, 'routing' => 2]],
            ['delete' => ['_index' => 'products', '_id' => 3, 'routing' => 3]],
        ], $this->bulkActions($bulk->toArray()));
    }

    /**
     * @test
     */
    public function a_redirected_bulk_payload_targets_the_override_in_both_branches(): void
    {
        $bulk = new Bulk();
        $bulk->into('products_probe_1700000000_abcdef');
        $bulk->index($this->product(2));
        $bulk->delete($this->product(3));

        // Both branches, because the alias is hardcoded in both: an index action
        // that honoured the override while a delete still went to the alias
        // would delete live documents.
        $this->assertSame([
            ['index' => ['_index' => 'products_probe_1700000000_abcdef', '_id' => 2, 'routing' => 2]],
            ['delete' => ['_index' => 'products_probe_1700000000_abcdef', '_id' => 3, 'routing' => 3]],
        ], $this->bulkActions($bulk->toArray()));
    }

    /**
     * @test
     */
    public function redirecting_a_bulk_back_to_null_is_indistinguishable_from_never_redirecting_it(): void
    {
        $default = new Bulk();
        $default->index($this->product(2));
        $default->delete($this->product(3));

        $restored = new Bulk();
        $restored->into('products_probe_1700000000_abcdef');
        $restored->into(null);
        $restored->index($this->product(2));
        $restored->delete($this->product(3));

        $this->assertSame($default->toArray(), $restored->toArray());
    }

    // ---- harness -----------------------------------------------------------

    /**
     * The import source for a searchable, exactly as the command builds it.
     */
    private function source(string $className = Product::class): ImportSource
    {
        return app(ImportSourceFactory::class)::from($className);
    }

    private function products(int $amount): void
    {
        // Model events off, or Scout indexes each row on create and the probe
        // would no longer be the only writer in the test.
        $dispatcher = Product::getEventDispatcher();
        Product::unsetEventDispatcher();

        factory(Product::class, $amount)->create();

        Product::setEventDispatcher($dispatcher);
    }

    /**
     * A full, ordinary import — the only way to get a live index behind the
     * `products` alias, which is what the safety tests have to protect.
     */
    private function importForReal(): void
    {
        Artisan::call('scout:import', ['searchable' => [Product::class]]);
    }

    /**
     * Run $callback once per profiled chunk, at the one instant the probe index
     * exists and has already been written to.
     *
     * The seam is PullFromSource::handleProfiled()'s own log line, emitted after
     * the chunk's bulk request and before the probe's teardown. Nothing is
     * asserted inside these callbacks anywhere in this file on purpose: the probe
     * catches Throwable per chunk, so a failed assertion in here would be
     * recorded as a failed sample instead of failing the test. Callers collect
     * and then assert, and check `aggregates.failed` to prove nothing was eaten.
     */
    private function onEachProbedChunk(callable $callback): void
    {
        Event::listen(MessageLogged::class, function (MessageLogged $event) use ($callback): void {
            if ($event->message !== 'scout:import chunk profile') {
                return;
            }

            $callback();
        });
    }

    /**
     * Every index on the cluster whose name says it belongs to a probe. Empty is
     * the only acceptable state once a probe has returned.
     *
     * @return array<int, string>
     */
    private function probeIndices(): array
    {
        $names = [];

        foreach ($this->elasticsearch->cat()->indices(['h' => 'index', 'format' => 'json']) as $row) {
            $name = $row['index'] ?? null;

            if (is_string($name) && strpos($name, '_probe_') !== false) {
                $names[] = $name;
            }
        }

        sort($names);

        return $names;
    }

    private function soleProbeIndex(): ?string
    {
        $indices = $this->probeIndices();

        return count($indices) === 1 ? $indices[0] : null;
    }

    /**
     * What the `products` alias resolves to: which concrete indices carry it and
     * with what settings (`is_write_index` above all).
     *
     * @return array<string, mixed>
     */
    private function aliasMap(): array
    {
        if (! $this->elasticsearch->indices()->existsAlias(['name' => 'products'])) {
            return [];
        }

        return $this->elasticsearch->indices()->getAlias(['name' => 'products']);
    }

    private function indexedCount(string $index): int
    {
        $this->elasticsearch->indices()->refresh(['index' => $index]);

        $response = $this->elasticsearch->search([
            'index' => $index,
            'body' => ['query' => ['match_all' => new stdClass()]],
        ]);

        return (int) $response['hits']['total']['value'];
    }

    private function product(int $id): Product
    {
        $product = new Product(['title' => 'Scout']);
        $product->id = $id;

        return $product;
    }

    /**
     * The action descriptors of a bulk payload, i.e. the lines that name the
     * index. The document bodies in between are not what these tests are about.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    private function bulkActions(array $payload): array
    {
        $actions = [];

        foreach ($payload['body'] as $line) {
            if (is_array($line) && (isset($line['index']) || isset($line['delete']))) {
                $actions[] = $line;
            }
        }

        return $actions;
    }
}

/**
 * Test-only Product variant that lazy-loads a relation while building its
 * document — the N+1 a probe exists to find, and the only finding that can be
 * made deterministic.
 *
 * Kept out of tests/laravel/app so SearchableListFactory never discovers it as a
 * searchable of its own, and pinned to the `products` table so it keeps
 * Product's searchableAs() (and therefore the index names the suite already
 * cleans up). The relation is a self-join on the unique per-row custom_key, so
 * each model resolves exactly one cheap row on demand: one lazy-loading
 * violation per model, and a hit count that equals the chunk's size.
 */
class ProbeLazyProduct extends Product
{
    protected $table = 'products';

    /**
     * @return HasMany<Product>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(Product::class, 'custom_key', 'custom_key');
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'title' => $this->title,
            // Not eager-loaded anywhere, so reading it here is the violation.
            'variants' => $this->variants->count(),
        ];
    }
}

/**
 * Test-only Product variant whose document cannot be built at all.
 *
 * The throw lands inside the profiled serialization pass, i.e. after the probe
 * index has been created and before anything has been written to it — the exact
 * window in which a teardown that lived on the happy path would leak an index.
 */
class ExplodingProduct extends Product
{
    protected $table = 'products';

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        throw new RuntimeException('toSearchableArray exploded for product '.$this->getKey());
    }
}
