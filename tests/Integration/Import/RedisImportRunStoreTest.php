<?php

declare(strict_types=1);

namespace Tests\Integration\Import;

use Matchish\ScoutElasticSearch\Import\ImportRunStore;
use Matchish\ScoutElasticSearch\Import\RedisImportRunStore;
use Orchestra\Testbench\TestCase;

final class RedisImportRunStoreTest extends TestCase
{
    private RedisImportRunStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->app['redis']->connection()->ping();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis is not available for coordinator integration tests.');
        }

        $this->store = new RedisImportRunStore($this->app['redis']);
    }

    /**
     * @test
     */
    public function lua_transitions_are_guarded_and_lock_owner_aware(): void
    {
        $token = 'test-run-'.bin2hex(random_bytes(8));

        $this->store->start($token, 2, 'products_1');
        $this->store->markDone($token, 0);

        $this->assertFalse($this->store->claimFinalization($token));
        $this->assertFalse($this->store->failIfNotDone($token, 0));
        $this->assertTrue($this->store->failIfNotDone($token, 1));
        $this->assertSame(ImportRunStore::STATUS_FAILED, $this->store->status($token));
    }

    /**
     * @test
     */
    public function finalization_resumes_from_finalizing_and_rejects_stale_terminal_writes(): void
    {
        $token = 'test-run-'.bin2hex(random_bytes(8));

        $this->store->start($token, 0, 'products_1');
        $this->assertTrue($this->store->acquireFinalizeLock($token, 'owner-a', 60));
        $this->assertTrue($this->store->claimFinalization($token));
        $this->store->releaseFinalizeLock($token, 'owner-a');

        $this->assertTrue($this->store->acquireFinalizeLock($token, 'owner-b', 60));
        $this->assertTrue($this->store->claimFinalization($token));
        $this->assertFalse($this->store->finalizeFailedIfFinalizing($token, 'owner-a'));
        $this->assertTrue($this->store->succeedIfFinalizing($token, 'owner-b'));
        $this->assertSame(ImportRunStore::STATUS_SUCCEEDED, $this->store->status($token));
    }
}
