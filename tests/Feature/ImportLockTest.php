<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Book;
use App\Product;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Matchish\ScoutElasticSearch\ImportLock;
use stdClass;
use Tests\IntegrationTestCase;

/**
 * The per-model duplicate-run guard. Correctness of keyset paging does not
 * depend on it (chunk bounds are frozen per job), so these tests assert the
 * safety-net behaviour: a second run for the same model is skipped, different
 * models are unaffected, and the lock is always freed when the import ends.
 */
final class ImportLockTest extends IntegrationTestCase
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
     * @dataProvider modes
     */
    public function second_import_of_the_same_model_is_skipped(bool $parallel): void
    {
        if ($parallel) {
            $this->useSyncQueue();
        }
        Bus::fake();

        // Simulate a first import already holding the lock.
        $held = (new ImportLock((new Product())->searchableAs(), 3600))->acquire();
        $this->assertNotNull($held);

        $options = ['searchable' => [Product::class]];
        if ($parallel) {
            $options['--parallel'] = true;
        }

        $exitCode = Artisan::call('scout:import', $options);

        // Skips cleanly (exit 0) and dispatches no work for the locked model.
        $this->assertEquals(0, $exitCode);
        Bus::assertNothingDispatched();
        Bus::assertNothingBatched();
    }

    /**
     * @test
     */
    public function different_models_are_not_blocked_by_each_other(): void
    {
        // Hold the Product lock; Book must still import in the same invocation.
        $held = (new ImportLock((new Product())->searchableAs(), 3600))->acquire();
        $this->assertNotNull($held);

        $this->withoutModelEvents(Book::class, function () {
            factory(Book::class, 4)->create();
        });

        Artisan::call('scout:import', ['searchable' => [Product::class, Book::class]]);

        // Book indexed despite Product being locked.
        $this->assertEquals(4, $this->searchTotal((new Book())->searchableAs()));
        // Product was skipped: no index created for it.
        $this->assertFalse(
            $this->elasticsearch->indices()->exists(['index' => (new Product())->searchableAs()]),
            'Locked model must not have been imported'
        );
    }

    /**
     * @test
     */
    public function lock_is_released_after_a_successful_sequential_import(): void
    {
        $this->withoutModelEvents(Product::class, function () {
            factory(Product::class, 5)->create();
        });

        Artisan::call('scout:import', ['searchable' => [Product::class]]);

        // Lock is free again, so a follow-up run can acquire it.
        $owner = (new ImportLock((new Product())->searchableAs(), 3600))->acquire();
        $this->assertNotNull($owner, 'Import lock should be released after the import finishes');
    }

    /**
     * @test
     */
    public function lock_is_released_after_a_successful_parallel_import(): void
    {
        $this->useSyncQueue();

        $this->withoutModelEvents(Product::class, function () {
            factory(Product::class, 5)->create();
        });

        Artisan::call('scout:import', ['searchable' => [Product::class], '--parallel' => true]);

        $owner = (new ImportLock((new Product())->searchableAs(), 3600))->acquire();
        $this->assertNotNull($owner, 'Import lock should be released after the parallel batch finishes');
    }

    /**
     * @test
     */
    public function a_second_acquire_fails_while_the_lease_is_held(): void
    {
        $lock = new ImportLock('products', 3600);

        $this->assertNotNull($lock->acquire());
        $this->assertNull((new ImportLock('products', 3600))->acquire());
    }

    /**
     * @test
     */
    public function renew_keeps_the_lease_alive_past_its_original_ttl(): void
    {
        // One-second lease, then renew it for an hour: it must still be held.
        $owner = (new ImportLock('products', 1))->acquire();
        $this->assertNotNull($owner);

        ImportLock::renew('products', $owner, 3600);

        // A competing acquire still cannot get in.
        $this->assertNull((new ImportLock('products', 1))->acquire());
    }

    /**
     * @test
     */
    public function renew_by_a_non_owner_is_a_no_op(): void
    {
        $owner = (new ImportLock('products', 3600))->acquire();
        $this->assertNotNull($owner);

        // A stranger cannot renew (or otherwise touch) someone else's lease.
        ImportLock::renew('products', 'not-the-owner', 3600);

        // Owner can still release it, proving the value was untouched.
        ImportLock::release('products', $owner);
        $this->assertNotNull((new ImportLock('products', 3600))->acquire());
    }

    /**
     * @test
     */
    public function release_only_frees_the_lease_when_the_owner_matches(): void
    {
        $owner = (new ImportLock('products', 3600))->acquire();
        $this->assertNotNull($owner);

        // Wrong owner cannot release it.
        ImportLock::release('products', 'someone-else');
        $this->assertNull((new ImportLock('products', 3600))->acquire());

        // Correct owner frees it.
        ImportLock::release('products', $owner);
        $this->assertNotNull((new ImportLock('products', 3600))->acquire());
    }

    public function modes(): array
    {
        return [
            'sequential' => [false],
            'parallel' => [true],
        ];
    }
}
