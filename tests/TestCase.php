<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Support\Facades\Artisan;
use Laravel\Scout\ScoutServiceProvider;
use Matchish\ScoutElasticSearch\ElasticSearchServiceProvider;
use Matchish\ScoutElasticSearch\Engines\ElasticSearchEngine;
use Matchish\ScoutElasticSearch\ScoutElasticSearchServiceProvider;
use OpenSearch\Client;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * @var Client|null
     */
    protected $elasticsearch;

    /**
     * Indices that existed on the cluster before the test ran. Anything not
     * in this snapshot is treated as test-created and removed in tearDown,
     * so pre-existing user indices are always preserved.
     *
     * @var array<string,true>|null
     */
    private ?array $preexistingIndices = null;

    public function setUp(): void
    {
        parent::setUp();

        $this->app->setBasePath(__DIR__.'/laravel');

        $this->withFactories(database_path('factories'));

        Artisan::call('migrate:fresh');

        $this->elasticsearch = $this->app->make(Client::class);
        $this->deleteKnownTestIndices();
        $this->preexistingIndices = $this->listIndices();
    }

    public function tearDown(): void
    {
        if ($this->elasticsearch !== null && $this->preexistingIndices !== null) {
            $this->deleteIndicesCreatedDuringTest();
        }

        parent::tearDown();
    }

    /**
     * Hardcoded test index names live in many test files; if a previous
     * (crashed) run left them behind they'd be in the snapshot and would
     * never be cleaned. Wipe known test patterns once on setUp so the suite
     * always starts from a clean slate while still leaving user indices
     * (anything not matching these patterns) alone.
     */
    private function deleteKnownTestIndices(): void
    {
        $patterns = [
            'products', 'products_*',
            'new_products', 'new_products_*',
            'books', 'books_*',
            'new_books', 'new_books_*',
            'books_with_custom_key', 'books_with_custom_key_*',
            'new_books_with_custom_key', 'new_books_with_custom_key_*',
            'posts', 'posts_*',
            'new_posts', 'new_posts_*',
            'tickets', 'tickets_*',
            'new_tickets', 'new_tickets_*',
        ];

        $this->elasticsearch->indices()->delete([
            'index' => implode(',', $patterns),
            'ignore_unavailable' => true,
        ]);
    }

    private function deleteIndicesCreatedDuringTest(): void
    {
        $current = $this->listIndices();
        $created = array_diff_key($current, $this->preexistingIndices);

        if ($created === []) {
            return;
        }

        $this->elasticsearch->indices()->delete([
            'index' => implode(',', array_keys($created)),
            'ignore_unavailable' => true,
        ]);
    }

    /**
     * @return array<string,true>
     */
    private function listIndices(): array
    {
        $response = $this->elasticsearch->cat()->indices(['h' => 'index', 'format' => 'json']);

        $indices = [];
        foreach ($response as $row) {
            $name = $row['index'] ?? null;
            if (is_string($name) && $name !== '') {
                $indices[$name] = true;
            }
        }

        return $indices;
    }

    /**
     * Define environment setup.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('scout.driver', ElasticSearchEngine::class);
        $app['config']->set('scout.chunk.searchable', 3);
        $app['config']->set('scout.queue', false);

        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('database.default', env('DB_CONNECTION', 'sqlite'));
    }

    protected function getPackageProviders($app)
    {
        return [
            ScoutServiceProvider::class,
            ScoutElasticSearchServiceProvider::class,
            ElasticSearchServiceProvider::class,
        ];
    }
}
