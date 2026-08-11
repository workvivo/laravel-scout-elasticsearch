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

        if (! env('REDIS_CLUSTER_TEST_ENABLED', false)) {
            $this->markTestSkipped('Redis Cluster integration tests are not enabled.');
        }

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

        // The chunk-protocol scripts, which is where a cluster is fussiest: the
        // lease and re-dispatch keys live under chunk:{id}:… suffixes and
        // recordFailure() is multi-key (done + failed). All of them are built
        // through key(), so the {token} hash tag keeps them in one slot — without
        // it CROSSSLOT would reject the script outright.
        $this->assertTrue($this->store->claimChunk($token, 1, 'owner-a', 60));
        $this->assertFalse($this->store->claimChunk($token, 1, 'owner-b', 60));
        $this->assertTrue($this->store->chunkInFlight($token, 1));
        $this->store->releaseChunk($token, 1, 'owner-a');
        $this->assertFalse($this->store->chunkInFlight($token, 1));

        $this->assertSame(1, $this->store->bumpRedispatch($token, 1));
        $this->assertSame(1, $this->store->recordFailure($token, 1));
        $this->assertSame(0, $this->store->recordFailure($token, 0));

        $this->store->pushBounds($token, [[0, null, 10], [1, 10, 20]]);
        $this->assertSame(2, $this->store->pendingBounds($token));
        $this->assertSame([[0, null, 10], [1, 10, 20]], $this->store->popBounds($token, 5));

        // The profile trio is the widest EVAL in the store: ONE script over
        // profile:counts + profile:weights + profile:worst. A unit test can only
        // prove the command stream; nothing but a real cluster can prove the
        // cluster accepts three keys in one script, and it only does so because
        // key() puts the {token} hash tag in front of every suffix. These calls
        // returning at all — instead of raising CROSSSLOT — is the assertion.
        $this->assertTrue($this->store->recordProfileFinding($token, 'n_plus_one', 12.0, (string) json_encode([
            'relation' => 'App\Models\Order::items', 'loads' => 12,
        ]), 120, 20));
        // The worse occurrence arrives second, so the kept example also proves
        // the weights hash and the worst hash were written together in one hop.
        $this->assertTrue($this->store->recordProfileFinding($token, 'n_plus_one', 480.0, (string) json_encode([
            'relation' => 'App\Models\Order::user', 'loads' => 480,
        ]), 120, 20));
        $this->assertTrue($this->store->recordProfileFinding($token, 'fetch_dominant', 900.0, '{"fetch_ms":900}', 120, 20));

        // Deduped to one row per code, sorted by code, worst example kept — the
        // documented shape, read back off the cluster rather than a fake.
        $this->assertSame([
            'fetch_dominant' => [
                'count' => 1,
                'weight' => 900.0,
                'data' => ['fetch_ms' => 900],
            ],
            'n_plus_one' => [
                'count' => 2,
                'weight' => 480.0,
                'data' => ['relation' => 'App\Models\Order::user', 'loads' => 480],
            ],
        ], $this->store->profileFindings($token));

        $this->store->refreshTtls($token, 3600);

        // The findings above were recorded with a deliberately short TTL, so a
        // stretched TTL here is proof that refreshTtls() reaches all three
        // profile keys on a cluster — a long import must not lose its
        // diagnostics before the terminal that started it reads them.
        foreach (['profile:counts', 'profile:weights', 'profile:worst'] as $suffix) {
            $this->assertGreaterThan(
                3000,
                (int) $this->app['redis']->connection('scout_import')->ttl('scout:import:run:{'.$token.'}:'.$suffix),
                $suffix
            );
        }

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
