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
    /**
     * Route Scout imports through the sync connection. Sync executes batched
     * jobs inline, so parallel imports run end-to-end in the test process — but
     * because it is the sync driver, --parallel needs --force to proceed.
     */
    private function useSyncQueue(): void
    {
        $this->app['config']->set('scout.queue', ['connection' => 'sync', 'queue' => 'scout']);
    }

    /**
     * scout.queue disabled, but the app's default queue is an asynchronous
     * connection — the real-world setup where --parallel should just work.
     */
    private function useAsyncDefaultQueue(): void
    {
        $this->app['config']->set('scout.queue', false);
        $this->app['config']->set('queue.connections.async_test', [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'default',
        ]);
        $this->app['config']->set('queue.default', 'async_test');
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

        Artisan::call('scout:import', ['searchable' => [Product::class], '--parallel' => true, '--force' => true]);

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
        Artisan::call('scout:import', ['--parallel' => true, '--force' => true]);

        $this->assertEquals($productsAmount, $this->searchTotal((new Product())->searchableAs()));
    }

    /**
     * @test
     */
    public function parallel_errors_when_the_resolved_connection_is_sync(): void
    {
        Bus::fake();

        // scout.queue disabled and the default queue is the sync driver: there
        // is no real parallelism to be had, so it fails fast rather than
        // silently running serially.
        $this->app['config']->set('scout.queue', false);
        $this->app['config']->set('queue.default', 'sync');

        $exitCode = Artisan::call('scout:import', ['searchable' => [Product::class], '--parallel' => true]);

        $this->assertEquals(ImportCommand::FAILURE, $exitCode);
        Bus::assertNothingDispatched();
        Bus::assertNothingBatched();
    }

    /**
     * @test
     */
    public function parallel_works_without_scout_queue_on_an_async_default_connection(): void
    {
        $this->useAsyncDefaultQueue();
        Bus::fake();

        // No --force, no scout.queue: it should dispatch onto the app default.
        $exitCode = Artisan::call('scout:import', ['searchable' => [Product::class], '--parallel' => true]);

        $this->assertEquals(ImportCommand::SUCCESS, $exitCode);
        Bus::assertChained([
            StageJob::class,
            StageJob::class,
            DispatchPullBatch::class,
        ]);
    }

    /**
     * @test
     */
    public function force_runs_parallel_inline_on_a_sync_connection(): void
    {
        // scout.queue disabled and sync default — --force runs it inline anyway.
        $this->app['config']->set('scout.queue', false);
        $this->app['config']->set('queue.default', 'sync');

        $this->withoutModelEvents(Product::class, function () {
            factory(Product::class, 5)->create();
        });

        $exitCode = Artisan::call('scout:import', ['searchable' => [Product::class], '--parallel' => true, '--force' => true]);

        $this->assertEquals(ImportCommand::SUCCESS, $exitCode);
        $this->assertEquals(5, $this->searchTotal((new Product())->searchableAs()));
    }

    /**
     * @test
     */
    public function parallel_dispatches_the_prepare_then_fanout_chain(): void
    {
        $this->useSyncQueue();
        Bus::fake();

        Artisan::call('scout:import', ['searchable' => [Product::class], '--parallel' => true, '--force' => true]);

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

        Artisan::call('scout:import', ['searchable' => [Product::class], '--parallel' => true, '--force' => true]);

        $this->assertFalse(
            $this->elasticsearch->indices()->exists(['index' => 'products_old']),
            'Old index must be removed after the parallel swap'
        );
        $this->assertEquals(5, $this->searchTotal((new Product())->searchableAs()));
    }
}
