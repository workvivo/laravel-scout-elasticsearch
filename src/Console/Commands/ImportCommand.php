<?php

declare(strict_types=1);

namespace Matchish\ScoutElasticSearch\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Matchish\ScoutElasticSearch\ElasticSearch\Config\Config;
use Matchish\ScoutElasticSearch\ElasticSearch\Index;
use Matchish\ScoutElasticSearch\ImportLock;
use Matchish\ScoutElasticSearch\Jobs\DispatchPullBatch;
use Matchish\ScoutElasticSearch\Jobs\Import;
use Matchish\ScoutElasticSearch\Jobs\QueueableJob;
use Matchish\ScoutElasticSearch\Jobs\StageJob;
use Matchish\ScoutElasticSearch\Jobs\Stages\CleanUp;
use Matchish\ScoutElasticSearch\Jobs\Stages\CreateWriteIndex;
use Matchish\ScoutElasticSearch\Searchable\DefaultImportSource;
use Matchish\ScoutElasticSearch\Searchable\ImportSource;
use Matchish\ScoutElasticSearch\Searchable\ImportSourceFactory;
use Matchish\ScoutElasticSearch\Searchable\SearchableListFactory;
use OpenSearch\Client;

final class ImportCommand extends Command
{
    const DEFAULT_LOCK_TTL = 3600;

    /**
     * Seconds --wait will poll for the batch to be created (prepare stages +
     * DispatchPullBatch) before giving up and leaving the work queued.
     */
    const DEFAULT_WAIT_TIMEOUT = 120;

    /**
     * @inheritdoc
     */
    protected $signature = 'scout:import {searchable?* : The name of the searchable}
        {--parallel : Import chunks in parallel across queue workers}
        {--queue= : Queue the import jobs run on (defaults to scout.queue, then the app default queue)}
        {--connection= : Queue connection the import jobs run on (defaults to scout.queue, then the app default queue)}
        {--force : Run --parallel even when the resolved queue connection is synchronous}
        {--wait : With --parallel, block and show a chunk progress bar until the import finishes}
        {--chunk= : Rows per chunk for this run (overrides the scout.chunk.searchable config)}
        {--fast-plan : Plan chunk boundaries from bare keys, skipping the eager-load join/filters (faster on joined models)}
        {--profile : Log a per-chunk fetch/filter/index timing breakdown to help diagnose slow chunks}';
    /**
     * @inheritdoc
     */
    protected $description = 'Create new index and import all searchable into the one';

