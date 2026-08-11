<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs;

use App\Product;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Bus;
use Matchish\ScoutElasticSearch\ElasticSearch\Index;
use Matchish\ScoutElasticSearch\Import\ImportRunStore;
use Matchish\ScoutElasticSearch\ImportLock;
use Matchish\ScoutElasticSearch\Jobs\DispatchPullChunks;
use Matchish\ScoutElasticSearch\Jobs\PullChunkJob;
use Matchish\ScoutElasticSearch\Searchable\ImportSource;
use Matchish\ScoutElasticSearch\Searchable\ImportSourceFactory;
use ReflectionProperty;
use Tests\Fakes\FakeImportRunStore;
use Tests\IntegrationTestCase;

/**
 * The paged fan-out: a plan larger than `import.dispatch_batch` is dispatched
 * over several self-re-enqueuing hops instead of one long-running job.
 *
 * Two properties matter more than anything else here, and both are asserted by
 * running every hop a worker would run:
 *
 *  - Coverage. A chunk id is not a label — it is the chunk's position in the
 *    plan, which is what the run record's done/total accounting counts and what
 *    the parked boundary triples carry. Ids must therefore come back contiguous
 *    from 0, each exactly once, with the key ranges tiling the whole table with
 *    no gap and no overlap.
 *  - Liveness. The pre-existing fan-out loop renewed nothing, so a fan-out
 *    longer than lock_ttl silently stranded the run (lapsed import lease,
 *    expired run keys). Both leases are now renewed *inside* the dispatch loop.
 *
 * The plans here are tiny and `dispatch_batch` is set low instead, so the paging
 * is exercised without queueing thousands of jobs.
 */
final class PagedFanOutTest extends IntegrationTestCase
{
    private function withoutModelEvents(string $class, callable $callback): void
    {
        $dispatcher = $class::getEventDispatcher();
        $class::unsetEventDispatcher();
        $callback();
        $class::setEventDispatcher($dispatcher);
    }

    private function source(): ImportSource
    {
        return app(ImportSourceFactory::class)::from(Product::class);
    }

    /**
     * @test
     */
    public function a_plan_that_fits_one_page_is_dispatched_in_a_single_hop(): void
    {
        Bus::fake();
        // Deliberately *equal* to the plan size: paging starts above the batch,
        // not at it, so this pins the boundary condition.
        $this->app['config']->set('elasticsearch.import.dispatch_batch', 4);

        $this->withoutModelEvents(Product::class, function () {
            factory(Product::class, 10)->create(); // chunk size 3 => 4 chunks
        });

        $source = $this->source();

        (new DispatchPullChunks(
            $source,
            Index::fromSource($source),
            'redis',
            'reindex',
            'owner-token',
            900,
            'one-page-token'
        ))->handle();

        Bus::assertDispatched(PullChunkJob::class, 4);
        Bus::assertNotDispatched(DispatchPullChunks::class);

        /** @var FakeImportRunStore $store */
        $store = app(ImportRunStore::class);
        $this->assertSame(0, $store->pendingBounds('one-page-token'));
        $this->assertArrayNotHasKey(
            'bounds',
            $store->runs['one-page-token'],
            'a plan that fits one page must not park boundaries in the run record at all'
        );

        $chunks = $this->dispatchedChunkKeys();
        $this->assertSame([0, 1, 2, 3], array_keys($chunks));
        $this->assertEquals(range(1, 10), array_merge(...array_values($chunks)));
    }

