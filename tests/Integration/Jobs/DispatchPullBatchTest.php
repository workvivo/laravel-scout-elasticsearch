<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs;

use App\Product;
use Illuminate\Support\Facades\Bus;
use Matchish\ScoutElasticSearch\ElasticSearch\Index;
use Matchish\ScoutElasticSearch\ImportLock;
use Matchish\ScoutElasticSearch\Jobs\DispatchPullBatch;
use Matchish\ScoutElasticSearch\Jobs\FinalizeJob;
use Matchish\ScoutElasticSearch\Jobs\PullChunkJob;
use Matchish\ScoutElasticSearch\Jobs\RollbackImportJob;
use Matchish\ScoutElasticSearch\Searchable\ImportSourceFactory;
use stdClass;
use Tests\IntegrationTestCase;

final class DispatchPullBatchTest extends IntegrationTestCase
{
    private function withoutModelEvents(string $class, callable $callback): void
    {
        $dispatcher = $class::getEventDispatcher();
        $class::unsetEventDispatcher();
        $callback();
        $class::setEventDispatcher($dispatcher);
    }

    private function source()
    {
        return app(ImportSourceFactory::class)::from(Product::class);
    }

    /**
     * @test
     */
    public function it_fans_out_one_chunk_job_per_chunk_on_the_given_queue(): void
    {
        Bus::fake();

        $this->withoutModelEvents(Product::class, function () {
            factory(Product::class, 10)->create(); // chunk size 3 => 4 chunks
        });

        $source = $this->source();
        $index = Index::fromSource($source);

        (new DispatchPullBatch($source, $index, 'redis', 'reindex', 'owner-token', 900))->handle();

        Bus::assertDispatched(PullChunkJob::class, 4);
        Bus::assertDispatched(PullChunkJob::class, function (PullChunkJob $job) {
            return $job->connection === 'redis' && $job->queue === 'reindex';
        });
    }

    /**
     * @test
     */
    public function it_dispatches_finalize_for_an_empty_import(): void
    {
        Bus::fake();

        $source = $this->source();
        $index = Index::fromSource($source);

        (new DispatchPullBatch($source, $index, null, null, 'owner-token', 900))->handle();

        Bus::assertDispatched(FinalizeJob::class);
        Bus::assertNotDispatched(PullChunkJob::class);
    }

    /**
     * @test
     */
    public function remove_unpromoted_index_deletes_only_the_new_index(): void
    {
        $source = $this->source();
        $index = Index::fromSource($source);

        // The new (unpromoted) index and an unrelated bystander index.
        $this->elasticsearch->indices()->create([
            'index' => $index->name(),
            'body' => ['settings' => ['number_of_shards' => 1, 'number_of_replicas' => 0]],
        ]);
        $this->elasticsearch->indices()->create([
            'index' => 'products_innocent_bystander',
            'body' => ['settings' => ['number_of_shards' => 1, 'number_of_replicas' => 0]],
        ]);

        RollbackImportJob::removeUnpromotedIndex($source, $index);

        $this->assertFalse(
            $this->elasticsearch->indices()->exists(['index' => $index->name()]),
            'The half-filled, never-promoted index must be removed on failure'
        );
        $this->assertTrue(
            $this->elasticsearch->indices()->exists(['index' => 'products_innocent_bystander']),
            'Cleanup must not touch any other index'
        );
    }

    /**
     * @test
     */
    public function remove_unpromoted_index_is_a_no_op_when_the_index_never_existed(): void
    {
        $source = $this->source();
        $index = Index::fromSource($source);

        // Must not throw for an index that was never created.
        RollbackImportJob::removeUnpromotedIndex($source, $index);

        $this->assertFalse($this->elasticsearch->indices()->exists(['index' => $index->name()]));
    }

    /**
     * @test
     * @dataProvider dangerousNames
     */
    public function remove_unpromoted_index_refuses_wildcard_or_foreign_names(string $dangerousName): void
    {
        $source = $this->source();

        // Two indices ES would happily match on a wildcard/multi-target delete.
        $this->elasticsearch->indices()->create(['index' => 'products_one']);
        $this->elasticsearch->indices()->create(['index' => 'orders_two']);

        // An Index whose name is a wildcard / foreign / empty value.
        RollbackImportJob::removeUnpromotedIndex($source, new Index($dangerousName));

        $this->assertTrue($this->elasticsearch->indices()->exists(['index' => 'products_one']));
        $this->assertTrue($this->elasticsearch->indices()->exists(['index' => 'orders_two']));
    }

