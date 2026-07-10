<?php

declare(strict_types=1);

namespace Matchish\ScoutElasticSearch\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Matchish\ScoutElasticSearch\ElasticSearch\Config\Config;
use Matchish\ScoutElasticSearch\ElasticSearch\Index;
use Matchish\ScoutElasticSearch\Import\ImportRunStore;
use Matchish\ScoutElasticSearch\ImportLock;
use Matchish\ScoutElasticSearch\Jobs\Middleware\SkipIfImportRun;
use Matchish\ScoutElasticSearch\Jobs\Stages\PullFromSource;
use Matchish\ScoutElasticSearch\Searchable\ImportSource;
use OpenSearch\Client;
use Throwable;

final class PullChunkJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private ImportSource $source;
    private PullFromSource $stage;
    private Index $index;
    private string $token;
    private int $chunkId;
    private ?string $lockOwner;
    private int $lockTtl;
    private ?string $connectionName;
    private ?string $queueName;

    public int $tries = 1;
    public ?int $timeout = null;
    public ?int $backoffBase = null;
    public ?int $backoffCap = null;
    public ?int $retryUntilSeconds = null;

    public function __construct(
        ImportSource $source,
        PullFromSource $stage,
        Index $index,
        string $token,
        int $chunkId,
        ?string $lockOwner,
        int $lockTtl,
        ?string $connection,
        ?string $queue
    ) {
        $this->source = $source;
        $this->stage = $stage;
        $this->index = $index;
        $this->token = $token;
        $this->chunkId = $chunkId;
        $this->lockOwner = $lockOwner;
        $this->lockTtl = $lockTtl;
        $this->connectionName = $connection;
        $this->queueName = $queue;
        $this->timeout = Config::queueTimeout();
    }

    public function middleware(): array
    {
        return [new SkipIfImportRun($this->token, $this->source->searchableAs(), $this->lockOwner)];
    }

    public function handle(Client $elasticsearch): void
    {
        $this->stage->handle($elasticsearch);

        $store = app(ImportRunStore::class);
        $done = $store->markDone($this->token, $this->chunkId);

        if ($this->lockOwner !== null) {
            ImportLock::renew($this->source->searchableAs(), $this->lockOwner, $this->lockTtl);
        }
        $store->refreshTtls($this->token, $this->lockTtl);

        if ($done === $store->total($this->token)) {
            $job = new FinalizeJob($this->source, $this->index, $this->token, $this->lockOwner, $this->lockTtl);
            $job->timeout = Config::queueTimeout();
            if ($this->connectionName !== null) {
                $job->onConnection($this->connectionName);
            }
            if ($this->queueName !== null) {
                $job->onQueue($this->queueName);
            }
            Bus::dispatch($job);
        }
    }

    public function failed(Throwable $e): void
    {
        $store = app(ImportRunStore::class);

        if (! $store->failIfNotDone($this->token, $this->chunkId)) {
            return;
        }

        report($e);

        $job = (new RollbackImportJob($this->source, $this->index, $this->lockOwner))
            ->delay(now()->addSeconds($this->rollbackDelay()));
        $job->timeout = Config::queueTimeout();

        if ($this->connectionName !== null) {
            $job->onConnection($this->connectionName);
        }
        if ($this->queueName !== null) {
            $job->onQueue($this->queueName);
        }
        Bus::dispatch($job);
    }

    private function rollbackDelay(): int
    {
        $delay = config('elasticsearch.import.rollback_delay', 5);

        return is_numeric($delay) ? (int) $delay : 5;
    }

    public function backoff(): array
    {
        $count = $this->tries - 1;

        if ($this->backoffBase === null || $count < 1) {
            return [];
        }

        $cap = $this->backoffCap ?? $this->backoffBase;
        $count = min(50, $count);

        $delays = [];
        for ($n = 1; $n <= $count; $n++) {
            $delay = (int) min($cap, $this->backoffBase * (2 ** min(20, $n - 1)));
            $half = intdiv($delay, 2);
            $delays[] = $half + random_int(0, $half);
        }

        return $delays;
    }

    public function retryUntil(): ?\DateTimeInterface
    {
        return $this->retryUntilSeconds ? now()->addSeconds($this->retryUntilSeconds) : null;
    }
}
