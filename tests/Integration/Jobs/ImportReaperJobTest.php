<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs;

use App\Product;
use Illuminate\Support\Facades\Bus;
use Matchish\ScoutElasticSearch\ElasticSearch\Index;
use Matchish\ScoutElasticSearch\Import\ImportRunStore;
use Matchish\ScoutElasticSearch\ImportLock;
use Matchish\ScoutElasticSearch\Jobs\DispatchPullChunks;
use Matchish\ScoutElasticSearch\Jobs\FinalizeJob;
use Matchish\ScoutElasticSearch\Jobs\ImportReaperJob;
use Matchish\ScoutElasticSearch\Jobs\PullChunkJob;
use Matchish\ScoutElasticSearch\Jobs\RollbackImportJob;
use Matchish\ScoutElasticSearch\Searchable\ImportSourceFactory;
use ReflectionProperty;
use Tests\IntegrationTestCase;

/**
 * The reaper is the opt-in watchdog for a --parallel run. Every assertion here
 * is about two things: that it stays completely inert at the default
 * `reaper_interval = 0`, and that when switched on it can only ever *add* work
 * (finalize, re-dispatch) and never fail or roll back a run.
 */
final class ImportReaperJobTest extends IntegrationTestCase
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
     * Index::fromSource() mints a fresh name on every call (it carries a random
     * per-run suffix), so a test that wants the reaper to act on a run record has
     * to hand it the *same* Index the record was published with — otherwise the
     * recycled-token guard declines, correctly.
     */
    private function reaper(Index $index, string $token, ?string $owner = null, int $pass = 0): ImportReaperJob
    {
        return new ImportReaperJob($this->source(), $index, $token, $owner, 900, null, null, false, $pass);
    }

    private function privateValue(object $job, string $name)
    {
        $property = new ReflectionProperty($job, $name);
        $property->setAccessible(true);

        return $property->getValue($job);
    }

    /**
     * @test
     */
    public function the_reaper_is_inert_at_the_default_configuration(): void
    {
        Bus::fake();

        // No explicit config(): this asserts the shipped default, which is the
        // whole point — enabling the reaper must be a deliberate act.
        $this->assertFalse(ImportReaperJob::scheduleFor(
            $this->source(),
            Index::fromSource($this->source()),
            'inert-token',
            null,
            900,
            null,
            null
        ));

        Bus::assertNotDispatched(ImportReaperJob::class);
    }

    /**
     * @test
     */
    public function the_fan_out_does_not_schedule_a_reaper_unless_it_is_enabled(): void
    {
        Bus::fake();

        $this->withoutModelEvents(Product::class, function () {
            factory(Product::class, 6)->create(); // chunk size 3 => 2 chunks
        });

        $source = $this->source();
        (new DispatchPullChunks($source, Index::fromSource($source), null, null, 'owner-token', 900))->handle();

        Bus::assertDispatched(PullChunkJob::class, 2);
        Bus::assertNotDispatched(ImportReaperJob::class);
    }

    /**
     * @test
     */
    public function the_fan_out_starts_the_chain_once_the_interval_is_set(): void
    {
        Bus::fake();
        $this->app['config']->set('elasticsearch.import.reaper_interval', 60);

        $this->withoutModelEvents(Product::class, function () {
            factory(Product::class, 6)->create();
        });

        $source = $this->source();
        (new DispatchPullChunks($source, Index::fromSource($source), 'redis', 'reindex', 'owner-token', 900))->handle();

        Bus::assertDispatched(ImportReaperJob::class, 1);
        Bus::assertDispatched(ImportReaperJob::class, function (ImportReaperJob $job) {
            return $job->connection === 'redis'
                && $job->queue === 'reindex'
                && $job->delay !== null
                && $this->privateValue($job, 'pass') === 0;
        });
    }

    /**
     * @test
     */
    public function an_empty_plan_needs_no_reaper(): void
    {
        Bus::fake();
        $this->app['config']->set('elasticsearch.import.reaper_interval', 60);

        // Nothing to strand and FinalizeJob is dispatched inline, so a watchdog
        // chain would poll a run that is already done.
        $source = $this->source();
        (new DispatchPullChunks($source, Index::fromSource($source), null, null, 'owner-token', 900))->handle();

        Bus::assertDispatched(FinalizeJob::class, 1);
        Bus::assertNotDispatched(ImportReaperJob::class);
    }

    /**
     * @test
     */
    public function a_pass_finalizes_a_complete_run_that_nobody_finalized(): void
    {
        Bus::fake();
        $this->app['config']->set('elasticsearch.import.reaper_interval', 60);

        $store = app(ImportRunStore::class);
        $source = $this->source();
        $token = 'complete-run';

        // The hole this closes: the chunk that observed done === total was killed
        // between markDone() and dispatching the finalize job.
        $index = Index::fromSource($source);
        $store->start($token, 2, $index->name());
        $store->markDone($token, 0);
        $store->markDone($token, 1);

        $this->reaper($index, $token)->handle();

        Bus::assertDispatched(FinalizeJob::class, 1);
        Bus::assertNotDispatched(PullChunkJob::class);
        // A complete run needs no further passes.
        Bus::assertNotDispatched(ImportReaperJob::class);
    }

    /**
     * @test
     */
    public function a_pass_re_dispatches_a_stranded_chunk_and_schedules_the_next_pass(): void
    {
        Bus::fake();
        $this->app['config']->set('elasticsearch.import.reaper_interval', 60);

        $store = app(ImportRunStore::class);
        $source = $this->source();

        $this->withoutModelEvents(Product::class, function () {
            factory(Product::class, 6)->create(); // chunk size 3 => 2 chunks
        });

        $token = 'stranded-run';
        $index = Index::fromSource($source);
        $store->start($token, 2, $index->name());
        $store->markDone($token, 0);
        // Chunk 1 is neither done nor holding a lease: it vanished from the queue
        // without ever running failed(), which is exactly what nothing else in
        // the system notices.
        $store->claimChunk($token, 0, 'irrelevant', 60);

        $this->reaper($index, $token)->handle();

        Bus::assertDispatched(PullChunkJob::class, 1);
        Bus::assertDispatched(PullChunkJob::class, function (PullChunkJob $job) use ($token) {
            return $this->privateValue($job, 'token') === $token
                && $this->privateValue($job, 'chunkId') === 1;
        });

        // Adds work, never destroys any.
        Bus::assertNotDispatched(RollbackImportJob::class);
        $this->assertSame(ImportRunStore::STATUS_RUNNING, $store->status($token));

        Bus::assertDispatched(ImportReaperJob::class, function (ImportReaperJob $job) {
            return $this->privateValue($job, 'pass') === 1;
        });
    }

    /**
     * @test
     */
    public function a_chunk_holding_a_live_lease_is_left_alone(): void
    {
        Bus::fake();
        $this->app['config']->set('elasticsearch.import.reaper_interval', 60);

        $store = app(ImportRunStore::class);
        $source = $this->source();

        $this->withoutModelEvents(Product::class, function () {
            factory(Product::class, 6)->create();
        });

        $token = 'in-flight-run';
        $index = Index::fromSource($source);
        $store->start($token, 2, $index->name());
        $store->markDone($token, 0);
        // Chunk 1 is genuinely running right now. Re-dispatching it would be
        // wasted work at best, and the copy would lose the lease race anyway.
        $store->claimChunk($token, 1, 'live-attempt', 300);

        $this->reaper($index, $token)->handle();

        Bus::assertNotDispatched(PullChunkJob::class);
        Bus::assertNotDispatched(FinalizeJob::class);
        // Still polling: the lease may yet lapse without the chunk completing.
        Bus::assertDispatched(ImportReaperJob::class, 1);
    }

    /**
     * @test
     */
    public function a_run_that_left_running_stops_the_chain(): void
    {
        Bus::fake();
        $this->app['config']->set('elasticsearch.import.reaper_interval', 60);

        $store = app(ImportRunStore::class);
        $source = $this->source();

        $index = Index::fromSource($source);

        foreach (['finalizing-run' => ImportRunStore::STATUS_FINALIZING, 'failed-run' => ImportRunStore::STATUS_FAILED] as $token => $status) {
            $store->start($token, 2, $index->name());
            $store->runs[$token]['status'] = $status;

            $this->reaper($index, $token)->handle();
        }

        // Every state past `running` is terminal for the reaper, and so is a run
        // record that has expired entirely.
        $this->reaper($index, 'never-existed')->handle();

        Bus::assertNotDispatched(ImportReaperJob::class);
        Bus::assertNotDispatched(PullChunkJob::class);
        Bus::assertNotDispatched(FinalizeJob::class);
    }

    /**
     * @test
     */
    public function a_pass_stands_down_once_the_lease_moved_to_another_import(): void
    {
        Bus::fake();
        $this->app['config']->set('elasticsearch.import.reaper_interval', 60);

        $store = app(ImportRunStore::class);
        $source = $this->source();

        $this->withoutModelEvents(Product::class, function () {
            factory(Product::class, 6)->create();
        });

        $token = 'superseded-run';
        $index = Index::fromSource($source);
        $store->start($token, 2, $index->name());

        // A later scout:import took the per-model lease after ours lapsed. Its
        // chunks own the index now.
        (new ImportLock($source->searchableAs(), 900))->acquire();

        $this->reaper($index, $token, 'our-stale-owner')->handle();

        Bus::assertNothingDispatched();
    }

    /**
     * @test
     */
    public function a_recycled_token_pointing_at_another_index_is_ignored(): void
    {
        Bus::fake();
        $this->app['config']->set('elasticsearch.import.reaper_interval', 60);

        $store = app(ImportRunStore::class);
        $token = 'recycled-token';

        $store->start($token, 2, 'products_someone_elses_index');

        $this->reaper(Index::fromSource($this->source()), $token)->handle();

        Bus::assertNothingDispatched();
    }

    /**
     * @test
     */
    public function a_paged_fan_out_still_dispatching_is_not_treated_as_stranded(): void
    {
        Bus::fake();
        $this->app['config']->set('elasticsearch.import.reaper_interval', 60);

        $store = app(ImportRunStore::class);
        $source = $this->source();

        $this->withoutModelEvents(Product::class, function () {
            factory(Product::class, 6)->create();
        });

        $token = 'still-dispatching-run';
        $index = Index::fromSource($source);
        $store->start($token, 2, $index->name());
        // Chunk 1's turn simply has not come yet.
        $store->pushBounds($token, [[1, 3, 6]]);

        $this->reaper($index, $token)->handle();

        Bus::assertNotDispatched(PullChunkJob::class);
        Bus::assertDispatched(ImportReaperJob::class, 1);
    }

    /**
     * @test
     */
    public function the_chain_stops_when_the_interval_is_set_back_to_zero(): void
    {
        Bus::fake();
        $this->app['config']->set('elasticsearch.import.reaper_interval', 0);

        $store = app(ImportRunStore::class);
        $source = $this->source();

        $token = 'switched-off-run';
        $index = Index::fromSource($source);
        $store->start($token, 2, $index->name());

        // The interval is re-read every pass rather than frozen into the payload,
        // so an operator can stop a chain that is already running.
        $this->reaper($index, $token, null, 12)->handle();

        Bus::assertNothingDispatched();
    }

    /**
     * @test
     */
    public function a_chunk_that_exhausted_its_re_dispatch_budget_ends_the_chain_without_failing_the_run(): void
    {
        Bus::fake();
        $this->app['config']->set('elasticsearch.import.reaper_interval', 60);
        // Shared with PullChunkJob's ambiguous-failure re-dispatch, so 0 has to
        // mean "never re-dispatch" in both places.
        $this->app['config']->set('elasticsearch.import.redispatch_limit', 0);

        $store = app(ImportRunStore::class);
        $source = $this->source();

        $this->withoutModelEvents(Product::class, function () {
            factory(Product::class, 6)->create();
        });

        $token = 'over-budget-run';
        $index = Index::fromSource($source);
        $store->start($token, 2, $index->name());
        $store->markDone($token, 0);

        $this->reaper($index, $token)->handle();

        Bus::assertNotDispatched(PullChunkJob::class);
        // Nothing left to try and nothing in flight: another pass would repeat
        // this one verbatim, so the chain ends. The run is left at `running` for
        // a human — the reaper is not allowed to fail a run or roll it back.
        Bus::assertNotDispatched(ImportReaperJob::class);
        Bus::assertNotDispatched(RollbackImportJob::class);
        $this->assertSame(ImportRunStore::STATUS_RUNNING, $store->status($token));
        $this->assertSame(0, $store->failureCount($token));
    }

    /**
     * @test
     */
    public function a_re_planned_chunk_count_that_moved_blocks_re_dispatch(): void
    {
        Bus::fake();
        $this->app['config']->set('elasticsearch.import.reaper_interval', 60);

        $store = app(ImportRunStore::class);
        $source = $this->source();

        $this->withoutModelEvents(Product::class, function () {
            factory(Product::class, 6)->create(); // re-plans to 2 chunks
        });

        // The record says 5, so chunk id N no longer denotes the range chunk N
        // was dispatched with. Indexing a shifted range would silently skip rows.
        $token = 'moved-key-space-run';
        $index = Index::fromSource($source);
        $store->start($token, 5, $index->name());

        $this->reaper($index, $token)->handle();

        Bus::assertNotDispatched(PullChunkJob::class);
        // The mismatch can be transient (rows written while we planned), so keep
        // polling rather than abandoning the run.
        Bus::assertDispatched(ImportReaperJob::class, 1);
    }
}
