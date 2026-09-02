<?php

namespace Matchish\ScoutElasticSearch\Jobs;

use OpenSearch\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Collection;
use Matchish\ScoutElasticSearch\ImportLock;
use Matchish\ScoutElasticSearch\Jobs\Stages\StageInterface;
use Matchish\ScoutElasticSearch\ProgressReportable;
use Matchish\ScoutElasticSearch\Searchable\ImportSource;

/**
 * @internal
 */
final class Import
{
    use Queueable;
    use ProgressReportable;

    /**
     * @var ImportSource
     */
    private $source;

    /**
     * Owner token of the per-model import lock held by the dispatching command,
     * or null when the command runs without the duplicate-run guard. Released
     * when this job finishes so the lock survives across the queued chain but
     * never outlives the import.
     *
     * @var string|null
     */
    private $lockOwner;

    /**
     * TTL, in seconds, used to renew the import lease as stages complete.
     *
     * @var int
     */
    private $lockTtl;

    /**
     * When true, each chunk logs a fetch/filter/index timing breakdown (see
     * {@see \Matchish\ScoutElasticSearch\Jobs\Stages\PullFromSource}).
     *
     * @var bool
     */
    private $profile;

    /**
     * Target number of chunks to profile, spread across the whole plan, instead
     * of profiling every one. Null (the default) keeps $profile meaning "every
     * chunk".
     *
     * @var int|null
     */
    private $profileSamples;

    public ?int $timeout = null;

    /**
     * @param  ImportSource  $source
     * @param  string|null  $lockOwner
     * @param  int  $lockTtl
     * @param  bool  $profile
     * @param  int|null  $profileSamples
     */
    public function __construct(ImportSource $source, ?string $lockOwner = null, int $lockTtl = 3600, bool $profile = false, ?int $profileSamples = null)
    {
        $this->source = $source;
        $this->lockOwner = $lockOwner;
        $this->lockTtl = $lockTtl;
        $this->profile = $profile;
        $this->profileSamples = $profileSamples;
    }

    /**
     * @param  Client  $elasticsearch
     */
    public function handle(Client $elasticsearch): void
    {
        try {
            // Renew once up front so the acquire->first-stage window (a queued
            // sequential import waiting for a worker) cannot let the lease lapse
            // before the loop below starts renewing per stage.
            if ($this->lockOwner !== null) {
                ImportLock::renew($this->source->searchableAs(), $this->lockOwner, $this->lockTtl);
            }
            $stages = $this->stages();
            $estimate = $stages->sum->estimate();
            $this->progressBar()->setMaxSteps($estimate);
            $stages->each(function ($stage) use ($elasticsearch) {
                /** @var StageInterface $stage */
                $this->progressBar()->setMessage($stage->title());
                $stage->handle($elasticsearch);
                $this->progressBar()->advance($stage->estimate());
                // Renew the lease after each stage so a long import (large table,
                // many chunks) never expires mid-run.
                if ($this->lockOwner !== null) {
                    ImportLock::renew($this->source->searchableAs(), $this->lockOwner, $this->lockTtl);
                }
            });
        } finally {
            if ($this->lockOwner !== null) {
                ImportLock::release($this->source->searchableAs(), $this->lockOwner);
            }
        }
    }

    /**
     * @return Collection<int, StageInterface>
     */
    private function stages(): Collection
    {
        return ImportStages::fromSource($this->source, $this->profile, $this->lockOwner, $this->profileSamples);
    }
}
