<?php

namespace Matchish\ScoutElasticSearch\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
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

    /**
     * Prepare-phase heartbeat. When set (only on the --parallel prepare stages,
     * never on the fanned-out chunk jobs), this stage publishes a marker to the
     * cache the instant a worker starts running it, so a waiting command can
     * tell the chain has been *picked up and is progressing* rather than still
     * sitting unclaimed on the queue. Null = no heartbeat.
     *
     * @var string|null
     */
    public ?string $heartbeatKey = null;
    /**
     * Monotonic step number so the waiter can tell one stage's heartbeat from
     * the next (clean up = 1, create index = 2, planning = 3) and reset its
     * patience window every time the value advances.
     *
     * @var int|null
     */
    public ?int $heartbeatSeq = null;
    /**
     * @var int
     */
    public int $heartbeatTtl = 3600;

    public function __construct(StageInterface $stage)
    {
        $this->stage = $stage;
    }

    /**
     * Make this stage publish a prepare-phase heartbeat when it begins, so
     * {@see \Matchish\ScoutElasticSearch\Console\Commands\ImportCommand::waitForBatch}
     * can distinguish "no worker has picked up the chain" from "a worker is
     * actively preparing".
     */
    public function withHeartbeat(string $key, int $seq, int $ttl): self
    {
        $this->heartbeatKey = $key;
        $this->heartbeatSeq = $seq;
        $this->heartbeatTtl = $ttl;

        return $this;
    }

    public function handle(Client $elasticsearch): void
    {
        if ($this->heartbeatKey !== null) {
            Cache::put($this->heartbeatKey, [
                'seq' => $this->heartbeatSeq,
                'stage' => $this->stage->title(),
            ], $this->heartbeatTtl);
        }

        $this->stage->handle($elasticsearch);
    }
}
