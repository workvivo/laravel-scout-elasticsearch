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
    public function inline_import_reports_a_summary_with_document_count_and_time(): void
    {
        $dispatcher = Product::getEventDispatcher();
        Product::unsetEventDispatcher();

        factory(Product::class, 6)->create();

        Product::setEventDispatcher($dispatcher);

        $output = new BufferedOutput();
        Artisan::call('scout:import', ['searchable' => [Product::class]], $output);

        $text = $output->fetch();
        $this->assertStringContainsString('6 documents', $text);
        $this->assertStringContainsString('documents in', $text); // includes elapsed time
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

        $lines = array_map('trim', explode("\n", $output->fetch()));

        $productStart = array_search(trans('scout::import.start', ['searchable' => Product::class]), $lines);
        $bookStart = array_search(trans('scout::import.start', ['searchable' => Book::class]), $lines);

        // Each searchable prints a start line and a summary line (with the
        // document count + elapsed time). Assert on messages rather than
        // hardcoded progress-bar offsets so the test survives pipeline changes,
        // and locate the summary by content since it carries dynamic values.
        $summaryLine = function (string $searchable) use ($lines) {
            foreach ($lines as $i => $line) {
                if (str_contains($line, $searchable) && str_contains($line, 'documents in')) {
                    return $i;
                }
            }

            return false;
        };
        $productDone = $summaryLine(Product::class);
        $bookDone = $summaryLine(Book::class);

        $this->assertNotFalse($productStart);
        $this->assertNotFalse($bookStart);
        $this->assertNotFalse($productDone, 'Product summary line missing');
        $this->assertNotFalse($bookDone, 'Book summary line missing');

        // Start precedes summary for each searchable, and Product (passed first)
        // is imported before Book starts.
        $this->assertLessThan($productDone, $productStart);
        $this->assertLessThan($bookStart, $productDone);
        $this->assertLessThan($bookDone, $bookStart);
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

    /**
     * @test
     */
    public function fast_plan_still_indexes_only_searchable_records(): void
    {
        $dispatcher = Post::getEventDispatcher();
        Post::unsetEventDispatcher();

        // Mix drafts and published across several chunks. --fast-plan plans over
        // all posts (drafts included), but the fetch must still index published
        // only — no drafts leaking in.
        $published = 0;
        for ($i = 0; $i < 12; $i++) {
            $state = $i % 2 === 0 ? 'published' : 'draft';
            factory(Post::class)->states($state)->create();
            $published += $state === 'published' ? 1 : 0;
        }

        Post::setEventDispatcher($dispatcher);

        Artisan::call('scout:import', ['searchable' => [Post::class], '--fast-plan' => true]);

        $response = $this->elasticsearch->search([
            'index' => (new Post())->searchableAs(),
            'body' => ['query' => ['match_all' => new stdClass()]],
        ]);

        $this->assertEquals($published, $response['hits']['total']['value']);
    }
}