    /**
     * @test
     */
    public function the_first_hop_dispatches_one_page_and_re_enqueues_itself(): void
    {
        Bus::fake();
        $this->app['config']->set('elasticsearch.import.dispatch_batch', 2);

        $this->withoutModelEvents(Product::class, function () {
            factory(Product::class, 18)->create(); // chunk size 3 => 6 chunks
        });

        $source = $this->source();

        (new DispatchPullChunks(
            $source,
            Index::fromSource($source),
            'redis',
            'reindex',
            'owner-token',
            900,
            'paged-token'
        ))->handle();

        // One page of chunks, not the whole plan, plus the hop that carries on.
        Bus::assertDispatched(PullChunkJob::class, 2);
        Bus::assertDispatched(DispatchPullChunks::class, 1);

        /** @var FakeImportRunStore $store */
        $store = app(ImportRunStore::class);
        $this->assertSame(6, $store->total('paged-token'), 'the whole plan is published up front');
        $this->assertSame(ImportRunStore::STATUS_RUNNING, $store->status('paged-token'));
        $this->assertSame(4, $store->pendingBounds('paged-token'), 'the undispatched tail stays parked');

        // Plan positions are parked with the bounds rather than re-derived per
        // hop, which is what keeps a chunk id meaning the same range in every
        // hop and in the done/total accounting.
        $this->assertSame(
            [2, 3, 4, 5],
            array_column($store->runs['paged-token']['bounds'], 0),
            'the parked tail keeps its plan positions'
        );

        $this->assertSame([0, 1], array_keys($this->dispatchedChunkKeys()), 'pages are dispatched in plan order');

        $next = Bus::dispatched(DispatchPullChunks::class)->first();
        $this->assertSame(2, $this->peek($next, 'cursor'), 'a continuation hop must not read cursor 0 and re-plan');
        $this->assertSame(6, $this->peek($next, 'total'));
        $this->assertSame('paged-token', $this->peek($next, 'runToken'));
        $this->assertSame('redis', $next->connection);
        $this->assertSame('reindex', $next->queue);
        $this->assertSame(1, $next->tries, 'a retried hop would re-dispatch the whole page');
    }

    /**
     * @test
     */
    public function running_every_hop_covers_the_whole_key_range_exactly_once(): void
    {
        Bus::fake();
        $this->app['config']->set('elasticsearch.import.dispatch_batch', 2);

        $this->withoutModelEvents(Product::class, function () {
            factory(Product::class, 18)->create(); // chunk size 3 => 6 chunks => 3 pages
        });

        $source = $this->source();

        $hops = $this->runHops(new DispatchPullChunks(
            $source,
            Index::fromSource($source),
            null,
            null,
            'owner-token',
            900,
            'paged-token'
        ));

        $this->assertSame(3, $hops);
        Bus::assertDispatched(PullChunkJob::class, 6);
        Bus::assertDispatched(DispatchPullChunks::class, 2);

        $chunks = $this->dispatchedChunkKeys();

        // Contiguous from 0 with no repeats: chunk ids are plan positions, and
        // the done/total accounting only ever adds up if every position is
        // dispatched exactly once.
        $this->assertSame(range(0, 5), array_keys($chunks));

        // Merged in chunk-id order the keys come back as the full table in
        // ascending order, which can only happen if the ranges tile the key
        // space with no gap (a missing row) and no overlap (a repeated row).
        $this->assertEquals(range(1, 18), array_merge(...array_values($chunks)));

        foreach ($chunks as $chunkId => $keys) {
            $this->assertNotEmpty($keys, "chunk {$chunkId} was rebuilt from bounds that select nothing");
        }

        /** @var FakeImportRunStore $store */
        $store = app(ImportRunStore::class);
        $this->assertSame(0, $store->pendingBounds('paged-token'));
        $this->assertSame(6, $store->total('paged-token'), 'no hop may re-plan and reset the total');
    }

    /**
     * @test
     */
    public function a_re_delivered_hop_never_dispatches_a_chunk_twice(): void
    {
        Bus::fake();
        $this->app['config']->set('elasticsearch.import.dispatch_batch', 2);

        $this->withoutModelEvents(Product::class, function () {
            factory(Product::class, 18)->create(); // chunk size 3 => 6 chunks
        });

        $source = $this->source();

        (new DispatchPullChunks(
            $source,
            Index::fromSource($source),
            null,
            null,
            'owner-token',
            900,
            'paged-token'
        ))->handle();

        // The broker hands the same hop payload out twice — the visibility
        // window lapsing mid-hop, or a plain SQS duplicate. Popping boundaries
        // is destructive and atomic, so the second delivery cannot see what the
        // first one already took: it either finds nothing left or moves the
        // fan-out forward, but never re-dispatches a chunk.
        $hop = Bus::dispatched(DispatchPullChunks::class)->first();
        $hop->handle();
        $hop->handle();

        // Whatever those two runs enqueued still gets its turn (index 0 is the
        // hop we just replayed).
        $i = 1;
        while (($queued = Bus::dispatched(DispatchPullChunks::class)->get($i)) !== null) {
            $this->assertLessThan(10, $i, 'the paged fan-out must terminate');
            $queued->handle();
            $i++;
        }

        Bus::assertDispatched(PullChunkJob::class, 6);

        // dispatchedChunkKeys() fails on a repeated chunk id.
        $chunks = $this->dispatchedChunkKeys();
        $this->assertSame(range(0, 5), array_keys($chunks));
        $this->assertEquals(range(1, 18), array_merge(...array_values($chunks)));

        /** @var FakeImportRunStore $store */
        $store = app(ImportRunStore::class);
        $this->assertSame(0, $store->pendingBounds('paged-token'));
    }

