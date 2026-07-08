<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use DateTimeInterface;
use Illuminate\Queue\Middleware\SkipIfBatchCancelled;
use Matchish\ScoutElasticSearch\ImportLock;
use Matchish\ScoutElasticSearch\Jobs\StageJob;
use Matchish\ScoutElasticSearch\Jobs\Stages\StageInterface;
use OpenSearch\Client;
use Tests\TestCase;

final class StageJobTest extends TestCase
{
    /**
     * A do-nothing stage that records whether it ran, so a test can assert the
     * surrounding StageJob logic (cancel guard, lease renewal) without touching
     * the cluster.
     */
    private function stubStage(): StageInterface
    {
        return new class implements StageInterface
        {
            public bool $handled = false;

            public function title(): string
            {
                return 'stub';
            }

            public function estimate(): int
            {
                return 1;
            }

            public function handle(Client $elasticsearch): void
            {
                $this->handled = true;
            }
        };
    }

    /**
     * @test
     */
    public function it_registers_the_skip_if_batch_cancelled_middleware(): void
    {
        $middleware = (new StageJob($this->stubStage()))->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(SkipIfBatchCancelled::class, $middleware[0]);
    }

    /**
     * @test
     */
    public function backoff_is_empty_for_a_prepare_stage(): void
    {
        // No backoff base (prepare stages), tries stays 1: nothing to retry.
        $job = new StageJob($this->stubStage());

        $this->assertSame(1, $job->tries);
        $this->assertSame([], $job->backoff());
    }

    /**
     * @test
     */
    public function backoff_is_empty_at_a_single_try_even_with_a_base(): void
    {
        $job = new StageJob($this->stubStage());
        $job->backoffBase = 5;
        $job->backoffCap = 120;
        $job->tries = 1;

        $this->assertSame([], $job->backoff());
    }

    /**
     * @test
     */
    public function backoff_is_a_bounded_jittered_schedule_when_configured(): void
    {
        $job = new StageJob($this->stubStage());
        $job->tries = 6;
        $job->backoffBase = 5;
        $job->backoffCap = 120;

        $delays = $job->backoff();

        // tries - 1 delays, each a positive integer no greater than the cap.
        $this->assertCount(5, $delays);
        foreach ($delays as $delay) {
            $this->assertIsInt($delay);
            $this->assertGreaterThanOrEqual(1, $delay);
            $this->assertLessThanOrEqual(120, $delay);
        }
    }

    /**
     * @test
     */
    public function retry_until_is_null_by_default_and_a_deadline_when_set(): void
    {
        $job = new StageJob($this->stubStage());
        $this->assertNull($job->retryUntil());

        $job->retryUntilSeconds = 100;
        $this->assertInstanceOf(DateTimeInterface::class, $job->retryUntil());
    }

    /**
     * @test
     */
    public function handle_renews_the_lease_when_configured(): void
    {
        $owner = (new ImportLock('products', 3600))->acquire();
        $this->assertNotNull($owner);

        $stage = $this->stubStage();
        $job = (new StageJob($stage))->withLockRenew('products', $owner, 3600);
        $job->handle(app(Client::class));

        // The stage ran and we still hold the lease (the renew path executed for
        // the current owner). The array cache used in tests can't assert a TTL
        // extension directly, but a non-owner still cannot acquire — proving the
        // lease was kept rather than dropped.
        $this->assertTrue($stage->handled);
        $this->assertTrue(ImportLock::isHeldBy('products', $owner));
        $this->assertNull((new ImportLock('products', 3600))->acquire());
    }
}
