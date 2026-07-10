<p align="center">
  <a href="https://savelife.in.ua/en/donate/">
    <img alt="Support Ukraine" src="http://supportua.org.ua/wp-content/uploads/2015/05/content-logo-main.png" >
  </a>
<!--   <a href="https://github.com/matchish/laravel-scout-elasticsearch">
    <img alt="Scout ElasticSearch" src="https://raw.githubusercontent.com/matchish/laravel-scout-elasticsearch/master/docs/banner.svg?sanitize=true" >
  </a> -->

  <img alt="Import progress report" src="https://raw.githubusercontent.com/matchish/laravel-scout-elasticsearch/master/docs/demo.gif" >

  <p align="center">
    <a href="#"><img src="https://github.com/matchish/laravel-scout-elasticsearch/actions/workflows/test-application.yaml/badge.svg" alt="Build Status"></img></a>
    <a href="https://packagist.org/packages/matchish/laravel-scout-elasticsearch"><img src="https://poser.pugx.org/matchish/laravel-scout-elasticsearch/d/total.svg" alt="Total Downloads"></a>
    <a href="https://packagist.org/packages/matchish/laravel-scout-elasticsearch"><img src="https://poser.pugx.org/matchish/laravel-scout-elasticsearch/v/stable.svg" alt="Latest Version"></a>
    <a href="https://packagist.org/packages/matchish/laravel-scout-elasticsearch"><img src="https://poser.pugx.org/matchish/laravel-scout-elasticsearch/license.svg" alt="License"></a>
  </p>
</p>

#### For Laravel Framework < 6.0.0 use [3.x](https://github.com/matchish/laravel-scout-elasticsearch/tree/3.x) branch

