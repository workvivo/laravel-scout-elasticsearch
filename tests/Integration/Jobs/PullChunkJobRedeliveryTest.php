<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs;

use App\Product;
use DateTimeInterface;
use Illuminate\Support\Facades\Bus;
use Matchish\ScoutElasticSearch\ElasticSearch\Index;
use Matchish\ScoutElasticSearch\Import\ImportRunStore;
use Matchish\ScoutElasticSearch\Jobs\PullChunkJob;
use Matchish\ScoutElasticSearch\Jobs\RollbackImportJob;
use Matchish\ScoutElasticSearch\Jobs\Stages\PullFromSource;
use Matchish\ScoutElasticSearch\Searchable\ImportSource;
use Matchish\ScoutElasticSearch\Searchable\ImportSourceFactory;
use ReflectionProperty;
use RuntimeException;
use Tests\IntegrationTestCase;

/**
 * The redelivery contract of PullChunkJob::failed().
 *
 * The scenario these tests exist for: SQS re-delivers a message once its
 * VisibilityTimeout lapses, even while attempt 1 is still running, and
 * SqsJob::attempts() is ApproximateReceiveCount — so delivery #2 arrives with
 * attempts=2 and Worker::process() fails it *before* handle(), which means the
 * SkipIfImportRun middleware never runs. failed() does run, though, and the old
 * implementation read "this chunk is not in the done set" as a genuine failure
 * and rolled the entire multi-hour import back over a harmless duplicate.
 *
 * failed() therefore has to classify from Redis state alone: CallQueuedHandler
 * deserializes a fresh command and never attaches the job, so attempts() is
 * always 1 here and nothing handle() set survives. That is why every test below
 * arranges its state through the store rather than through a previous run of
 * handle().
 */
final class PullChunkJobRedeliveryTest extends IntegrationTestCase
{
    private const TOKEN = 'redelivery-token';

    /**
     * Lease TTL used for the replacement job's delay: the worker timeout
     * PullChunkJob falls back to when elasticsearch.queue.timeout is unset (60)
     * plus the default chunk_lease_pad (120). The re-dispatch adds 1-30s jitter.
     */
    private const LEASE_TTL = 180;

    private function source(): ImportSource
    {
        return app(ImportSourceFactory::class)::from(Product::class);
    }

    private function job(ImportSource $source, Index $index, int $chunkId): PullChunkJob
    {
        return new PullChunkJob(
            $source,
            new PullFromSource($source),
            $index,
            self::TOKEN,
            $chunkId,
            'owner-token',
            900,
            'redis',
            'reindex'
        );
    }

    /**
     * @test
     */
    public function a_failure_while_another_attempt_holds_the_lease_re_dispatches_instead_of_rolling_back(): void
    {
        Bus::fake();

        $source = $this->source();
        $index = Index::fromSource($source);
        /** @var ImportRunStore $store */
        $store = app(ImportRunStore::class);
        $store->start(self::TOKEN, 4, $index->name());

        // Attempt 1 is running: it holds the lease for chunk 2 and has not
        // marked it done yet. This is the state a duplicate delivery lands in.
        $this->assertTrue($store->claimChunk(self::TOKEN, 2, 'attempt-1-owner', 300));

        // Delivery #2, failed by the worker before handle() ever ran. A fresh
        // instance, because that is all CallQueuedHandler::failed() ever has.
        $this->job($source, $index, 2)->failed(new RuntimeException('MaxAttemptsExceededException'));

        $this->assertSame(
            ImportRunStore::STATUS_RUNNING,
            $store->status(self::TOKEN),
            'A duplicate delivery must not touch the run status'
        );
        Bus::assertNotDispatched(RollbackImportJob::class);
        $this->assertSame(
            0,
            $store->failureCount(self::TOKEN),
            'An ambiguous failure must not consume a slot of the failure budget'
        );
        $this->assertTrue(
            $store->chunkInFlight(self::TOKEN, 2),
            "The duplicate must leave the running attempt's lease alone"
        );

        // The chunk is re-dispatched rather than judged: bounds are frozen into
        // the payload and documents overwrite by stable _id, so re-running it is
        // always safe, and the delay lets the current lease lapse first.
        Bus::assertDispatched(PullChunkJob::class, 1);
        Bus::assertDispatched(PullChunkJob::class, function (PullChunkJob $job) {
            return $this->privateValue($job, 'token') === self::TOKEN
                && $this->privateValue($job, 'chunkId') === 2
                && $job->connection === 'redis'
                && $job->queue === 'reindex'
                && $job->delay instanceof DateTimeInterface
                // now() + lease TTL + 1..30s of jitter, minus a little slack for
                // the wall clock moving between dispatch and this assertion.
                && $job->delay->getTimestamp() >= time() + self::LEASE_TTL - 5
                && $job->delay->getTimestamp() <= time() + self::LEASE_TTL + 30;
        });
    }

