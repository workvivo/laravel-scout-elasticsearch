<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Book;
use App\BookWithCustomKey;
use App\Post;
use App\Product;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Matchish\ScoutElasticSearch\Jobs\Import;
use Matchish\ScoutElasticSearch\Jobs\QueueableJob;
use stdClass;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\IntegrationTestCase;

final class ImportCommandTest extends IntegrationTestCase
{
    /**
     * @test
     */
    public function import_entites(): void
    {
        $dispatcher = Product::getEventDispatcher();
        Product::unsetEventDispatcher();

        $productsAmount = random_int(1, 5);

        factory(Product::class, $productsAmount)->create();

        $productsUnsearchableAmount = random_int(1, 5);
        factory(Product::class, $productsUnsearchableAmount)->states(['archive'])->create();

        Product::setEventDispatcher($dispatcher);

        Artisan::call('scout:import', [
            'searchable' => [Product::class],
        ]);
        $params = [
            'index' => 'products',
            'body' => [
                'query' => [
                    'match_all' => new stdClass(),
                ],
            ],
        ];
        $response = $this->elasticsearch->search($params);
        $this->assertEquals($productsAmount, $response['hits']['total']['value']);
    }

    /**
     * @test
     */
    public function import_entites_in_queue(): void
    {
        $this->app['config']->set('scout.queue', ['connection' => 'sync', 'queue' => 'scout']);

        $dispatcher = Product::getEventDispatcher();
        Product::unsetEventDispatcher();

        $productsAmount = random_int(1, 5);
        factory(Product::class, $productsAmount)->create();

        Product::setEventDispatcher($dispatcher);

        Artisan::call('scout:import');
        $params = [
            'index' => 'products',
            'body' => [
                'query' => [
                    'match_all' => new stdClass(),
                ],
            ],
        ];
        $response = $this->elasticsearch->search($params);
        $this->assertEquals($productsAmount, $response['hits']['total']['value']);
    }

    /**
     * @test
     */
    public function import_all_pages(): void
    {
        $dispatcher = Product::getEventDispatcher();
        Product::unsetEventDispatcher();

        $productsAmount = 10;

        factory(Product::class, $productsAmount)->create();

        Product::setEventDispatcher($dispatcher);

        Artisan::call('scout:import');
        $params = [
            'index' => (new Product())->searchableAs(),
            'body' => [
                'query' => [
                    'match_all' => new stdClass(),
                ],
            ],
        ];
        $response = $this->elasticsearch->search($params);
        $this->assertEquals($productsAmount, $response['hits']['total']['value']);
    }

    /**
     * @test
     */
    public function import_with_custom_key_all_pages(): void
    {
        $this->app['config']['scout.key'] = 'title';

        $dispatcher = Book::getEventDispatcher();

        Book::unsetEventDispatcher();

        $booksAmount = 10;

        factory(Book::class, $booksAmount)->create();

        Book::setEventDispatcher($dispatcher);

        Artisan::call('scout:import');

        $params = [
            'index' => (new BookWithCustomKey())->searchableAs(),
            'body' => [
                'query' => [
                    'match_all' => new stdClass(),
                ],
            ],
        ];

        $response = $this->elasticsearch->search($params);

        $this->assertEquals($booksAmount, $response['hits']['total']['value']);
    }

    /**
     * @test
     */
    public function remove_old_index_after_switching_to_new(): void
    {
        $params = [
            'index' => 'products_old',
            'body' => [
                'aliases' => ['products' => new stdClass()],
                'settings' => [
                    'number_of_shards' => 1,
                    'number_of_replicas' => 0,
                ],
            ],
        ];
        $this->elasticsearch->indices()->create($params);
        $dispatcher = Product::getEventDispatcher();
        Product::unsetEventDispatcher();

        $productsAmount = random_int(1, 5);

        factory(Product::class, $productsAmount)->create();

        Product::setEventDispatcher($dispatcher);

        Artisan::call('scout:import');

        $this->assertFalse($this->elasticsearch->indices()->exists(['index' => 'products_old']), 'Old index must be deleted');
    }

    /**
     * @test
     */
    public function progress_report(): void
    {
        $output = new BufferedOutput();
        Artisan::call('scout:import', ['searchable' => [Product::class, Book::class]], $output);

        $output = explode("\n", $output->fetch());
        $this->assertEquals(
            trans('scout::import.start', ['searchable' => Product::class]),
            trim($output[0])
        );
        $this->assertEquals(
            '[OK] '.trans('scout::import.done', ['searchable' => Product::class]),
            trim($output[17])
        );
        $this->assertEquals(
            trans('scout::import.start', ['searchable' => Book::class]),
            trim($output[19])
        );
        $this->assertEquals(
            '[OK] '.trans('scout::import.done', ['searchable' => Book::class]),
            trim($output[36])
        );
    }