The package provides the perfect starting point to integrate
ElasticSearch into your Laravel application. It is carefully crafted to simplify the usage
of ElasticSearch within the [Laravel Framework](https://laravel.com).

It’s built on top of the latest release of [Laravel Scout](https://laravel.com/docs/scout), the official Laravel search
package. Using this package, you are free to take advantage of all of Laravel Scout’s
great features, and at the same time leverage the complete set of ElasticSearch’s search experience.

If you need any help, [stack overflow](https://stackoverflow.com/questions/tagged/laravel-scout%20laravel%20elasticsearch) is the preferred and recommended way to ask support questions.

## :two_hearts: Features
Don't forget to :star: the package if you like it. :pray:

- Laravel Scout 10.x support
- Laravel Nova support
- [Search amongst multiple models](#search-amongst-multiple-models)
- [**Zero downtime** reimport](#zero-downtime-reimport) - it’s a breeze to import data in production.
- [Eager load relations](#eager-load) - speed up your import.
- Import all searchable models at once.
- A fully configurable mapping for each model.
- Full power of ElasticSearch in your queries.

## :warning: Requirements

- PHP version >= 8.0
- Laravel Framework version >= 8.0.0

| Elasticsearch version | ElasticsearchDSL version |
|-----------------------|--------------------------|
| >= 8.0                | >= 8.0.0                 |
| >= 7.0                | >= 3.0.0                 |
| >= 6.0, < 7.0         | < 3.0.0                  |

## :rocket: Installation

Use composer to install the package:

`composer require matchish/laravel-scout-elasticsearch`

Set env variables
```
SCOUT_DRIVER=Matchish\ScoutElasticSearch\Engines\ElasticSearchEngine
```

The package uses `\ElasticSearch\Client` from official package, but does not try to configure it,
so feel free do it in your app service provider.
But if you don't want to do it right now,
you can use `Matchish\ElasticSearchServiceProvider` from the package.
Register the provider, adding to `config/app.php`
```php
'providers' => [
    // Other Service Providers

    \Matchish\ScoutElasticSearch\ElasticSearchServiceProvider::class
],
```
Set `ELASTICSEARCH_HOST` env variable
```
ELASTICSEARCH_HOST=host:port
```
or use commas as separator for additional nodes
```
ELASTICSEARCH_HOST=host:port,host:port
```
And publish config example for elasticsearch
`php artisan vendor:publish --tag config`

## :bulb: Usage

> **Note:** This package adds functionalities to [Laravel Scout](https://github.com/laravel/scout), and for this reason, we encourage you to **read the Scout documentation first**. Documentation for Scout can be found on the [Laravel website](https://laravel.com/docs/scout).

### Index [settings](https://www.elastic.co/guide/en/elasticsearch/reference/current/indices-create-index.html#create-index-settings) and [mappings](https://www.elastic.co/guide/en/elasticsearch/reference/current/indices-create-index.html#mappings)
It is very important to define the mapping when we create an index — an inappropriate preliminary definition and mapping may result in the wrong search results.

To define mappings or settings for index, set config with right value.

For example if method `searchableAs` returns
`products` string

Config key for mappings should be
`elasticsearch.indices.mappings.products`
Or you you can specify default mappings with config key
`elasticsearch.indices.mappings.default`

Same way you can define settings

For index `products` it will be
`elasticsearch.indices.settings.products`

And for default settings
`elasticsearch.indices.settings.default`

### Eager load
To speed up import you can eager load relations on import using global scopes.

You should configure `ImportSourceFactory` in your service provider(`register` method)
```php
use Matchish\ScoutElasticSearch\Searchable\ImportSourceFactory;
...
public function register(): void
{
$this->app->bind(ImportSourceFactory::class, MyImportSourceFactory::class);
```
Here is an example of `MyImportSourceFactory`
```php
namespace Matchish\ScoutElasticSearch\Searchable;

final class MyImportSourceFactory implements ImportSourceFactory
{
    public static function from(string $className): ImportSource
    {
        //Add all required scopes
        return new DefaultImportSource($className, [new WithCommentsScope()]);
    }
}

class WithCommentsScope implements Scope {

    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * @param \Illuminate\Database\Eloquent\Builder $builder
     * @param \Illuminate\Database\Eloquent\Model $model
     * @return void
     */
    public function apply(Builder $builder, Model $model)
    {
        $builder->with('comments');
    }
}
```

You can also customize your indexed data when you save models by leveraging the [`toSearchableArray`](https://laravel.com/docs/9.x/scout#configuring-searchable-data) method
provided by Laravel Scout through the `Searchable` trait

#### Example:
```php
class Product extends Model
{
    use Searchable;

    /**
     * Get the indexable data array for the model.
     *
     * @return array
     */
    public function toSearchableArray()
    {
        $with = [
            'categories',
        ];

        $this->loadMissing($with);

        return $this->toArray();
    }
}
```

This example will make sure the categories relationship gets always loaded on the model when
saving it.
### Zero downtime reimport
While working in production, to keep your existing search experience available while reimporting your data, you also can use `scout:import` Artisan command:

`php artisan scout:import`

The command create new temporary index, import all models to it, and then switch to the index and remove old index.

When it runs inline (no `scout.queue`), it finishes with a summary of how many
documents were indexed and how long it took, e.g.
`[App\Product] imported: 48213 documents in 4m 12s.` When `scout.queue` is set the
job is queued and the command just reports that it was dispatched.

#### Parallel import

Imports run chunk by chunk in a single job by default. On large tables you can fan the
chunks out across your queue workers with `--parallel`:

```
php artisan scout:import "App\Models\Product" --parallel
```

Each chunk (a keyset key-range, see below) becomes its own queued job, so many
"pages" of the same model are pulled and indexed at once. The alias is only
swapped to the new index once every chunk succeeds; if any chunk fails, the old
index keeps serving, the failure is reported, and the half-filled new index is
removed so it does not linger on the cluster.

`--parallel` requires Redis for import coordination. Chunk completions are
tracked in a Redis run record, and the per-model import lock still needs an
atomic cache store; the file cache driver is not supported for parallel imports.
Laravel may connect to Redis through either Predis or PhpRedis. This package's
test suite uses Predis so the Redis coordinator tests do not require the PHP
Redis extension.

`--parallel` does **not** require `scout.queue` (that flag only governs per-model
index syncs). It resolves its queue connection from `--connection`, then the
`scout.queue` connection if set, then your app's **default queue**
(`config('queue.default')`) — so it uses your existing queues (e.g. SQS, Redis)
out of the box. Override per run with `--connection` and `--queue`, handy for
routing a big reindex onto a dedicated queue:

```
php artisan scout:import "App\Models\Product" --parallel --connection=redis --queue=reindex
```

If the resolved connection is the `sync` driver there is no real parallelism, so
the command stops with an error. Pass `--force` to run it inline on `sync` anyway:

```
php artisan scout:import "App\Models\Product" --parallel --force
```

By default `--parallel` dispatches and returns immediately (the work runs on your
workers). Add `--wait` to block and watch chunk progress, then get
a summary (documents indexed, chunk count, elapsed time; non-zero exit if any
chunk failed):

```
php artisan scout:import "App\Models\Product" --parallel --wait
```

`--wait` polls the Redis run record, so it needs workers running to make progress. The
prepare chain (clean up → create index → plan chunks) publishes a heartbeat as
each step runs, and `--wait` measures its timeout against the time since the
**last observed step**, not since dispatch — so a chain that keeps progressing
never times out just because a busy queue was slow to schedule each hop. The
timeout (`elasticsearch.import.wait_timeout`, default 120s /
`SCOUT_IMPORT_WAIT_TIMEOUT`) therefore bounds a single idle gap, and on giving up
it distinguishes two cases: **no worker picked it up** (nothing ran within the
window — check a worker is consuming that connection/queue, or raise the timeout
on a deep backlog) versus **picked up but went silent** (a slow prepare stage, or
a crashed/OOM worker). Either way the work stays queued and the command exits —
a `--wait` timeout is not a failure; the import still runs on your workers.

##### Tuning chunk size and planning

`--chunk=N` overrides `scout.chunk.searchable` for a single run. Fewer, larger
chunks mean fewer planning queries and fewer jobs to enqueue (faster start-up),
at the cost of coarser parallelism and more rows held per job:

```
php artisan scout:import "App\Models\Comment" --parallel --wait --chunk=5000
```

Chunk boundaries are planned by seeking the primary keys through the same query
`makeAllSearchableUsing` builds — including any eager-load join. On a joined
model that join runs on every planning query. `--fast-plan` plans the boundaries
from a bare key query instead (skipping the join/filters), which is much cheaper:

```
php artisan scout:import "App\Models\Comment" --parallel --wait --fast-plan
```

This is safe: the per-chunk fetch still applies the full join/filters and
`shouldBeSearchable()`, so the planned ranges only widen — every searchable
record is still covered exactly once and no extra records are indexed. The only
effect is that some chunks may fetch fewer rows than their key-span suggests.

##### Running large parallel imports safely

`--parallel` fans every chunk of a model into independent queue jobs coordinated
by Redis. To keep a big reindex healthy:

- **Fewer, larger chunks.** Raising the chunk size reduces planning work and the
  number of queued jobs. Set it per model (keyed by `searchableAs()`) so small
  models keep the default:

  ```php
  // config/elasticsearch.php → 'import'
  'chunk' => [
      'default' => null,      // null → scout.chunk.searchable (500)
      'products' => 2000,
      'orders'   => 5000,
  ],
  ```

  Precedence is `--chunk` > per-model > `default` > `scout.chunk.searchable`.

- **Bound the worker pool.** A handful of workers on a dedicated queue is often
  enough; very high concurrency can still overload the database or cluster even
  though completion tracking is Redis-backed.

- **Keep `retry_after` > `timeout`.** If a chunk runs longer than the queue
  connection's `retry_after` (or an SQS visibility timeout), the queue makes a
  duplicate available while the first is still running and the job trips
  `MaxAttemptsExceeded`. Set the connection's `retry_after` comfortably above
  `elasticsearch.queue.timeout` (`SCOUT_QUEUE_TIMEOUT`).

- **Chunk retries.** By default a chunk that throws fails immediately
  (`tries=1`). For transient failures, let chunks retry with jittered exponential
  backoff — the re-index is idempotent, so a retried chunk just overwrites:

  ```php
  // config/elasticsearch.php → 'import'
  'retry' => [
      'tries' => 3,           // SCOUT_IMPORT_RETRY_TRIES (1 = no retry)
      'backoff_base' => 5,    // SCOUT_IMPORT_RETRY_BACKOFF_BASE, seconds
      'backoff_cap' => 120,   // SCOUT_IMPORT_RETRY_BACKOFF_CAP, seconds
      'retry_until' => 0,     // SCOUT_IMPORT_RETRY_UNTIL (0 = tries only)
  ],
  ```

  `SCOUT_IMPORT_RETRY_TRIES=3` means each chunk can run three times total: the
  first attempt plus two retries. Keep this bounded; it is for transient
  infrastructure failures, not for retrying bad mappings or permanently failing
  data.

A failed parallel import never swaps the alias and removes its half-built index,
so re-running is safe — the live index keeps serving until a run fully succeeds.
Once a run fails, remaining chunk jobs skip work before writing, and rollback
deletes the unpromoted index after a short drain delay.
The destructive cleanup, rollback, and final alias swap all re-check the
per-model lock owner first, so a stale run whose lease expired cannot delete or
promote over a newer run's in-progress index.

#### Concurrent imports

Chunk boundaries are frozen into each job at dispatch time (keyset seek, not offset),
so imports for **different** models are fully isolated and safe to run at the same
time. To stop a second run for the **same** model from racing the alias swap, a
per-model lock is taken while an import is in flight — a second `scout:import` for a
model already being imported is skipped with a warning.

The lock is a **renewable lease**: the running import extends it as each chunk
completes, so the TTL (`elasticsearch.import.lock_ttl`, default 3600s /
`SCOUT_IMPORT_LOCK_TTL`) is an *inactivity* timeout, not a cap on total import
time. This matters for large tables — a multi-hour import stays locked the whole
time as long as it keeps making progress, while a crashed run self-heals after
one idle TTL window. Set the TTL comfortably above the time a single chunk takes
to index. It needs a cache store with atomic `add` (`redis`, `memcached`,
`database`, or `dynamodb` — **not** the `file` driver).

### Search

To be fully compatible with original scout package, this package does not add new methods.
So how we can build complex queries?
There is two ways.
By default, when you pass a query to the `search` method, the engine builds a [query_string](https://www.elastic.co/guide/en/elasticsearch/reference/current/query-dsl-query-string-query.html) query, so you can build queries like this

```php
Product::search('(title:this OR description:this) AND (title:that OR description:that)')
```

If it's not enough in your case you can pass a callback to the query builder

```php
$results = Product::search('zonga', function(\Elastic\Elasticsearch\Client $client, $body) {

    $minPriceAggregation = new MinAggregation('min_price');
    $minPriceAggregation->setField('price');

    $maxPriceAggregation = new MaxAggregation('max_price');
    $maxPriceAggregation->setField('price');

    $brandTermAggregation = new TermsAggregation('brand');
    $brandTermAggregation->setField('brand');

    $body->addAggregation($minPriceAggregation);
    $body->addAggregation($brandTermAggregation);

    return $client->search(['index' => 'products', 'body' => $body->toArray()]);
})->raw();
```

### Conditions ###

Scout supports only 3 conditions: `->where(column, value)` (strict equation), `->whereIn(column, array)` and `->whereNotIn(column, array)`:

```php
Product::search('(title:this OR description:this) AND (title:that OR description:that)')
    ->where('price', 100)
    ->whereIn('type', ['used', 'like new'])
    ->whereNotIn('type', ['new', 'refurbished']);
```

Scout does not support any operators, but you can pass ElasticSearch terms like `RangeQuery` as value to `->where()`:

```php

use ONGR\ElasticsearchDSL\Query\TermLevel\RangeQuery;

Product::search('(title:this OR description:this) AND (title:that OR description:that)')
    ->where('price', new RangeQuery('price', [
        RangeQuery::GTE => 100,
        RangeQuery::LTE => 1000,
    ]);
```

And if you just want to search using RangeQuery without any query_string, you can call the search() method directly and leave the param empty.

```php

use ONGR\ElasticsearchDSL\Query\TermLevel\RangeQuery;

Product::search()
    ->where('price', new RangeQuery('price', [
        RangeQuery::GTE => 100,
    ]);
```

Full list of ElasticSearch terms is in `vendor/handcraftedinthealps/elasticsearch-dsl/src/Query/TermLevel`.

### Search amongst multiple models
You can do it with `MixedSearch` class, just pass indices names separated by commas to the `within` method.
```php
MixedSearch::search('title:Barcelona or to:Barcelona')
    within(implode(',', [
        (new Ticket())->searchableAs(),
        (new Book())->searchableAs(),
    ]))
->get();
```
In this example you will get collection of `Ticket` and `Book` models where ticket's arrival city or
book title is `Barcelona`

### Working with results
Often your response isn't collection of models but aggregations or models with higlights an so on.
In this case you need to implement your own implementation of `HitsIteratorAggregate` and bind it in your service provider

[Here is a case](https://github.com/matchish/laravel-scout-elasticsearch/issues/28)

## :hammer_and_wrench: Local development

To work on the package against a real Laravel app without publishing to Packagist,
point the app at your local clone via a Composer `path` repository. In the
app's `composer.json`, add to `repositories`:

```json
{
    "type": "path",
    "url": "/absolute/path/to/laravel-scout-elasticsearch",
    "options": { "symlink": true }
}
```

Then require it as a `@dev` version:

```
composer require "matchish/laravel-scout-elasticsearch:*@dev"
```

`symlink: true` means edits in the package repo are picked up by the app
immediately — no `composer update` after each change. Drop the option (or set
it to `false`) to have Composer copy files instead, useful when your host does
not follow symlinks (e.g. some Docker bind-mount setups).

To test a branch from the fork without cloning, use a `vcs` repository and a
`dev-<branch>` constraint:

```json
{
    "type": "vcs",
    "url": "https://github.com/workvivo/laravel-scout-elasticsearch"
}
```

```
composer require "matchish/laravel-scout-elasticsearch:dev-<branch-name>"
```

Add `@dev` to the constraint (`dev-<branch>@dev`) if Composer complains about
stability without changing the app's global `minimum-stability`.

## :free: License
Scout ElasticSearch is an open-sourced software licensed under the [MIT license](LICENSE.md).
