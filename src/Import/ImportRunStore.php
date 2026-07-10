<?php

declare(strict_types=1);

namespace Matchish\ScoutElasticSearch\Import;

interface ImportRunStore
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_FINALIZING = 'finalizing';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_FINALIZE_FAILED = 'finalize_failed';

    public function start(string $token, int $total, string $index): void;

    public function markDone(string $token, int $chunkId): int;

    public function isDone(string $token, int $chunkId): bool;

    public function total(string $token): int;

    public function status(string $token): ?string;

    public function snapshot(string $token): array;

    public function refreshTtls(string $token, int $ttl): void;

    public function failIfNotDone(string $token, int $chunkId): bool;

    public function claimFinalization(string $token): bool;

    public function succeedIfFinalizing(string $token, string $lockOwner): bool;

    public function finalizeFailedIfFinalizing(string $token, string $lockOwner): bool;

    public function acquireFinalizeLock(string $token, string $owner, int $ttlSeconds): bool;

    public function renewFinalizeLock(string $token, string $owner, int $ttlSeconds): bool;

    public function releaseFinalizeLock(string $token, string $owner): void;

    public function supportsAtomicCoordination(): bool;
}
