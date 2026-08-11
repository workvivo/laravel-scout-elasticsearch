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
Redis extension. Redis Cluster is supported: all per-run coordinator keys use a
`{run-token}` hash tag so the multi-key Lua transitions execute in one hash slot.

The included Docker Compose and GitHub Actions test environments start Redis
alongside MySQL and OpenSearch and run PHPUnit with `REDIS_CLIENT=predis`.
The Redis integration tests run the coordinator Lua transitions against Redis
and assert, using Predis' Redis Cluster slot calculation, that every key touched
by a run maps to the same cluster slot.

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

- **Get the timeout ordering right.** The single most important tuning rule for a
  large `--parallel` import is:

  ```
  p99 chunk duration  <<  SCOUT_QUEUE_TIMEOUT  <  VisibilityTimeout  <  shutdown grace
  ```

  Read left to right:

  - **`p99 chunk duration << SCOUT_QUEUE_TIMEOUT`** — the timeout exists for
    pathological chunks, not normal ones. If your slowest normal chunks are
    anywhere near the timeout, shrink the chunk size instead of raising it.
  - **`SCOUT_QUEUE_TIMEOUT < VisibilityTimeout`** — the job's own alarm must win
    the race against the broker. When the alarm fires first, the worker fails the
    job and **deletes the message**: one clean failure you can see. When the
    broker wins, the still-running job keeps its slot *and* a duplicate becomes
    available — a phantom second delivery whose only symptom is a
    `MaxAttemptsExceeded` on a job that never ran.
  - **`VisibilityTimeout < shutdown grace`** — give the container's SIGTERM grace
    period (e.g. Kubernetes `terminationGracePeriodSeconds`) room to let a
    long chunk finish, so a deploy does not hard-kill workers mid-chunk.

  Two things that are easy to get wrong here:

  - **On SQS the lever is not `retry_after`.** Laravel's SQS connector never reads
    `retry_after`; the only redelivery window is the queue's AWS-side
    `VisibilityTimeout` attribute. `retry_after` applies to the `redis` and
    `database` drivers. Also keep `maxReceiveCount` on the queue's redrive policy
    **greater than** `tries`, or the dead-letter queue swallows messages the
    worker still intended to retry.
  - **`elasticsearch.queue.timeout` is `null` by default.** Unless you set
    `SCOUT_QUEUE_TIMEOUT`, no per-job timeout is written into the payload and the
    real bound is the worker's `--timeout` (default 60s). Compare the redelivery
    window against whichever of the two actually applies.

  You do not have to check this ordering by hand: `--parallel` verifies as much of
  it as PHP can observe before it dispatches anything — see
  [Queue timing pre-flight](#queue-timing-pre-flight).

- **Chunk retries.** By default a chunk that throws fails immediately
  (`tries=1`). For transient failures, let chunks retry with jittered exponential
  backoff — the re-index is idempotent, so a retried chunk just overwrites:

  ```php
  // config/elasticsearch.php → 'import'
  'retry' => [
      'tries' => 3,           // SCOUT_IMPORT_RETRY_TRIES (1 = no retry)
      'backoff_base' => 5,    // SCOUT_IMPORT_RETRY_BACKOFF_BASE, seconds
      'backoff_cap' => 120,   // SCOUT_IMPORT_RETRY_BACKOFF_CAP, seconds
      'retry_until' => 0,     // SCOUT_IMPORT_RETRY_UNTIL (0 = off; see below)
  ],
  ```

  `SCOUT_IMPORT_RETRY_TRIES=3` means each chunk can run three times total: the
  first attempt plus two retries. Keep this bounded; it is for transient
  infrastructure failures, not for retrying bad mappings or permanently failing
  data.

- **Leave `retry_until` at 0.** It is not a per-chunk wall-clock ceiling and it
  does not compose with `tries`:

  - Laravel evaluates it **once, at push time**, and freezes an absolute
    timestamp into the payload. On a large fan-out, every chunk still sitting in
    the queue when that deadline passes fails on its **first** receive, having
    never run.
  - The worker only compares attempts against `tries` when no retry deadline is
    set, so a non-zero `retry_until` **silently disables `tries`** and gives a
    failing chunk unbounded attempts until the deadline.

- **Budget failures instead of rolling back on the first one.** A chunk that dies
  ambiguously — a duplicate delivery, an expired visibility window, an OOM kill —
  is re-dispatched rather than counted as a failure, using a short-lived Redis
  *execution lease* per chunk to tell "another copy is still running" from
  "genuinely failed". Only genuine failures count against the budget, and the same
  chunk failing twice only consumes one slot:

  ```php
  // config/elasticsearch.php → 'import'
  'failure_budget'   => 1,    // SCOUT_IMPORT_FAILURE_BUDGET, genuine failures before rollback
  'chunk_lease_pad'  => 120,  // SCOUT_IMPORT_CHUNK_LEASE_PAD, seconds added to the job timeout
  'redispatch_limit' => 3,    // SCOUT_IMPORT_REDISPATCH_LIMIT, re-dispatches per chunk
  ```

  `failure_budget = 1` is the default and reproduces the historic behaviour: one
  genuine failure fails the run and rolls back. Raise it on a very wide fan-out
  where losing hours of work to a single flaky chunk is worse than a handful of
  under-indexed documents. The lease TTL is the job timeout plus
  `chunk_lease_pad`, so the pad must cover the gap between the alarm firing and
  the process actually stopping. Once a chunk exhausts `redispatch_limit` it is
  treated as a genuine failure, so a pathological chunk cannot loop forever.

- **Paged fan-out and the finalize reaper.** Enqueuing a very large plan can take
  longer than the fan-out job's own visibility window. Plans larger than
  `dispatch_batch` are therefore spread over several self-re-enqueuing hops, each
  renewing the import lock as it goes; smaller plans are dispatched in one hop as
  before. The reaper is an opt-in safety net that finalizes a run whose last chunk
  failed to hand off, and re-dispatches chunks that vanished with no lease live:

  ```php
  // config/elasticsearch.php → 'import'
  'dispatch_batch'  => 1000,  // SCOUT_IMPORT_DISPATCH_BATCH, chunks per fan-out hop
  'reaper_interval' => 0,     // SCOUT_IMPORT_REAPER_INTERVAL, seconds; 0 = disabled
  ```

A failed parallel import never swaps the alias and removes its half-built index,
so re-running is safe — the live index keeps serving until a run fully succeeds.
Once a run fails, remaining chunk jobs skip work before writing, and rollback
deletes the unpromoted index after a short drain delay.
The destructive cleanup, rollback, and final alias swap all re-check the
per-model lock owner first, so a stale run whose lease expired cannot delete or
promote over a newer run's in-progress index.

##### Queue timing pre-flight

Before a `--parallel` run dispatches anything, the command checks the timing
ordering for you and prints what it found. The check is read-only: it takes no
lock, creates no index, enqueues no job, and its only I/O is a single
`GetQueueAttributes` call on SQS.

The invariant it guards is:

```
p99 chunk duration  <<  effective job timeout  <  VisibilityTimeout  <  shutdown grace
```

The **effective job timeout** is `SCOUT_QUEUE_TIMEOUT` when you set it, and
otherwise the queue worker's `--timeout` — 60s unless you changed it. The middle
comparison is the one that actually destroys imports, because the two ways a
long chunk can be stopped are not equivalent:

- **The job's own alarm wins** → the worker fails that job and **deletes the
  message**. One clean failure, attributable to one chunk, visible in
  `failed_jobs`.
- **The broker wins** → the first copy is still running when the message becomes
  visible again, so a second copy is delivered. On SQS the attempt number *is*
  `ApproximateReceiveCount`, so that phantom delivery arrives already over its
  attempt limit and the worker fails it **before the job body ever runs**. The
  symptom is a run collapsing with `MaxAttemptsExceeded` on chunks that never
  executed — while the original copies are still happily indexing.

So the pre-flight's job is to make sure the alarm is the thing that fires first.

**What it can prove.** On an SQS connection it resolves the queue URL the same
way the queue driver does, then reads the queue's real `VisibilityTimeout` and
`RedrivePolicy` (`maxReceiveCount`, dead-letter target). Everything else comes
from config: the resolved connection and driver, `retry_after`, `tries`,
`retry_until`, `failure_budget`, `redispatch_limit`, `dispatch_batch`,
`lock_ttl`, and the chunk size.

**What it cannot see.** Three numbers are simply not obtainable from inside a PHP
process and must be declared if you want them checked:

- the worker's `queue:work --timeout` — it belongs to a different process,
  possibly on a different host;
- the container/supervisor **shutdown grace** (e.g. Kubernetes
  `terminationGracePeriodSeconds`) — how long a worker gets between `SIGTERM`
  and `SIGKILL`;
- the **p99 chunk duration** on your data — measure it with
  [`--profile-samples`](#profiling-slow-chunks), which logs a per-chunk
  fetch/filter/index timing breakdown, then declare the number.

Every value in the report is therefore tagged with its provenance — `probed`,
`declared`, `assumed default` or `unknown` — so you can tell at a glance which
numbers are facts and which are guesses.

```php
// config/elasticsearch.php → 'import'
'preflight' => [
    'enabled' => true,                 // SCOUT_IMPORT_PREFLIGHT, false disables the check
    'worker_timeout' => 0,             // SCOUT_IMPORT_WORKER_TIMEOUT, seconds; 0 = undeclared
    'shutdown_grace' => 0,             // SCOUT_IMPORT_SHUTDOWN_GRACE, seconds; 0 = undeclared
    'expected_chunk_seconds' => 0,     // SCOUT_IMPORT_EXPECTED_CHUNK_SECONDS, p99 from --profile-samples
    'probe_queue' => true,             // SCOUT_IMPORT_PREFLIGHT_PROBE_QUEUE, false skips the SQS read
],
```

**The check only aborts on proven evidence.** Two of its three fatal findings
require a successfully probed `VisibilityTimeout` (the third, below, is provable
from configuration alone). The probed pair fire
when `SCOUT_QUEUE_TIMEOUT` is set and the visibility window is not above it, and
when `SCOUT_QUEUE_TIMEOUT` is unset and the visibility window is not above the
governing worker timeout (your declared `worker_timeout`, or the framework's
documented 60s default). That second case is the classic one — a 30s
`VisibilityTimeout` with no `SCOUT_QUEUE_TIMEOUT` set — and either remedy fixes
it: raise the queue's `VisibilityTimeout`, or set `SCOUT_QUEUE_TIMEOUT` below it.

**The SQS 12-hour ceiling.** `VisibilityTimeout` on SQS cannot exceed **43200s
(12 hours)** — an AWS hard cap, not a soft default. So an effective job timeout
above 43200s makes the middle comparison of the invariant, `job timeout <
VisibilityTimeout`, *unsatisfiable*: no legal value exists on any queue you could
create. That is the one fatal finding that fires without a probe, because a probe
has nothing left to establish — the configuration alone proves it. When it fires
it also **suppresses** the two findings above, whose "raise `VisibilityTimeout` to
N" remedy would be a number AWS refuses. The remedy runs the other way: **lower**
the effective job timeout below the ceiling, then set `VisibilityTimeout` above
the new value.

The trap here is real and easy to hit. `SCOUT_QUEUE_TIMEOUT` is only the
*preferred* source of the job timeout; whenever it is unset, the queue worker's
`--timeout` governs. A worker started as `queue:work sqs --timeout=86400` — a
perfectly ordinary way to keep long jobs alive — therefore puts a 24-hour timeout
in force, which is above the ceiling by design and silently guarantees mid-flight
re-delivery on any queue with a shorter visibility window. Every chunk still
running when the window lapses gets a phantom second delivery that fails before
its body ever runs.

PHP cannot read another process's `--timeout`, so declare it:

```
SCOUT_IMPORT_WORKER_TIMEOUT=86400
```

That is the only way the pre-flight can see the number at all — undeclared, it
assumes the framework's 60s default and cannot warn you about the 24-hour reality.
Declaring it costs nothing and turns an invisible misconfiguration into a fatal
finding with the fix in it.

Everything else is a warning that prints and lets the run continue: a probe that
could not run, a `null` job timeout on an async driver, a non-zero `retry_until`,
`maxReceiveCount` at or below `tries`, a missing dead-letter target, a
`lock_ttl` at or below the job timeout, a declared shutdown grace below it, too
little headroom between the declared chunk duration and the timeout, a
`retry_after` at or below the timeout on a non-SQS async driver, and a correct
but thin (< 30s) visibility margin. A setup that works today keeps working:
nothing that cannot be proven is ever fatal.

**IAM.** The probe needs `sqs:GetQueueAttributes` on the import queue. If the
role lacks it — or the AWS SDK is absent, or the call fails for any other reason
— the failure **degrades to a warning** naming the reason, and the run
continues with the ordering marked unverified. Set
`SCOUT_IMPORT_PREFLIGHT_PROBE_QUEUE=false` to skip the call entirely; you lose
the only fact the check can prove, so it then always warns.

**Dry run.** `--preflight` prints the report and exits without importing
anything, which is the cheap way to inspect a queue's timing before committing to
a multi-hour reindex:

```
php artisan scout:import "App\Models\Product" --parallel --preflight --connection=sqs --queue=reindex
```

```
Queue timing pre-flight for connection [sqs], queue [reindex]:

  Setting                            Value                                       Provenance
  Queue connection                   sqs                                         declared
  Queue driver                       sqs                                         declared
  Queue                              reindex                                     declared
  Queue URL                          https://sqs.eu-west-1.../reindex            probed
  Job timeout (SCOUT_QUEUE_TIMEOUT)  unknown                                     unknown
  Effective job timeout              60s (assumed queue:work --timeout default)  assumed default
  SQS VisibilityTimeout              30s                                         probed
  SQS dead-letter target             none                                        probed
  Import lease TTL                   3600s                                       declared
  Shutdown grace (declared)          unknown                                     unknown
  ...
```

Here the run would abort: `VisibilityTimeout` (30s) is not above the 60s timeout
in force, so every chunk slower than 30s gets a phantom second delivery that
fails before it runs.

**Waiving it.** `--force` — the same flag that lets `--parallel` run inline on the
`sync` driver — downgrades the fatal findings to warnings and continues:

```
php artisan scout:import "App\Models\Product" --parallel --force
```

Use it when you know something the check cannot (for example a worker started
with an explicit `--timeout` you have not declared in config). Set
`SCOUT_IMPORT_PREFLIGHT=false` to turn the check off for good.

#### Profiling slow chunks

`--profile-samples` logs one structured `scout:import chunk profile` line per
profiled chunk with a per-phase breakdown — `fetch_ms`, `filter_ms`, `index_ms`
(split into `serialize_ms` and `bulk_ms`), `payload_kb`, the DB query count of
each phase, and a `lazy_loads` map of every relation that resolved on demand:

```
php artisan scout:import "App\Models\Product" --parallel --profile-samples=all
```

`all` profiles **every** chunk, which is what you want on a table small enough
that the volume is readable. On a large one you ask for a bounded number of
samples instead — `--profile-samples=100` — for the reasons
[below](#sampling-with---profile-samples). It is one flag either way: asking for
a sample target *is* asking to profile, so there is nothing else to turn on.

It works on both paths — the `--parallel` fan-out and the default sequential
import — because the per-chunk decision is baked in when the plan is built, not
read from a global at run time. That includes the sampling: a huge sequential
import has the identical problem, so it gets the identical knob.

Profiling does **not** stop early. The import runs to completion exactly as it
would without the flag; profiling only adds instrumentation. But the log lines
stream **as each chunk finishes**, so the first few arrive within seconds of the
first worker picking up work, and they are usually all you need: a non-empty
`lazy_loads` map naming `App\Models\Product::category` is an N+1, and the fix is
to eager-load that relation in `makeAllSearchableUsing`. You do not have to wait
for the run to finish to read its diagnosis.

##### Sampling with `--profile-samples`

Profiling is not free. Every profiled chunk costs a log line *and* an extra full
serialization pass — the profiled path builds each document once to measure
`serialize_ms` in isolation, and the bulk request then serializes again
internally. On a small table that is invisible. At PERF scale — a plan of
~100,000 chunks — it means ~100,000 log lines and double serialization on every
single chunk, which is unusable for diagnosis: the signal drowns in the volume
and the measurement distorts the thing being measured.

That is why the sample target is the flag rather than a modifier on one: on any
table worth profiling, sampling is what you want, and every-chunk profiling is
the small-table special case (`--profile-samples=all`) rather than the default.

`--profile-samples=N` profiles a bounded number of chunks instead of all of them:

```
php artisan scout:import "App\Models\Product" --parallel --profile-samples=100
```

Two things about that number:

- **It is a target sample count, not a stride.** You are asking for "about 100
  profiled chunks", not "every 100th chunk". This is deliberate, because you
  cannot know the chunk count in advance to compute a stride from: chunk
  boundaries are planned by walking the **MIN/MAX primary-key span**, not by
  counting rows, so a table with gaps, soft deletes or a `shouldBeSearchable()`
  filter yields a chunk count nobody can predict from `count(*)`. The stride is
  derived for you once the plan is materialised. Ask for more samples than there
  are chunks and you simply get all of them.
- **The samples are spread across the whole plan**, not taken from the front. The
  first chunks of a plan are the least representative ones you could pick — lowest
  ids, oldest and often smallest rows, caches still cold — and, more importantly,
  they miss the heavy tail. p99 chunk duration is set by outliers scattered
  through the key space (a burst of rows with huge relations, a stretch of dense
  ids), and a front-loaded sample reports a comfortable p99 that your run will
  then blow straight through.

The accepted values, in full:

| Value                   | Result                                      |
|-------------------------|---------------------------------------------|
| omitted                 | no profiling                                |
| `--profile-samples=100` | profile ~100 chunks, spread across the plan |
| `--profile-samples=all` | profile **every** chunk                     |

`all` is not a separate mode — it resolves to a sample target larger than any
plan, and a target larger than the chunk count already means "all of them" by the
rule in the first bullet above. Same code path, no precedence to remember.

A zero, negative or non-numeric `--profile-samples` is ignored with a warning and
the run continues: a bad diagnostic knob must never block an import. Because the
sample target is the only way to ask for profiling, an ignored value means the
run is simply not profiled — nothing silently escalates to every chunk.

To tell a sampled run from a complete one afterwards, the fan-out log lines
(`scout:import dispatching parallel chunks` and
`scout:import dispatched chunk page`) carry the sampling in effect — `profile` is
`false`, `"all"` or `"1 in N"`, alongside the resolved `profile_samples` target
(`--profile-samples=all` resolves to "more samples than any plan can have", so
read `profile` rather than the very large number next to it) — so a sampled
profile is never mistaken for full coverage when you go back to read the numbers.

##### Findings on your terminal (`--profile-samples` with `--wait`)

Reading log lines is not a diagnosis. On top of them, each profiled chunk turns
its own numbers into **findings** — named, deduplicated conclusions with a remedy
attached — and publishes them into the Redis run record. Add `--wait` and they are
printed on your terminal as they arrive:

```
php artisan scout:import "App\Models\Product" --parallel --wait --profile-samples=200
```

**Terminal output requires `--wait`. There is no way around that.** Under
`--parallel` the profiling happens inside a queue worker, quite possibly on
another host, and the run record that `--wait` polls is the only channel back to
the process holding your terminal. Without `--wait` the command dispatches and
exits immediately, so there is nothing left running to print into. The findings
are still computed, still published to the run record, and the underlying
`scout:import chunk profile` lines are still in your worker logs — you just have
to go and look at them.

Findings are stored **per code**, not per chunk: a run where 200 chunks all
lazy-load the same relation reports one `n_plus_one` with a count of 200 and the
worst example measured, not 200 rows. Each code is announced once, inline, the
first time it appears, together with its fix; the closing roll-up then repeats
every code with how many chunks hit it:

```
Importing [App\Models\Product]
Preparing [App\Models\Product]: Create write index…
Preparing [App\Models\Product]: Planning chunks…
[App\Models\Product] import running: 6/1042 chunks done.
Fetching dominates [App\Models\Product]: 8410.2 ms of 11204.7 ms (75.1%) went to reading rows from the database.
  Index the columns the keyset scan and eager-loads sort/join on, drop with() relations the index does not need, and try --fast-plan; a smaller --chunk will not help while the read is the bottleneck.
N+1 while indexing [App\Models\Product]: App\Models\Product::category was lazy-loaded 500 times inside one chunk (2 relation(s) lazy-loaded).
  Each of those 500 loads is an extra query per model: eager-load the relation in makeAllSearchableUsing() on the model, e.g. `return $query->with([...]);`.
[App\Models\Product] import running: 11/1042 chunks done.
...
[App\Models\Product] import finalizing: 1042/1042 chunks done.
Profiling findings for [App\Models\Product] (3 distinct, diagnostics only — the import status above is unaffected):
  4 chunk(s): A chunk of [App\Models\Product] took 41230.5 ms, 68.7% of its 60 s job timeout — normal variance will push a slower chunk over.
    Raise SCOUT_QUEUE_TIMEOUT above your p99 chunk (and keep the queue VisibilityTimeout above that), or lower --chunk to buy headroom before a chunk trips the timeout.
  188 chunk(s): Fetching dominates [App\Models\Product]: 9902.4 ms of 12016.8 ms (82.4%) went to reading rows from the database.
  200 chunk(s): N+1 while indexing [App\Models\Product]: App\Models\Product::category was lazy-loaded 500 times inside one chunk (2 relation(s) lazy-loaded).

 [OK] App\Models\Product imported: 1041558 documents across 1042 chunks in 24m 11s.
```

The `chunk_near_timeout` above was published between the last poll and the
terminal transition, so it never got an inline line — which is why the roll-up
repeats its remedy (indented) for that code and only that code. A late finding is
never reported without its fix.

What each finding means:

| Finding                 | What it measured                                                       | Remedy                                                                                          |
|-------------------------|------------------------------------------------------------------------|-------------------------------------------------------------------------------------------------|
| `n_plus_one`            | a relation resolved on demand, once per model, inside one chunk         | eager-load it in `makeAllSearchableUsing()` — the highest-value finding of the set               |
| `chunk_exceeds_timeout` | a chunk's wall time reached or passed the job timeout in force          | raise `SCOUT_QUEUE_TIMEOUT` above your p99 chunk (keeping `VisibilityTimeout` above that), or lower `--chunk` |
| `chunk_near_timeout`    | a chunk used at least half its job timeout (never reported with the above) | same remedy, applied before the timeout is actually tripped                                   |
| `filter_queries`        | `shouldBeSearchable()` issued more than one query per chunk             | decide from attributes already loaded, or eager-load what it reads                               |
| `fetch_dominant`        | more than 60% of the chunk went to reading rows                         | index the columns the keyset scan and eager-loads sort/join on, drop unneeded `with()`, try `--fast-plan` |
| `index_dominant`        | more than 60% of the chunk went to indexing (`bulk_ms` is broken out)   | bulk-heavy is the cluster (`refresh_interval`, replicas); the rest is CPU in `toSearchableArray()` |
| `large_payload`         | documents averaged more than 50 KB                                      | trim `toSearchableArray()`, or lower `--chunk` so each bulk request stays small                  |

The two timeout findings compare against the chunk job's own timeout, which is
`SCOUT_QUEUE_TIMEOUT`. With it unset the job carries no alarm of its own and the
governing number lives in the worker process, unreadable from here — so both
findings stay silent rather than guess. A wrong timeout would produce a wrong
diagnosis, and a diagnosis nobody can trust is worse than none. Set
`SCOUT_QUEUE_TIMEOUT` and you get the check for free.

The number of **distinct codes** a run will store is capped:

```php
// config/elasticsearch.php → 'import'
'profile_findings' => 20,  // SCOUT_IMPORT_PROFILE_FINDINGS, distinct codes per run; 0 disables
```

The cap is on codes, not chunks, so a run of 100,000 profiled chunks costs a
handful of Redis rows either way, and a code already being tracked keeps counting
even once the cap is reached — the cap can never hide a finding you are already
being told about. Setting it to `0` disables publication entirely: no writes at
all, and `--wait` stops polling for findings.

Findings are diagnostics and nothing more. They never change the command's exit
code, and a failure anywhere in the publishing or rendering path is swallowed and
logged: a broken diagnostic must cost you a diagnosis, never an import.

##### Probing before you commit to a full import (`--probe`)

Everything above measures an import you are already running. On a table of a few
million rows that is a bad way to learn that one chunk takes 84s, or that
`toSearchableArray()` lazy-loads a relation once per model: by the time the log
lines tell you, you have started a run that will take the rest of the day and
that you now want to kill and restart with a different `--chunk` or a
`makeAllSearchableUsing()` fix.

`--probe` answers those two questions in seconds. It measures a handful of chunks,
prints their timings, the findings and an extrapolation to the whole plan, and
exits without importing anything:

```
php artisan scout:import "App\Models\Product" --probe --probe-samples=5 --probe-workers=12
```

It is **synchronous and in-process**. No queue, no workers, no `--wait`, no run
record — the command that measures the chunks is the command holding your
terminal, so the numbers are printed directly and there is no Redis state to poll
or clean up afterwards. It works with or without `--parallel`: a probe dispatches
nothing, so under `--probe` the `--parallel` gates (Redis coordination, the async
connection requirement, the queue timing pre-flight) are all skipped, and
`--probe` neither requires `--parallel` nor warns about its absence. Probing a
sequential import is just as useful.

`--chunk` and `--fast-plan` are honoured, because they are applied to the source
before the probe runs — so you are measuring the shape the real import would use,
and comparing two `--chunk` values is two probes.

| Flag                | Default | Meaning                                                    |
|---------------------|---------|------------------------------------------------------------|
| `--probe`           | off     | measure a sample of chunks, report, exit without importing |
| `--probe-samples=N` | 3       | how many chunks to measure, spread across the plan         |
| `--probe-workers=N` | 1       | how many workers to divide the time estimate across        |

The samples are **spread across the plan** — the same stride math
`--profile-samples` uses — and never taken from the front, for the reason
[described above](#sampling-with---profile-samples): the first chunks have the
lowest ids, the oldest and often smallest rows and cold caches, and they miss the
heavy tail that actually sets p99. A front-loaded sample of three chunks would
report a comfortable estimate that the run then blows straight through. If the
plan has fewer chunks than you asked for, all of them are measured.

A bad `--probe-samples` or `--probe-workers` — zero, negative, non-numeric —
warns and falls back to the default. It never aborts.

```
Probing [App\Models\Product]: measuring 5 of 8360 chunk(s) (~500 rows each) into throwaway index [products_probe_1754899200_k3f9qa]. Nothing is imported — the live index is never written to and its alias is never touched.
+-------+---------+---------+----------+-----------+----------+----------+----------------------------+------------+
| Chunk | Fetched | Indexed | Fetch ms | Filter ms | Index ms | Total ms | Queries fetch/filter/index | Payload KB |
+-------+---------+---------+----------+-----------+----------+----------+----------------------------+------------+
| 0     | 500     | 500     | 48211.4  | 12.7      | 13016.7  | 61240.8  | 502/0/0                    | 4218.2     |
| 1672  | 500     | 500     | 71204.6  | 11.9      | 12885.9  | 84102.4  | 502/0/0                    | 4402.8     |
| 3344  | 500     | 500     | 69880.1  | 12.4      | 13188.6  | 83081.1  | 502/0/0                    | 4370.5     |
| 5016  | 500     | 500     | 78402.9  | 12.1      | 16922.6  | 95337.6  | 502/0/0                    | 5011.4     |
| 6688  | 500     | 500     | 99771.2  | 13.0      | 19119.9  | 118904.1 | 502/0/0                    | 6294.7     |
+-------+---------+---------+----------+-----------+----------+----------+----------------------------+------------+
Measured 5 chunk(s) of [App\Models\Product]: min 61240.8 ms, median 84102.4 ms, mean 88533.2 ms, max 118904.1 ms (2500 rows fetched, 2500 indexed).
ESTIMATE from 5 sampled chunk(s), not a measurement: 8360 chunks x 88.533 s mean = 205h 35m on one worker, 17h 7m across 12 worker(s). Chunk cost is never uniform, so a heavy tail pushes this up.
Probe findings for [App\Models\Product] (3 distinct — diagnostics only, --probe exits successfully either way):
  5 of 5 sampled chunk(s): N+1 while indexing [App\Models\Product]: App\Models\Product::category was lazy-loaded 500 times inside one chunk (2 relation(s) lazy-loaded).
    Each of those 500 loads is an extra query per model: eager-load the relation in makeAllSearchableUsing() on the model, e.g. `return $query->with([...]);`.
  5 of 5 sampled chunk(s): Fetching dominates [App\Models\Product]: 99771.2 ms of 118904.1 ms (83.9%) went to reading rows from the database.
    Index the columns the keyset scan and eager-loads sort/join on, drop with() relations the index does not need, and try --fast-plan; a smaller --chunk will not help while the read is the bottleneck.
  5 of 5 sampled chunk(s): A chunk of [App\Models\Product] took 118904.1 ms, at or past its 60 s job timeout (198.2% of the budget) — that chunk is being killed mid-flight.
    Raise SCOUT_QUEUE_TIMEOUT above your p99 chunk (and keep the queue VisibilityTimeout above that), or lower --chunk until a chunk finishes well inside the timeout.
Probe index [products_probe_1754899200_k3f9qa] deleted. Nothing else was created and no import ran.
```

That is the whole diagnosis, in the time five chunks take: the `502` queries per
chunk against 500 rows is the N+1, and the fetch phase is where the time goes.
The findings are the **same** ones a `--profile-samples` import with `--wait`
prints, rendered from the same `profile_finding_<code>` sentences and remedies —
a diagnosis reads
identically whether a worker measured it mid-import or a probe measured it here.
As during an import, findings are diagnostics only: `--probe` exits SUCCESS
whenever the probe ran, however alarming what it found, and FAILURE only when it
could not run at all (an unplannable source, or a probe index that could not be
created).

**The extrapolation is an estimate from a sample, not a measurement**, and the
output says so on its own line. It is the mean measured chunk multiplied by the
total chunk count, reported serially and again divided by `--probe-workers`. Two
things it cannot know: chunk cost is not uniform, so a heavy tail pushes the real
number up; and N workers rarely give N times the throughput, because they contend
for the same database and the same cluster. Read it as an order of magnitude —
"hours, not minutes" — not as an ETA.

If an import for the same model is already running, the probe says so as a
warning above the table and measures anyway: it takes no lock, so it can neither
block a real import nor be blocked by one, but a concurrent import competes for
the same rows and the same cluster and skews every number below it.

**Safety — why the probe writes where it writes.** A probe has to index
documents somewhere; the one place it must never index them is the live index.
Bulk requests carry `searchableAs()` — the **alias** — as their `_index`, so the
probe redirects them to a **standalone index that is never added to the alias**.
The live index and its alias are untouched, and your application's concurrent
`searchable()` writes keep going to production exactly as before.

The naive alternative would be to create a write index the way a real import
does. That would be actively destructive here: a new write index becomes the
alias's `is_write_index`, which **diverts the application's own writes** into it —
and the probe then deletes that index, so those writes would be permanently lost.
A real import is safe from this only because it ends by promoting the index it
wrote; a probe never promotes anything, so it must never be in the alias in the
first place.

The probe index is created with the same settings and mappings a real write
index gets (so the measurements are representative), is named
`<searchableAs>_probe_<timestamp>_<random>` — the `<searchableAs>_` prefix is what
lets the same guarded delete a rolled-back import uses accept it, and it refuses
`''`, `_all`, wildcards and anything outside that prefix — and is **always**
deleted, in a `finally`, even when a sampled chunk threw. A chunk that fails is
recorded as one failed sample and the remaining samples are still measured and
reported.

Two lines name the index for you: the header when it is created, and
`Probe index [...] deleted.` when it is gone. If a probe is interrupted (`^C`) or
the delete itself fails, the closing line is missing — that absence is the signal
that an index may remain, and the name you need is in the output above. A failed
delete additionally prints a warning with the `DELETE /<index>` to run. Nothing
reads a leftover probe index and it is not in the alias; it only occupies disk.

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