    /**
     * @test
     */
    public function a_failure_after_the_lease_expired_is_genuine_and_rolls_back_at_the_default_budget(): void
    {
        Bus::fake();

        $source = $this->source();
        $index = Index::fromSource($source);
        /** @var \Tests\Fakes\FakeImportRunStore $store */
        $store = app(ImportRunStore::class);
        $store->start(self::TOKEN, 4, $index->name());

        // A worker hard-killed mid-flight (SIGALRM/OOM) skips handle()'s finally,
        // so its lease survives until the TTL elapses. Once it has, there is
        // nothing in flight and the failure is genuine again.
        $store->claimChunk(self::TOKEN, 1, 'dead-worker', 300);
        $store->expireChunkLease(self::TOKEN, 1);

        $this->job($source, $index, 1)->failed(new RuntimeException('chunk failed'));

        // failure_budget defaults to 1, which is exactly the historical
        // behaviour: one genuine failure kills the run.
        $this->assertSame(ImportRunStore::STATUS_FAILED, $store->status(self::TOKEN));
        $this->assertSame(1, $store->failureCount(self::TOKEN));
        Bus::assertDispatched(RollbackImportJob::class, function (RollbackImportJob $job) {
            return $job->connection === 'redis' && $job->queue === 'reindex';
        });
        Bus::assertNotDispatched(PullChunkJob::class);
    }

    /**
     * @test
     */
    public function a_larger_failure_budget_absorbs_failures_until_it_is_spent(): void
    {
        Bus::fake();
        $this->app['config']->set('elasticsearch.import.failure_budget', 3);

        $source = $this->source();
        $index = Index::fromSource($source);
        /** @var ImportRunStore $store */
        $store = app(ImportRunStore::class);
        $store->start(self::TOKEN, 8, $index->name());

        // No lease anywhere: every one of these is a genuine failure.
        $this->job($source, $index, 0)->failed(new RuntimeException('first'));

        $this->assertSame(ImportRunStore::STATUS_RUNNING, $store->status(self::TOKEN));
        $this->assertSame(1, $store->failureCount(self::TOKEN));
        Bus::assertNotDispatched(RollbackImportJob::class);

        $this->job($source, $index, 1)->failed(new RuntimeException('second'));

        $this->assertSame(ImportRunStore::STATUS_RUNNING, $store->status(self::TOKEN));
        $this->assertSame(2, $store->failureCount(self::TOKEN));
        Bus::assertNotDispatched(RollbackImportJob::class);

        // The third distinct chunk exhausts the budget.
        $this->job($source, $index, 2)->failed(new RuntimeException('third'));

        $this->assertSame(ImportRunStore::STATUS_FAILED, $store->status(self::TOKEN));
        $this->assertSame(3, $store->failureCount(self::TOKEN));
        Bus::assertDispatched(RollbackImportJob::class, 1);
    }

    /**
     * @test
     */
    public function the_same_chunk_failing_repeatedly_consumes_only_one_budget_slot(): void
    {
        Bus::fake();
        $this->app['config']->set('elasticsearch.import.failure_budget', 2);

        $source = $this->source();
        $index = Index::fromSource($source);
        /** @var ImportRunStore $store */
        $store = app(ImportRunStore::class);
        $store->start(self::TOKEN, 8, $index->name());

        // Chunk 5 fails, is retried by the queue, and fails again. recordFailure
        // is a set add, so the budget tracks *distinct* broken chunks.
        $this->job($source, $index, 5)->failed(new RuntimeException('attempt a'));
        $this->job($source, $index, 5)->failed(new RuntimeException('attempt b'));
        $this->job($source, $index, 5)->failed(new RuntimeException('attempt c'));

        $this->assertSame(
            1,
            $store->failureCount(self::TOKEN),
            'One broken chunk may never spend more than one slot, however often it fails'
        );
        $this->assertSame(ImportRunStore::STATUS_RUNNING, $store->status(self::TOKEN));
        Bus::assertNotDispatched(RollbackImportJob::class);

        // A second, different chunk does spend the remaining slot.
        $this->job($source, $index, 6)->failed(new RuntimeException('other chunk'));

        $this->assertSame(2, $store->failureCount(self::TOKEN));
        $this->assertSame(ImportRunStore::STATUS_FAILED, $store->status(self::TOKEN));
        Bus::assertDispatched(RollbackImportJob::class, 1);
    }

