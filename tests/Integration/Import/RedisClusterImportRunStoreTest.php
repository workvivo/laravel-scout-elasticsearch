<?php

declare(strict_types=1);

namespace Tests\Integration\Import;

use Illuminate\Redis\Connections\PhpRedisClusterConnection;
use Matchish\ScoutElasticSearch\Import\ImportRunStore;
use Matchish\ScoutElasticSearch\Import\RedisImportRunStore;
use Orchestra\Testbench\TestCase;

final class RedisClusterImportRunStoreTest extends TestCase
{
    private RedisImportRunStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('redis')) {
            $this->markTestSkipped('The phpredis extension is not available.');
        }

        $this->store = new RedisImportRunStore($this->app['redis']);

        try {
            $this->app['redis']->connection('scout_import');
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis Cluster is not available for coordinator integration tests.');
        }
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.redis.client', 'phpredis');
        $app['config']->set('database.redis.options.cluster', 'redis');
        $app['config']->set('database.redis.options.timeout', 5);
        $app['config']->set('database.redis.options.read_timeout', 5);
        $app['config']->set('database.redis.clusters.scout_import', array_map(
            fn (int $node) => [
                'host' => env('REDIS_CLUSTER_HOST_'.$node, 'redis-cluster-'.$node),
                'password' => null,
                'port' => (int) env('REDIS_CLUSTER_PORT_'.$node, 6379),
            ],
            range(0, 5)
        ));
        $app['config']->set('elasticsearch.import.redis.connection', 'scout_import');
    }

    /**
     * @test
     */
    public function coordinator_runs_against_a_real_phpredis_cluster_connection(): void
    {
        $this->assertInstanceOf(PhpRedisClusterConnection::class, $this->app['redis']->connection('scout_import'));
        $this->assertTrue($this->store->supportsAtomicCoordination());

        $token = 'cluster-run-'.bin2hex(random_bytes(8));

        $this->store->start($token, 2, 'products_1');

        $this->assertSame(1, $this->store->markDone($token, 0));
        $this->assertFalse($this->store->claimFinalization($token));

        $this->assertSame(2, $this->store->markDone($token, 1));
        $this->assertTrue($this->store->acquireFinalizeLock($token, 'owner-a', 60));
        $this->assertTrue($this->store->claimFinalization($token));
        $this->assertTrue($this->store->succeedIfFinalizing($token, 'owner-a'));

        $this->assertSame(ImportRunStore::STATUS_SUCCEEDED, $this->store->status($token));
        $this->assertSame([
            'status' => ImportRunStore::STATUS_SUCCEEDED,
            'done' => 2,
            'total' => 2,
            'index' => 'products_1',
        ], $this->store->snapshot($token));
    }
}