    /**
     * @test
     */
    public function remove_unpromoted_index_deletes_when_still_the_lock_owner(): void
    {
        $source = $this->source();
        $index = Index::fromSource($source);
        $this->elasticsearch->indices()->create(['index' => $index->name()]);

        $owner = (new ImportLock($source->searchableAs(), 3600))->acquire();

        RollbackImportJob::removeUnpromotedIndex($source, $index, $owner);

        $this->assertFalse(
            $this->elasticsearch->indices()->exists(['index' => $index->name()]),
            'The still-owned run must roll back its own half-filled index'
        );
    }

    /**
     * @test
     */
    public function remove_unpromoted_index_skips_when_the_lease_belongs_to_another_run(): void
    {
        $source = $this->source();
        $index = Index::fromSource($source);
        $this->elasticsearch->indices()->create(['index' => $index->name()]);

        // Someone else now owns the lease for this model.
        (new ImportLock($source->searchableAs(), 3600))->acquire();

        RollbackImportJob::removeUnpromotedIndex($source, $index, 'stale-owner-token');

        $this->assertTrue(
            $this->elasticsearch->indices()->exists(['index' => $index->name()]),
            'Must not delete an index once our lease has been taken over by another run'
        );
    }

    /**
     * @test
     */
    public function finalize_tolerates_a_vanished_index(): void
    {
        $source = $this->source();
        $index = Index::fromSource($source); // deliberately never created in ES

        (new FinalizeJob($source, $index, 'vanished-index-token'))->handle();

        $this->assertFalse($this->elasticsearch->indices()->exists(['index' => $index->name()]));
    }

    /**
     * @test
     */
    public function per_model_chunk_config_controls_the_number_of_chunks(): void
    {
        Bus::fake();
        $this->app['config']->set('elasticsearch.import.chunk.products', 5);

        $this->withoutModelEvents(Product::class, function () {
            factory(Product::class, 10)->create();
        });

        $source = $this->source();
        $index = Index::fromSource($source);
        (new DispatchPullBatch($source, $index, null, null, 'owner-token', 900))->handle();

        // 10 rows / per-model chunk 5 => 2 chunks (the test config default is 3).
        Bus::assertDispatched(PullChunkJob::class, 2);
    }

    /**
     * @test
     */
    public function chunk_jobs_default_to_a_single_try_with_no_backoff(): void
    {
        Bus::fake();

        $this->withoutModelEvents(Product::class, function () {
            factory(Product::class, 6)->create();
        });

        $source = $this->source();
        (new DispatchPullBatch($source, Index::fromSource($source), null, null, 'owner-token', 900))->handle();

        Bus::assertDispatched(PullChunkJob::class, function (PullChunkJob $job) {
            return $job->tries === 1 && $job->backoff() === [];
        });
    }

    /**
     * @test
     */
    public function chunk_jobs_carry_the_configured_retry_settings(): void
    {
        Bus::fake();
        $this->app['config']->set('elasticsearch.import.retry.tries', 7);
        $this->app['config']->set('elasticsearch.import.retry.backoff_base', 3);
        $this->app['config']->set('elasticsearch.import.retry.backoff_cap', 30);

        $this->withoutModelEvents(Product::class, function () {
            factory(Product::class, 6)->create();
        });

        $source = $this->source();
        (new DispatchPullBatch($source, Index::fromSource($source), null, null, 'owner-token', 900))->handle();

        Bus::assertDispatched(PullChunkJob::class, function (PullChunkJob $job) {
            return $job->tries === 7 && $job->backoffBase === 3 && $job->backoffCap === 30;
        });
    }

    public function dangerousNames(): array
    {
        return [
            'wildcard star' => ['products_*'],
            'match all' => ['_all'],
            'comma list' => ['products_one,orders_two'],
            'question mark' => ['products_?'],
            'empty' => [''],
            'foreign model' => ['orders_123456'], // does not start with products_
            'alias itself' => ['products'], // no timestamp suffix
        ];
    }
}