    /**
     * @test
     */
    public function progress_report_in_queue(): void
    {
        $this->app['config']->set('scout.queue', ['connection' => 'sync', 'queue' => 'scout']);

        $output = new BufferedOutput();
        Artisan::call('scout:import', [], $output);

        $output = array_map('trim', explode("\n", $output->fetch()));

        $this->assertContains(trans('scout::import.start', ['searchable' => Product::class]), $output);
        $this->assertContains('[OK] '.trans('scout::import.done.queue', ['searchable' => Product::class]), $output);
    }

    /**
     * @test
     */
    public function queue_timeout_configuration(): void
    {
        Bus::fake([
            QueueableJob::class,
        ]);

        $this->app['config']->set('scout.queue', ['connection' => 'sync', 'queue' => 'scout']);
        $this->app['config']->set('elasticsearch.queue.timeout', 2);

        $output = new BufferedOutput();
        Artisan::call('scout:import', [], $output);

        $output = array_map('trim', explode("\n", $output->fetch()));

        $this->assertContains(trans('scout::import.start', ['searchable' => Product::class]), $output);
        $this->assertContains('[OK] '.trans('scout::import.done.queue', ['searchable' => Product::class]), $output);

        Bus::assertDispatched(function (QueueableJob $job) {
            return $job->timeout === 2;
        });
    }

    /**
     * @test
     */
    public function chained_queue_timeout_configuration(): void
    {
        Bus::fake([
            Import::class,
        ]);

        $this->app['config']->set('scout.queue', ['connection' => 'sync', 'queue' => 'scout']);
        $this->app['config']->set('elasticsearch.queue.timeout', 2);

        $output = new BufferedOutput();
        Artisan::call('scout:import', [], $output);

        $output = array_map('trim', explode("\n", $output->fetch()));

        $this->assertContains(trans('scout::import.start', ['searchable' => Product::class]), $output);
        $this->assertContains('[OK] '.trans('scout::import.done.queue', ['searchable' => Product::class]), $output);

        Bus::assertDispatched(function (Import $job) {
            return $job->timeout === 2;
        });
    }

    /**
     * @test
     */
    public function chained_queue_timeout_configuration_with_null_value(): void
    {
        Bus::fake([
            Import::class,
        ]);

        $this->app['config']->set('scout.queue', ['connection' => 'sync', 'queue' => 'scout']);
        $this->app['config']->set('elasticsearch.queue.timeout', null);

        $output = new BufferedOutput();
        Artisan::call('scout:import', [], $output);

        $output = array_map('trim', explode("\n", $output->fetch()));

        $this->assertContains(trans('scout::import.start', ['searchable' => Product::class]), $output);
        $this->assertContains('[OK] '.trans('scout::import.done.queue', ['searchable' => Product::class]), $output);

        Bus::assertDispatched(function (Import $job) {
            return $job->timeout === null;
        });
    }

    /**
     * @test
     */
    public function chained_queue_timeout_configuration_with_empty_string(): void
    {
        Bus::fake([
            Import::class,
        ]);

        $this->app['config']->set('scout.queue', ['connection' => 'sync', 'queue' => 'scout']);
        $this->app['config']->set('elasticsearch.queue.timeout', '');

        $output = new BufferedOutput();
        Artisan::call('scout:import', [], $output);

        $output = array_map('trim', explode("\n", $output->fetch()));

        $this->assertContains(trans('scout::import.start', ['searchable' => Product::class]), $output);
        $this->assertContains('[OK] '.trans('scout::import.done.queue', ['searchable' => Product::class]), $output);

        Bus::assertDispatched(function (Import $job) {
            return $job->timeout === null;
        });
    }

    /**
     * @test
     */
    public function make_all_searchable_using_method_is_called_in_the_product_model(): void
    {
        $dispatcher = Post::getEventDispatcher();
        Post::unsetEventDispatcher();

        factory(Post::class)->states('draft')->create();
        factory(Post::class)->states('draft')->create();
        factory(Post::class)->states('draft')->create();
        factory(Post::class)->states('published')->create();

        Post::setEventDispatcher($dispatcher);

        // Call the makeAllSearchableUsing method on the Product model
        Artisan::call('scout:import', ['searchable' => [Post::class]]);

        $params = [
            'index' => (new Post())->searchableAs(),
            'body' => [
                'query' => [
                    'match_all' => new stdClass(),
                ],
            ],
        ];

        $response = $this->elasticsearch->search($params);

        // Assert that only the published posts are searchable
        // bacause in the Post model we have defined the makeAllSearchableUsing method
        // which returns only the published posts.
        $this->assertEquals(1, $response['hits']['total']['value']);
    }
}
