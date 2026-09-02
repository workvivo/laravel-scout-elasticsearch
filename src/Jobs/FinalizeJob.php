<?php

declare(strict_types=1);

namespace Matchish\ScoutElasticSearch\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Matchish\ScoutElasticSearch\ElasticSearch\Config\Config;
use Matchish\ScoutElasticSearch\ElasticSearch\Index;
use Matchish\ScoutElasticSearch\Import\ImportRunStore;
use Matchish\ScoutElasticSearch\ImportLock;
use Matchish\ScoutElasticSearch\Jobs\Stages\RefreshIndex;
use Matchish\ScoutElasticSearch\Jobs\Stages\SwitchToNewAndRemoveOldIndex;
use Matchish\ScoutElasticSearch\Searchable\ImportSource;
use OpenSearch\Client;
use OpenSearch\Common\Exceptions\Missing404Exception;
use Throwable;

final class FinalizeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private ImportSource $source;
    private Index $index;
    private string $token;
    private ?string $lockOwner;
    private int $lockTtl;
    private string $finalizeOwner;

    public int $tries = 3;
    public ?int $timeout = null;

    public function __construct(ImportSource $source, Index $index, string $token, ?string $lockOwner = null, int $lockTtl = 3600, ?string $finalizeOwner = null)
    {
        $this->source = $source;
        $this->index = $index;
        $this->token = $token;
        $this->lockOwner = $lockOwner;
        $this->lockTtl = $lockTtl;
        $this->finalizeOwner = $finalizeOwner ?? Str::random(40);
        $this->timeout = Config::queueTimeout();
    }

    public function handle(): void
    {
        $store = app(ImportRunStore::class);
        $finalizeTtl = $this->finalizeLockTtl();

        if (! $store->acquireFinalizeLock($this->token, $this->finalizeOwner, $finalizeTtl)) {
            return;
        }

        if (! $store->claimFinalization($this->token)) {
            $store->releaseFinalizeLock($this->token, $this->finalizeOwner);

            return;
        }

        if ($this->lockOwner !== null
            && ! ImportLock::isHeldBy($this->source->searchableAs(), $this->lockOwner)) {
            $store->releaseFinalizeLock($this->token, $this->finalizeOwner);

            return;
        }

        if ($store->status($this->token) !== ImportRunStore::STATUS_FINALIZING) {
            $store->releaseFinalizeLock($this->token, $this->finalizeOwner);

            return;
        }

        $this->finalize();

        if ($store->succeedIfFinalizing($this->token, $this->finalizeOwner)
            && $this->lockOwner !== null) {
            ImportLock::release($this->source->searchableAs(), $this->lockOwner);
        }

        $store->releaseFinalizeLock($this->token, $this->finalizeOwner);
    }

    public function failed(Throwable $e): void
    {
        $store = app(ImportRunStore::class);

        if ($store->finalizeFailedIfFinalizing($this->token, $this->finalizeOwner)
            && $this->lockOwner !== null) {
            ImportLock::release($this->source->searchableAs(), $this->lockOwner);
        }

        $store->releaseFinalizeLock($this->token, $this->finalizeOwner);

        logger()->error('scout:import finalize failed', [
            'searchable' => $this->source->searchableAs(),
            'index' => $this->index->name(),
            'exception' => get_class($e),
            'message' => $e->getMessage(),
        ]);
    }

    private function finalize(): void
    {
        $elasticsearch = app(Client::class);
        try {
            (new RefreshIndex($this->index))->handle($elasticsearch);
            (new SwitchToNewAndRemoveOldIndex($this->source, $this->index, $this->lockOwner))->handle($elasticsearch);
        } catch (Missing404Exception $e) {
            logger()->warning('scout:import finalize aborted: target index vanished', [
                'index' => $this->index->name(),
            ]);
        }
    }

    private function finalizeLockTtl(): int
    {
        $timeout = (int) ($this->timeout ?? Config::queueTimeout() ?? 0);

        return max($this->lockTtl, $timeout + 60, 60);
    }
}