    /**
     * @inheritdoc
     */
    public function handle(): int
    {
        // --parallel fans chunks out onto a queue, so it only does real work on
        // an asynchronous connection. It does NOT require scout.queue (that flag
        // only governs per-model index syncs): the connection is resolved from
        // --connection, then scout.queue, then the app's default queue. Bail
        // early if that resolves to the sync driver, unless --force is given.
        if ($this->option('parallel') && ! $this->option('force')) {
            $connection = $this->resolvedConnection();

            if ($this->isSyncConnection($connection)) {
                $this->error(trans('scout::import.parallel_requires_async_queue', [
                    'connection' => $connection ?? 'sync',
                ]));

                return self::FAILURE;
            }
        }

        if ($this->option('chunk') !== null && (int) $this->option('chunk') < 1) {
            $this->error(trans('scout::import.invalid_chunk'));

            return self::FAILURE;
        }

        if ($this->option('wait') && ! $this->option('parallel')) {
            $this->warn(trans('scout::import.wait_needs_parallel'));
        }

        $failed = false;

        $this->searchableList((array) $this->argument('searchable'))
        ->each(function ($searchable) use (&$failed) {
            if ($this->import($searchable) === self::FAILURE) {
                $failed = true;
            }
        });

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The queue connection --parallel work is dispatched on: the explicit
     * --connection option, else the scout.queue connection when configured,
     * else the application's default queue connection.
     */
    private function resolvedConnection(): ?string
    {
        return $this->option('connection')
            ?: config('scout.queue.connection')
            ?: config('queue.default');
    }

    /**
     * The queue name --parallel work is dispatched on: the explicit --queue
     * option, else the scout.queue queue when configured (null = the
     * connection's default queue).
     */
    private function resolvedQueue(): ?string
    {
        return $this->option('queue') ?: config('scout.queue.queue');
    }

    private function isSyncConnection(?string $connection): bool
    {
        if ($connection === null) {
            return true;
        }

        return config("queue.connections.{$connection}.driver") === 'sync';
    }

    private function searchableList(array $argument): Collection
    {
        return collect($argument)->whenEmpty(function () {
            $factory = new SearchableListFactory(app()->getNamespace(), app()->path());

            return $factory->make();
        });
    }

    private function import(string $searchable): int
    {
        $sourceFactory = app(ImportSourceFactory::class);
        $source = $sourceFactory::from($searchable);

        // Per-run tuning. Only the built-in source supports it; a custom
        // ImportSource keeps its own chunking/planning.
        if ($source instanceof DefaultImportSource) {
            $chunk = $this->option('chunk');
            if ($chunk !== null) {
                $source = $source->withChunkSize((int) $chunk);
            }
            if ($this->option('fast-plan')) {
                $source = $source->withFastPlan();
            }
        }

        $ttl = (int) config('elasticsearch.import.lock_ttl', self::DEFAULT_LOCK_TTL);
        $owner = (new ImportLock($source->searchableAs(), $ttl))->acquire();

        // Another import for this exact model is already running. Skip this one
        // rather than racing its alias swap. Different models are unaffected —
        // each holds its own key. A skip is not a failure.
        if ($owner === null) {
            $this->warn(trans('scout::import.already_running', [
                'searchable' => $searchable,
                'key' => ImportLock::keyFor($source->searchableAs()),
                'ttl' => $ttl,
            ]));

            return self::SUCCESS;
        }

        // Once dispatch hands the lock to the pipeline, the pipeline owns
        // releasing it (Import's finally / the batch's finally callback). Only
        // release here if dispatch itself failed before that handoff.
        $handedOff = false;

        try {
            $startMessage = trans('scout::import.start', ['searchable' => "<comment>$searchable</comment>"]);
            $this->line($startMessage);

            if ($this->option('parallel')) {
                // Resolve independently of scout.queue so parallel imports can
                // use the app's default (e.g. SQS) queue on their own. When
                // --wait is set, a token lets us find and poll the batch.
                $connection = $this->resolvedConnection();
                $queue = $this->resolvedQueue();
                $token = $this->option('wait') ? (string) Str::uuid() : null;
                $this->dispatchParallel($source, $connection, $queue, $owner, $ttl, $token, (bool) $this->option('profile'));
                $handedOff = true;

                if ($token !== null) {
                    return $this->waitForBatch($searchable, $token, $connection, $queue);
                }

                $this->output->success(trans('scout::import.done.queue', ['searchable' => $searchable]));

                return self::SUCCESS;
            }

            // Sequential path: scout.queue decides queued vs inline, and
            // per-model overrides are honoured. null falls back to the queue
            // driver's default.
            $connection = $this->option('connection') ?: $source->syncWithSearchUsing();
            $queue = $this->option('queue') ?: $source->syncWithSearchUsingQueue();

            $start = microtime(true);
            $this->dispatchSequential($source, $connection, $queue, $owner, $ttl, (bool) $this->option('profile'));
            $handedOff = true;

            // Queued sequential is fire-and-forget (runs on a worker), so it can
            // only report that the job was dispatched. An inline import finished
            // in-process, so report the same summary as --parallel --wait.
            if (config('scout.queue')) {
                $this->output->success(trans('scout::import.done.queue', ['searchable' => $searchable]));
            } else {
                $this->output->success(trans('scout::import.done_summary', [
                    'searchable' => $searchable,
                    'indexed' => $this->countIndexedDocuments($source->searchableAs()) ?? '?',
                    'elapsed' => $this->humanElapsed(microtime(true) - $start),
                ]));
            }

            return self::SUCCESS;
        } finally {
            if (! $handedOff) {
                ImportLock::release($source->searchableAs(), $owner);
            }
        }
    }

    /**
     * Block until a --parallel batch finishes, rendering a progress bar over its
     * chunks, then print a summary. Returns FAILURE when any chunk failed.
     */
    private function waitForBatch(string $searchable, string $token, ?string $connection, ?string $queue): int
    {
        $key = DispatchPullBatch::progressKey($token);
        $prepareKey = DispatchPullBatch::preparingKey($token);
        $start = microtime(true);
        $appearTimeout = (int) config('elasticsearch.import.wait_timeout', self::DEFAULT_WAIT_TIMEOUT);

        // Wait for the worker to run the prepare stages + DispatchPullBatch and
        // publish the batch id. A worker first has to pick up the chain, clean
        // up + create the new index, and scan the primary keys to plan the
        // chunks before the batch exists.
        //
        // The timeout is applied to the time since the last *observed activity*,
        // not since dispatch: each prepare stage publishes a heartbeat as it
        // begins (see StageJob::withHeartbeat + DispatchPullBatch), so a chain
        // that is actively progressing keeps resetting the window and never
        // false-times-out just because a busy queue took a while to schedule
        // each hop. Two distinct give-up cases: never picked up (no heartbeat
        // ever — likely no worker on this queue, or a very deep backlog) vs.
        // picked up then went silent (a slow stage, or a crashed/OOM worker).
        $record = null;
        $lastActivity = $start;
        $lastSeq = null;
        $lastStage = null;
        $pickedUp = false;

        while (($record = Cache::get($key)) === null) {
            $beat = Cache::get($prepareKey);
            if ($beat !== null && ($beat['seq'] ?? null) !== $lastSeq) {
                $lastSeq = $beat['seq'] ?? null;
                $lastStage = $beat['stage'] ?? null;
                $lastActivity = microtime(true);
                $pickedUp = true;
                $this->comment(trans('scout::import.wait_preparing', [
                    'searchable' => $searchable,
                    'stage' => $lastStage ?? '…',
                ]));
            }

            if (microtime(true) - $lastActivity > $appearTimeout) {
                $silence = (int) round(microtime(true) - $lastActivity);
                if ($pickedUp) {
                    $this->warn(trans('scout::import.wait_prepare_stalled', [
                        'searchable' => $searchable,
                        'stage' => $lastStage ?? '?',
                        'elapsed' => $silence,
                    ]));
                    $this->line(trans('scout::import.wait_prepare_stalled_hint'));
                } else {
                    $this->warn(trans('scout::import.wait_no_pickup', [
                        'searchable' => $searchable,
                        'connection' => $connection ?? '(driver default)',
                        'queue' => $queue ?? '(driver default)',
                        'elapsed' => $silence,
                        'timeout' => $appearTimeout,
                    ]));
                    $this->line(trans('scout::import.wait_no_pickup_hint'));
                }

                return self::SUCCESS;
            }

            usleep(500000);
        }

        if (! empty($record['empty'])) {
            $this->output->success(trans('scout::import.wait_summary_empty', ['searchable' => $searchable]));

            return self::SUCCESS;
        }

        $batchId = $record['batchId'];
        $indexName = $record['index'] ?? null;

        $batch = Bus::findBatch($batchId);
        $bar = (new ProgressBarFactory($this->output))
            ->create($batch !== null ? $batch->totalJobs : (int) ($record['total'] ?? 0));
        $bar->setMessage(trans('scout::import.indexing', ['searchable' => $searchable]));
        $bar->start();

        while ($batch !== null && ! $batch->finished()) {
            $bar->setProgress($batch->processedJobs());
            usleep(500000);
            $batch = Bus::findBatch($batchId);
        }

        if ($batch !== null) {
            $bar->setProgress($batch->processedJobs());
        }
        $bar->finish();
        $this->newLine();

        $elapsed = $this->humanElapsed(microtime(true) - $start);
        $failed = $batch !== null ? $batch->failedJobs : 0;
        $total = $batch !== null ? $batch->totalJobs : (int) ($record['total'] ?? 0);

        if ($failed > 0 || ($batch !== null && $batch->cancelled())) {
            $this->error(trans('scout::import.wait_failed', [
                'searchable' => $searchable,
                'failed' => $failed,
                'chunks' => $total,
                'elapsed' => $elapsed,
            ]));

            return self::FAILURE;
        }

        $indexed = $indexName !== null ? $this->countIndexedDocuments($indexName) : null;
        $this->output->success(trans('scout::import.wait_summary', [
            'searchable' => $searchable,
            'indexed' => $indexed ?? '?',
            'chunks' => $total,
            'elapsed' => $elapsed,
        ]));

        return self::SUCCESS;
    }

    /**
     * Count documents in the freshly built index by its concrete name, so the
     * total is independent of the alias swap timing. Best effort — returns null
     * if the index is unavailable.
     */
    private function countIndexedDocuments(string $index): ?int
    {
        try {
            $client = app(Client::class);
            $client->indices()->refresh(['index' => $index]);

            return (int) ($client->count(['index' => $index])['count'] ?? 0);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function humanElapsed(float $seconds): string
    {
        $seconds = (int) round($seconds);

        if ($seconds < 60) {
            return $seconds.'s';
        }

        $minutes = intdiv($seconds, 60);
        if ($minutes < 60) {
            return $minutes.'m '.($seconds % 60).'s';
        }

        return intdiv($minutes, 60).'h '.($minutes % 60).'m';
    }

    private function dispatchSequential(ImportSource $source, ?string $connection, ?string $queue, string $owner, int $ttl, bool $profile = false): void
    {
        $job = new Import($source, $owner, $ttl, $profile);
        $job->timeout = Config::queueTimeout();

        if (config('scout.queue')) {
            $job = (new QueueableJob())->chain([$job]);
            $job->timeout = Config::queueTimeout();
        }

        $bar = (new ProgressBarFactory($this->output))->create();
        $job->withProgressReport($bar);

        dispatch($job)->allOnQueue($queue)->allOnConnection($connection);
    }

    private function dispatchParallel(ImportSource $source, ?string $connection, ?string $queue, string $owner, int $ttl, ?string $progressToken = null, bool $profile = false): void
    {
        $index = Index::fromSource($source);
        $timeout = Config::queueTimeout();

        // Pass the lock owner so CleanUp refuses to delete a write index once
        // our lease has lapsed, and renew the lease as each prepare stage begins
        // so the (otherwise unrenewed) clean-up + create-index + planning window
        // cannot expire and admit a second, overlapping run of the same model.
        $cleanUp = (new StageJob(new CleanUp($source, $owner)))
            ->withLockRenew($source->searchableAs(), $owner, $ttl);
        $createIndex = (new StageJob(new CreateWriteIndex($source, $index)))
            ->withLockRenew($source->searchableAs(), $owner, $ttl);

        // With --wait, the prepare stages publish a heartbeat as each begins so
        // waitForBatch can see the chain has been picked up and is progressing
        // (clean up = 1, create index = 2; DispatchPullBatch publishes 3 for
        // planning). Without --wait nobody polls, so skip the writes.
        if ($progressToken !== null) {
            $prepareKey = DispatchPullBatch::preparingKey($progressToken);
            $cleanUp->withHeartbeat($prepareKey, 1, $ttl);
            $createIndex->withHeartbeat($prepareKey, 2, $ttl);
        }

        $stages = [
            $cleanUp,
            $createIndex,
            new DispatchPullBatch($source, $index, $connection, $queue, $owner, $ttl, $progressToken, $profile),
        ];

        foreach ($stages as $stage) {
            $stage->timeout = $timeout;
        }

        Bus::chain($stages)
            ->onConnection($connection)
            ->onQueue($queue)
            ->dispatch();
    }
}
