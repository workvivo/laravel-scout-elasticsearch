<?php

declare(strict_types=1);

namespace Matchish\ScoutElasticSearch\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Matchish\ScoutElasticSearch\ElasticSearch\Config\Config;
use Matchish\ScoutElasticSearch\ElasticSearch\Index;
use Matchish\ScoutElasticSearch\ImportLock;
use Matchish\ScoutElasticSearch\Jobs\DispatchPullBatch;
use Matchish\ScoutElasticSearch\Jobs\Import;
use Matchish\ScoutElasticSearch\Jobs\QueueableJob;
use Matchish\ScoutElasticSearch\Jobs\StageJob;
use Matchish\ScoutElasticSearch\Jobs\Stages\CleanUp;
use Matchish\ScoutElasticSearch\Jobs\Stages\CreateWriteIndex;
use Matchish\ScoutElasticSearch\Searchable\ImportSource;
use Matchish\ScoutElasticSearch\Searchable\ImportSourceFactory;
use Matchish\ScoutElasticSearch\Searchable\SearchableListFactory;

final class ImportCommand extends Command
{
    const DEFAULT_LOCK_TTL = 3600;

    /**
     * @inheritdoc
     */
    protected $signature = 'scout:import {searchable?* : The name of the searchable}
        {--parallel : Import chunks in parallel across queue workers}
        {--queue= : Queue the import jobs run on (defaults to scout.queue, then the app default queue)}
        {--connection= : Queue connection the import jobs run on (defaults to scout.queue, then the app default queue)}
        {--force : Run --parallel even when the resolved queue connection is synchronous}';
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

        $this->searchableList((array) $this->argument('searchable'))
        ->each(function ($searchable) {
            $this->import($searchable);
        });

        return self::SUCCESS;
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

    private function import(string $searchable): void
    {
        $sourceFactory = app(ImportSourceFactory::class);
        $source = $sourceFactory::from($searchable);

        $ttl = (int) config('elasticsearch.import.lock_ttl', self::DEFAULT_LOCK_TTL);
        $owner = (new ImportLock($source->searchableAs(), $ttl))->acquire();

        // Another import for this exact model is already running. Skip this one
        // rather than racing its alias swap. Different models are unaffected —
        // each holds its own key.
        if ($owner === null) {
            $this->warn(trans('scout::import.already_running', ['searchable' => $searchable]));

            return;
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
                // use the app's default (e.g. SQS) queue on their own.
                $this->dispatchParallel($source, $this->resolvedConnection(), $this->resolvedQueue(), $owner, $ttl);
                $doneKey = 'scout::import.done.queue';
            } else {
                // Sequential path is unchanged: scout.queue decides queued vs
                // inline, and per-model overrides are honoured. null falls back
                // to the queue driver's default.
                $connection = $this->option('connection') ?: $source->syncWithSearchUsing();
                $queue = $this->option('queue') ?: $source->syncWithSearchUsingQueue();
                $this->dispatchSequential($source, $connection, $queue, $owner, $ttl);
                $doneKey = config('scout.queue') ? 'scout::import.done.queue' : 'scout::import.done';
            }

            $handedOff = true;

            $doneMessage = trans($doneKey, ['searchable' => $searchable]);
            $this->output->success($doneMessage);
        } finally {
            if (! $handedOff) {
                ImportLock::release($source->searchableAs(), $owner);
            }
        }
    }

    private function dispatchSequential(ImportSource $source, ?string $connection, ?string $queue, string $owner, int $ttl): void
    {
        $job = new Import($source, $owner, $ttl);
        $job->timeout = Config::queueTimeout();

        if (config('scout.queue')) {
            $job = (new QueueableJob())->chain([$job]);
            $job->timeout = Config::queueTimeout();
        }

        $bar = (new ProgressBarFactory($this->output))->create();
        $job->withProgressReport($bar);

        dispatch($job)->allOnQueue($queue)->allOnConnection($connection);
    }

    private function dispatchParallel(ImportSource $source, ?string $connection, ?string $queue, string $owner, int $ttl): void
    {
        $index = Index::fromSource($source);
        $timeout = Config::queueTimeout();

        $stages = [
            new StageJob(new CleanUp($source)),
            new StageJob(new CreateWriteIndex($source, $index)),
            new DispatchPullBatch($source, $index, $connection, $queue, $owner, $ttl),
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
