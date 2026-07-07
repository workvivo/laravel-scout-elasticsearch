<?php

namespace Matchish\ScoutElasticSearch\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Matchish\ScoutElasticSearch\Jobs\Stages\StageInterface;
use OpenSearch\Client;

/**
 * Runs a single import stage as its own queued job so the pipeline can fan out
 * across workers (the `--parallel` path in
 * {@see \Matchish\ScoutElasticSearch\Console\Commands\ImportCommand}). Sequential
 * imports still run every stage in-process inside the Import job.
 *
 * @internal
 */
final class StageJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @var StageInterface
     */
    private $stage;

    public ?int $timeout = null;

    public function __construct(StageInterface $stage)
    {
        $this->stage = $stage;
    }

    public function handle(Client $elasticsearch): void
    {
        $this->stage->handle($elasticsearch);
    }
}
