<?php

namespace Matchish\ScoutElasticSearch;

use OpenSearch\Client;
use OpenSearch\Common\Exceptions\NoNodesAvailableException;
use Tests\TestCase;

class ScoutElasticSearchServiceProviderTest extends TestCase
{
    /**
     * @test
     */
    public function config_is_merged_from_the_package()
    {
        $distConfig = require __DIR__.'/../../config/elasticsearch.php';

        $this->assertSame($distConfig, config('elasticsearch'));
    }

    /**
     * @test
     */
    public function config_publishing()
    {
        $provider = new ElasticSearchServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        $this->artisan('vendor:publish', [
            '--tag' => 'config',
        ])->assertExitCode(0);

        $this->assertFileExists(config_path('elasticsearch.php'));

        \File::delete(config_path('elasticsearch.php'));
    }

    /**
     * @test
     */
    public function it_provides()
    {
        $provider = new ElasticSearchServiceProvider($this->app);
        $this->assertEquals([Client::class], $provider->provides());
    }

    /**
     * @test
     */
    public function config_with_username()
    {
        $this->app['config']->set('elasticsearch.host', 'http://localhost:9200');
        $this->app['config']->set('elasticsearch.user', 'elastic');
        $this->app['config']->set('elasticsearch.password', 'pass');
        $provider = new ElasticSearchServiceProvider($this->app);
        $this->assertEquals([Client::class], $provider->provides());
        /** @var Client $client */
        $client = $this->app[Client::class];
        try {
            $client->info();
        } catch (NoNodesAvailableException $e) {
            $this->assertTrue(true);
        }

        $this->assertEquals('elastic:pass', $client->transport->getLastConnection()->getUserPass());
    }

    /*
     * @test
     */
    public function config_with_cloud_id()
    {
        $this->app['config']->set('elasticsearch.cloud_id', 'Test:ZXUtY2VudHJhbC0xLmF3cy5jbG91ZC5lcy5pbyQ0ZGU0NmNlZDhkOGQ0NTk2OTZlNTQ0ZmU1ZjMyYjk5OSRlY2I0YTJlZmY0OTA0ZDliOTE5NzMzMmQwOWNjOTY5Ng==');
        $this->app['config']->set('elasticsearch.api_key', '123456');
        $this->app['config']->set('elasticsearch.user', null);
        $provider = new ElasticSearchServiceProvider($this->app);
        $this->assertEquals([Client::class], $provider->provides());
        /** @var Client $client */
        $client = $this->app[Client::class];
        $this->assertEquals('ApiKey 123456', $client->getTransport()->getHeaders()['Authorization']);
        $this->assertEquals('4de46ced8d8d459696e544fe5f32b999.eu-central-1.aws.cloud.es.io', $client->getTransport()->getNodePool()->nextNode()->getUri()->getHost());
    }
}
