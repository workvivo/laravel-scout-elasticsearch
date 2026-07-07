<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs;

use App\Product;
use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;
use Matchish\ScoutElasticSearch\ElasticSearch\Index;
use Matchish\ScoutElasticSearch\Jobs\DispatchPullBatch;
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
    public function it_fans_out_one_batched_job_per_chunk_on_the_given_queue(): void
    {
        Bus::fake();

        $this->withoutModelEvents(Product::class, function () {
            factory(Product::class, 10)->create(); // chunk size 3 => 4 chunks
        });

        $source = $this->source();
        $index = Index::fromSource($source);

        (new DispatchPullBatch($source, $index, 'redis', 'reindex', 'owner-token', 900))->handle();

        Bus::assertBatched(function (PendingBatch $batch) {
            return $batch->jobs->count() === 4
                && $batch->connection() === 'redis'
                && $batch->queue() === 'reindex';
        });
    }

    /**
     * @test
     */
    public function it_registers_progress_then_catch_and_finally_callbacks(): void
    {
        Bus::fake();

        $this->withoutModelEvents(Product::class, function () {
            factory(Product::class, 6)->create();
        });

        $source = $this->source();
        $index = Index::fromSource($source);

        (new DispatchPullBatch($source, $index, null, null, 'owner-token', 900))->handle();

        Bus::assertBatched(function (PendingBatch $batch) {
            // Heartbeat (renew), finalize, rollback, and lock release are all wired.
            return count($batch->progressCallbacks()) === 1
                && count($batch->thenCallbacks()) === 1
                && count($batch->catchCallbacks()) === 1
                && count($batch->finallyCallbacks()) === 1;
        });
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

        DispatchPullBatch::removeUnpromotedIndex($source, $index);

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
        DispatchPullBatch::removeUnpromotedIndex($source, $index);

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
        DispatchPullBatch::removeUnpromotedIndex($source, new Index($dangerousName));

        $this->assertTrue($this->elasticsearch->indices()->exists(['index' => 'products_one']));
        $this->assertTrue($this->elasticsearch->indices()->exists(['index' => 'orders_two']));
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
