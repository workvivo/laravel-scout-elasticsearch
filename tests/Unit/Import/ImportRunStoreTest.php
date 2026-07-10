<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use LogicException;
use Matchish\ScoutElasticSearch\Import\ImportRunStore;
use Tests\Fakes\FakeImportRunStore;
use PHPUnit\Framework\TestCase;

final class ImportRunStoreTest extends TestCase
{
    private FakeImportRunStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new FakeImportRunStore();
    }

    /**
     * @test
     */
    public function start_is_idempotent_and_does_not_clear_done_or_rewind_status(): void
    {
        $this->store->start('run', 2, 'products_1');
        $this->store->markDone('run', 0);
        $this->store->markDone('run', 1);
        $this->assertTrue($this->store->claimFinalization('run'));

        $this->store->start('run', 2, 'products_1');

        $this->assertSame(ImportRunStore::STATUS_FINALIZING, $this->store->status('run'));
        $this->assertTrue($this->store->isDone('run', 0));
        $this->assertSame(2, $this->store->snapshot('run')['done']);
    }

    /**
     * @test
     */
    public function start_rejects_total_and_index_mismatches(): void
    {
        $this->store->start('run', 2, 'products_1');

        try {
            $this->store->start('run', 3, 'products_1');
            $this->fail('Expected total mismatch');
        } catch (LogicException $e) {
            $this->assertStringContainsString('total mismatch', $e->getMessage());
        }

        try {
            $this->store->start('run', 2, 'products_2');
            $this->fail('Expected index mismatch');
        } catch (LogicException $e) {
            $this->assertStringContainsString('index mismatch', $e->getMessage());
        }
    }

    /**
     * @test
     */
    public function failure_does_not_win_after_the_same_chunk_is_done(): void
    {
        $this->store->start('run', 1, 'products_1');
        $this->store->markDone('run', 0);

        $this->assertFalse($this->store->failIfNotDone('run', 0));
        $this->assertSame(ImportRunStore::STATUS_RUNNING, $this->store->status('run'));
    }

    /**
     * @test
     */
    public function claim_finalization_requires_completeness_and_resumes_finalizing(): void
    {
        $this->store->start('run', 2, 'products_1');
        $this->store->markDone('run', 0);

        $this->assertFalse($this->store->claimFinalization('run'));
        $this->store->markDone('run', 1);
        $this->assertTrue($this->store->claimFinalization('run'));
        $this->assertTrue($this->store->claimFinalization('run'));
    }

    /**
     * @test
     */
    public function terminal_transitions_require_the_finalize_lock_owner(): void
    {
        $this->store->start('run', 0, 'products_1');
        $this->assertTrue($this->store->acquireFinalizeLock('run', 'owner-a', 60));
        $this->assertTrue($this->store->claimFinalization('run'));

        $this->assertFalse($this->store->finalizeFailedIfFinalizing('run', 'owner-b'));
        $this->assertSame(ImportRunStore::STATUS_FINALIZING, $this->store->status('run'));

        $this->assertTrue($this->store->succeedIfFinalizing('run', 'owner-a'));
        $this->assertSame(ImportRunStore::STATUS_SUCCEEDED, $this->store->status('run'));
    }
}