    /**
     * @test
     */
    public function a_continuation_hop_never_re_plans_the_import(): void
    {
        Bus::fake();

        $this->withoutModelEvents(Product::class, function () {
            factory(Product::class, 18)->create();
        });

        $source = $this->source();

        // A continuation hop (cursor > 0) whose parked boundaries are already
        // gone. Re-planning here would dispatch the entire fan-out a second time
        // and a second start() would reset the chunk total the finalize
        // accounting keys off, so it must do neither.
        (new DispatchPullChunks(
            $source,
            Index::fromSource($source),
            null,
            null,
            'owner-token',
            900,
            'orphan-token',
            false,
            1,
            6
        ))->handle();

        Bus::assertNotDispatched(PullChunkJob::class);
        Bus::assertNotDispatched(DispatchPullChunks::class);

        /** @var FakeImportRunStore $store */
        $store = app(ImportRunStore::class);
        $this->assertNull($store->status('orphan-token'));
        $this->assertArrayNotHasKey('orphan-token', $store->runs, 'a continuation hop must not publish a run record');
    }

    /**
     * @test
     */
    public function a_page_renews_the_import_lease_and_the_run_record_inside_the_dispatch_loop(): void
    {
        // This test sleeps for real, deliberately: the in-loop renew is throttled
        // on microtime(), so only wall clock can make it due.
        //
        // lock_ttl 2 => the loop renews at most once a second (max(1,
        // intdiv(2, 4))). Three chunk dispatches held for 1.1s each stretch the
        // page to ~3.3s, comfortably past the 2s lease. Renewing only *around*
        // the loop cannot rescue that: ImportLock::renew() extends a lease it
        // still owns and a lapsed lease is gone for good. So a lease still held
        // at the end is proof the renew ran while chunks were still going out.
        $ttl = 2;
        $this->app['config']->set('elasticsearch.import.dispatch_batch', 3);

        $this->withoutModelEvents(Product::class, function () {
            factory(Product::class, 18)->create(); // chunk size 3 => 6 chunks => 2 pages
        });

        $source = $this->source();
        $searchableAs = $source->searchableAs();

        $owner = (new ImportLock($searchableAs, $ttl))->acquire();
        $this->assertNotNull($owner);

        // Everything that happens, in order: 'refresh' for a run-record TTL
        // refresh, 'chunk' for a queued chunk, 'hop' for the next page.
        /** @var array<int, string> $events */
        $events = [];

        $this->app->instance(ImportRunStore::class, $this->recordingStore($events));
        Bus::swap($this->recordingDispatcher($events, 3, 1100000));

        (new DispatchPullChunks(
            $source,
            Index::fromSource($source),
            null,
            null,
            $owner,
            $ttl,
            'renew-token'
        ))->handle();

        $chunkAt = array_keys($events, 'chunk', true);
        $this->assertCount(3, $chunkAt, 'one page is dispatch_batch chunks');
        $this->assertContains('hop', $events, 'boundaries were left parked, so a next hop must be queued');

        // Strictly *between* the first and the last chunk of the page. The
        // planning phase and the end of the loop refresh the TTLs too, and
        // counting either of those would let the original bug pass.
        $first = (int) reset($chunkAt);
        $last = (int) end($chunkAt);
        $this->assertContains(
            'refresh',
            array_slice($events, $first + 1, $last - $first - 1),
            'the run record TTLs must be refreshed while the page is still being dispatched'
        );

        $this->assertTrue(
            ImportLock::isHeldBy($searchableAs, $owner),
            'the import lease must be renewed inside the dispatch loop: a page longer than lock_ttl otherwise '
            .'lapses mid-fan-out, and a second run of the same model can then start on top of this one'
        );
    }

