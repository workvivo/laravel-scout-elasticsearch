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
        {--queue= : Queue the import jobs run on (defaults to the scout.queue config)}
        {--connection= : Queue connection the import jobs run on (defaults to the scout.queue config)}';
    /**
     * @inheritdoc
     */
    protected $description = 'Create new index and import all searchable into the one';

    /**
     * @inheritdoc
     */
    public function handle(): int
    {
        if ($this->option('parallel') && ! config('scout.queue')) {
            $this->error(trans('scout::import.parallel_requires_queue'));

            return self::FAILURE;
        }

        $this->searchableList((array) $this->argument('searchable'))
        ->each(function ($searchable) {
            $this->import($searchable);
        });

        return self::SUCCESS;
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

            // null on either falls back to the queue driver's default, matching
            // the original dispatch behaviour.
            $connection = $this->option('connection') ?: $source->syncWithSearchUsing();
            $queue = $this->option('queue') ?: $source->syncWithSearchUsingQueue();

            if ($this->option('parallel')) {
                $this->dispatchParallel($source, $connection, $queue, $owner, $ttl);
                $doneKey = 'scout::import.done.queue';
            } else {
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
