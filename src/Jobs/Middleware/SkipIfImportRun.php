<?php

declare(strict_types=1);

namespace Matchish\ScoutElasticSearch\Jobs\Middleware;

use Matchish\ScoutElasticSearch\Import\ImportRunStore;
use Matchish\ScoutElasticSearch\ImportLock;

final class SkipIfImportRun
{
    private string $token;
    private string $searchableAs;
    private ?string $owner;

    public function __construct(string $token, string $searchableAs, ?string $owner)
    {
        $this->token = $token;
        $this->searchableAs = $searchableAs;
        $this->owner = $owner;
    }

    public function handle(object $job, callable $next): void
    {
        $store = app(ImportRunStore::class);
        $status = $store->status($this->token);

        if (in_array($status, [
            ImportRunStore::STATUS_SUCCEEDED,
            ImportRunStore::STATUS_FAILED,
            ImportRunStore::STATUS_FINALIZE_FAILED,
        ], true)) {
            return;
        }

        if ($this->owner !== null && ! ImportLock::isHeldBy($this->searchableAs, $this->owner)) {
            return;
        }

        $next($job);
    }
}