    /**
     * Run the planning hop and then every hop it re-enqueues, exactly as a
     * worker would, and return how many hops ran. Each hop queues at most one
     * successor, so the n-th queued DispatchPullChunks is simply hop n.
     */
    private function runHops(DispatchPullChunks $planning, int $limit = 20): int
    {
        $planning->handle();
        $hops = 1;

        while (($next = Bus::dispatched(DispatchPullChunks::class)->get($hops - 1)) !== null) {
            $this->assertLessThan($limit, $hops, 'the paged fan-out must terminate');
            $next->handle();
            $hops++;
        }

        return $hops;
    }

    /**
     * The keys every dispatched chunk job would actually index, indexed by chunk
     * id, in ascending id order. Fails the test on a chunk id dispatched twice.
     *
     * The chunk id and its stage are private internals rather than API, but a
     * fan-out test has to see which chunk got which slice of the key space —
     * and asking the rebuilt source what it selects is both cheaper and stricter
     * than comparing the boundary scalars, which say nothing about whether the
     * ranges actually tile the table.
     *
     * @return array<int, array<int, mixed>>
     */
    private function dispatchedChunkKeys(): array
    {
        $chunks = [];

        foreach (Bus::dispatched(PullChunkJob::class) as $job) {
            $chunkId = $this->peek($job, 'chunkId');
            /** @var ImportSource $source */
            $source = $this->peek($this->peek($job, 'stage'), 'source');

            $this->assertArrayNotHasKey($chunkId, $chunks, "chunk {$chunkId} was dispatched more than once");

            $chunks[$chunkId] = $source->get()->modelKeys();
        }

        ksort($chunks);

        return $chunks;
    }

    /**
     * A store that keeps real paging state (a FakeImportRunStore does the work)
     * while logging every TTL refresh into the shared event log.
     *
     * @param  array<int, string>  $events
     */
    private function recordingStore(array &$events): ImportRunStore
    {
        $state = new FakeImportRunStore();

        $store = $this->createMock(ImportRunStore::class);
        $store->method('start')->willReturnCallback([$state, 'start']);
        $store->method('pushBounds')->willReturnCallback([$state, 'pushBounds']);
        $store->method('popBounds')->willReturnCallback([$state, 'popBounds']);
        $store->method('pendingBounds')->willReturnCallback([$state, 'pendingBounds']);
        $store->method('refreshTtls')->willReturnCallback(function (string $token, int $ttl) use (&$events): void {
            $events[] = 'refresh';
        });

        return $store;
    }

    /**
     * A bus that only records what was dispatched, holding the first few chunk
     * dispatches open for a while — a broker write is not instant either, and
     * the loop's renew is due on elapsed time.
     *
     * @param  array<int, string>  $events
     * @param  int  $slowDispatches  how many chunk dispatches are held open
     * @param  int  $holdMicroseconds  how long each of those is held
     */
    private function recordingDispatcher(array &$events, int $slowDispatches, int $holdMicroseconds): Dispatcher
    {
        $record = function ($command) use (&$events, &$slowDispatches, $holdMicroseconds): void {
            if ($command instanceof PullChunkJob) {
                if ($slowDispatches > 0) {
                    $slowDispatches--;
                    usleep($holdMicroseconds);
                }

                $events[] = 'chunk';

                return;
            }

            $events[] = $command instanceof DispatchPullChunks ? 'hop' : 'other';
        };

        return new class($record) implements Dispatcher
        {
            /**
             * @var callable
             */
            private $record;

            public function __construct(callable $record)
            {
                $this->record = $record;
            }

            public function dispatch($command)
            {
                ($this->record)($command);

                return null;
            }

            public function dispatchSync($command, $handler = null)
            {
                return $this->dispatch($command);
            }

            public function dispatchNow($command, $handler = null)
            {
                return $this->dispatch($command);
            }

            public function hasCommandHandler($command)
            {
                return false;
            }

            public function getCommandHandler($command)
            {
                return false;
            }

            public function pipeThrough(array $pipes)
            {
                return $this;
            }

            public function map(array $map)
            {
                return $this;
            }
        };
    }

    /**
     * @return mixed
     */
    private function peek(object $object, string $property)
    {
        $reflection = new ReflectionProperty($object, $property);
        $reflection->setAccessible(true);

        return $reflection->getValue($object);
    }
}
