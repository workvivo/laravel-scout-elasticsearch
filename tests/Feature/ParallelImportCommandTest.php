<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Product;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Matchish\ScoutElasticSearch\Console\Commands\ImportCommand;
use Matchish\ScoutElasticSearch\Jobs\DispatchPullBatch;
use Matchish\ScoutElasticSearch\Jobs\StageJob;
use stdClass;
use Tests\IntegrationTestCase;

final class ParallelImportCommandTest extends IntegrationTestCase
{
    private function useSyncQueue(): void
    {
        $this->app['config']->set('scout.queue', ['connection' => 'sync', 'queue' => 'scout']);
    }

    private function withoutModelEvents(string $class, callable $callback): void
    {
        $dispatcher = $class::getEventDispatcher();
        $class::unsetEventDispatcher();
        $callback();
        $class::setEventDispatcher($dispatcher);
    }

    private function searchTotal(string $index): int
    {
        $response = $this->elasticsearch->search([
            'index' => $index,
            'body' => ['query' => ['match_all' => new stdClass()]],
        ]);

        return $response['hits']['total']['value'];
    }

    /**
     * @test
     */
    public function parallel_imports_all_records_across_multiple_chunks(): void
    {
        $this->useSyncQueue();

        // Chunk size is 3 (see TestCase), so 10 rows fan out into 4 chunks that
        // each run as their own batched job.
        $productsAmount = 10;
        $this->withoutModelEvents(Product::class, function () use ($productsAmount) {
            factory(Product::class, $productsAmount)->create();
        });

        Artisan::call('scout:import', ['searchable' => [Product::class], '--parallel' => true]);

        $this->assertEquals($productsAmount, $this->searchTotal((new Product())->searchableAs()));
    }

    /**
     * @test
     */
    public function parallel_imports_all_discovered_models_when_none_is_specified(): void
    {
        $this->useSyncQueue();

        $productsAmount = 7; // chunk size 3 => 3 chunks
        $this->withoutModelEvents(Product::class, function () use ($productsAmount) {
            factory(Product::class, $productsAmount)->create();
        });

        // No searchable argument: the command discovers and imports every model.
        Artisan::call('scout:import', ['--parallel' => true]);

        $this->assertEquals($productsAmount, $this->searchTotal((new Product())->searchableAs()));
    }

    /**
     * @test
     */
    public function parallel_requires_a_queue_and_dispatches_nothing_without_one(): void
    {
        Bus::fake();

        // scout.queue is false by default (see TestCase).
        $exitCode = Artisan::call('scout:import', ['searchable' => [Product::class], '--parallel' => true]);

        $this->assertEquals(ImportCommand::FAILURE ?? 1, $exitCode);
        Bus::assertNothingDispatched();
        Bus::assertNothingBatched();
    }

    /**
     * @test
     */
    public function parallel_dispatches_the_prepare_then_fanout_chain(): void
    {
        $this->useSyncQueue();
        Bus::fake();

        Artisan::call('scout:import', ['searchable' => [Product::class], '--parallel' => true]);

        Bus::assertChained([
            StageJob::class,
            StageJob::class,
            DispatchPullBatch::class,
        ]);
    }

    /**
     * @test
     */
    public function parallel_switches_alias_and_removes_old_index(): void
    {
        $this->useSyncQueue();

        // Seed an existing index behind the alias so we can prove the swap.
        $this->elasticsearch->indices()->create([
            'index' => 'products_old',
            'body' => [
                'aliases' => ['products' => new stdClass()],
                'settings' => ['number_of_shards' => 1, 'number_of_replicas' => 0],
            ],
        ]);

        $this->withoutModelEvents(Product::class, function () {
            factory(Product::class, 5)->create();
        });

        Artisan::call('scout:import', ['searchable' => [Product::class], '--parallel' => true]);

        $this->assertFalse(
            $this->elasticsearch->indices()->exists(['index' => 'products_old']),
            'Old index must be removed after the parallel swap'
        );
        $this->assertEquals(5, $this->searchTotal((new Product())->searchableAs()));
    }
}