    /**
     * @test
     */
    public function an_exhausted_re_dispatch_budget_escalates_to_a_genuine_failure(): void
    {
        Bus::fake();
        $this->app['config']->set('elasticsearch.import.redispatch_limit', 2);

        $source = $this->source();
        $index = Index::fromSource($source);
        /** @var ImportRunStore $store */
        $store = app(ImportRunStore::class);
        $store->start(self::TOKEN, 4, $index->name());

        // A lease that never goes away — a chunk that keeps coming back
        // ambiguous. Long TTL so it is still live on every pass below.
        $store->claimChunk(self::TOKEN, 3, 'stuck-attempt', 3600);

        $this->job($source, $index, 3)->failed(new RuntimeException('ambiguous 1'));
        $this->job($source, $index, 3)->failed(new RuntimeException('ambiguous 2'));

        Bus::assertDispatched(PullChunkJob::class, 2);
        $this->assertSame(ImportRunStore::STATUS_RUNNING, $store->status(self::TOKEN));
        Bus::assertNotDispatched(RollbackImportJob::class);

        // Budget spent: the third ambiguous failure is treated as genuine, so a
        // pathological chunk cannot re-dispatch itself forever.
        $this->job($source, $index, 3)->failed(new RuntimeException('ambiguous 3'));

        Bus::assertDispatched(PullChunkJob::class, 2);
        $this->assertSame(1, $store->failureCount(self::TOKEN));
        $this->assertSame(ImportRunStore::STATUS_FAILED, $store->status(self::TOKEN));
        Bus::assertDispatched(RollbackImportJob::class, 1);
    }

    /**
     * @test
     */
    public function a_zero_re_dispatch_limit_never_re_dispatches(): void
    {
        Bus::fake();
        $this->app['config']->set('elasticsearch.import.redispatch_limit', 0);

        $source = $this->source();
        $index = Index::fromSource($source);
        /** @var ImportRunStore $store */
        $store = app(ImportRunStore::class);
        $store->start(self::TOKEN, 4, $index->name());
        $store->claimChunk(self::TOKEN, 0, 'attempt-1-owner', 3600);

        $this->job($source, $index, 0)->failed(new RuntimeException('ambiguous'));

        Bus::assertNotDispatched(PullChunkJob::class);
        $this->assertSame(ImportRunStore::STATUS_FAILED, $store->status(self::TOKEN));
        Bus::assertDispatched(RollbackImportJob::class);
    }

    /**
     * @test
     */
    public function a_failure_for_an_already_done_chunk_is_ignored_even_while_a_lease_is_live(): void
    {
        Bus::fake();

        $source = $this->source();
        $index = Index::fromSource($source);
        /** @var ImportRunStore $store */
        $store = app(ImportRunStore::class);
        $store->start(self::TOKEN, 4, $index->name());
        $store->markDone(self::TOKEN, 0);
        // A duplicate delivery may still hold a lease when the original copy has
        // already completed; "done" must win over "in flight".
        $store->claimChunk(self::TOKEN, 0, 'late-duplicate', 3600);

        $this->job($source, $index, 0)->failed(new RuntimeException('late failure'));

        $this->assertSame(ImportRunStore::STATUS_RUNNING, $store->status(self::TOKEN));
        $this->assertSame(0, $store->failureCount(self::TOKEN));
        Bus::assertNotDispatched(PullChunkJob::class);
        Bus::assertNotDispatched(RollbackImportJob::class);
    }

    /**
     * PullChunkJob keeps its coordinates private and is final, so the only way
     * to prove the replacement job targets the same chunk is reflection.
     *
     * @return mixed
     */
    private function privateValue(PullChunkJob $job, string $name)
    {
        $property = new ReflectionProperty(PullChunkJob::class, $name);
        $property->setAccessible(true);

        return $property->getValue($job);
    }
}
