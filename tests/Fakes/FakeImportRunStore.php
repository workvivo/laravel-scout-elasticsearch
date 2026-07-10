<?php

declare(strict_types=1);

namespace Tests\Fakes;

use LogicException;
use Matchish\ScoutElasticSearch\Import\ImportRunStore;

final class FakeImportRunStore implements ImportRunStore
{
    /**
     * @var array<string,array{status?:string,total?:int,index?:string,done:array<int,true>,finalize?:string}>
     */
    public array $runs = [];

    public function start(string $token, int $total, string $index): void
    {
        $run = $this->runs[$token] ?? ['done' => []];

        if (isset($run['total']) && $run['total'] !== $total) {
            throw new LogicException('total mismatch for token');
        }
        if (isset($run['index']) && $run['index'] !== $index) {
            throw new LogicException('index mismatch for token');
        }

        $run['total'] = $total;
        $run['index'] = $index;
        $run['status'] ??= self::STATUS_RUNNING;
        $run['done'] ??= [];

        $this->runs[$token] = $run;
    }

    public function markDone(string $token, int $chunkId): int
    {
        $this->runs[$token]['done'][$chunkId] = true;

        return count($this->runs[$token]['done']);
    }

    public function isDone(string $token, int $chunkId): bool
    {
        return isset($this->runs[$token]['done'][$chunkId]);
    }

    public function total(string $token): int
    {
        return (int) ($this->runs[$token]['total'] ?? 0);
    }

    public function status(string $token): ?string
    {
        return $this->runs[$token]['status'] ?? null;
    }

    public function snapshot(string $token): array
    {
        return [
            'status' => $this->status($token),
            'done' => isset($this->runs[$token]) ? count($this->runs[$token]['done'] ?? []) : 0,
            'total' => $this->total($token),
            'index' => $this->runs[$token]['index'] ?? null,
        ];
    }

    public function refreshTtls(string $token, int $ttl): void
    {
        //
    }

    public function failIfNotDone(string $token, int $chunkId): bool
    {
        if ($this->status($token) !== self::STATUS_RUNNING || $this->isDone($token, $chunkId)) {
            return false;
        }

        $this->runs[$token]['status'] = self::STATUS_FAILED;

        return true;
    }

    public function claimFinalization(string $token): bool
    {
        $status = $this->status($token);

        if ($status === self::STATUS_FINALIZING) {
            return true;
        }
        if ($status !== self::STATUS_RUNNING) {
            return false;
        }

        $total = $this->total($token);
        $done = count($this->runs[$token]['done'] ?? []);
        if ($total === 0 || $done === $total) {
            $this->runs[$token]['status'] = self::STATUS_FINALIZING;

            return true;
        }

        return false;
    }

    public function succeedIfFinalizing(string $token, string $lockOwner): bool
    {
        if (($this->runs[$token]['finalize'] ?? null) !== $lockOwner) {
            return false;
        }
        if ($this->status($token) !== self::STATUS_FINALIZING) {
            return false;
        }

        $this->runs[$token]['status'] = self::STATUS_SUCCEEDED;

        return true;
    }

    public function finalizeFailedIfFinalizing(string $token, string $lockOwner): bool
    {
        if (($this->runs[$token]['finalize'] ?? null) !== $lockOwner) {
            return false;
        }
        if ($this->status($token) !== self::STATUS_FINALIZING) {
            return false;
        }

        $this->runs[$token]['status'] = self::STATUS_FINALIZE_FAILED;

        return true;
    }

    public function acquireFinalizeLock(string $token, string $owner, int $ttlSeconds): bool
    {
        $heldBy = $this->runs[$token]['finalize'] ?? null;

        if ($heldBy === null || $heldBy === $owner) {
            $this->runs[$token]['finalize'] = $owner;

            return true;
        }

        return false;
    }

    public function renewFinalizeLock(string $token, string $owner, int $ttlSeconds): bool
    {
        return ($this->runs[$token]['finalize'] ?? null) === $owner;
    }

    public function releaseFinalizeLock(string $token, string $owner): void
    {
        if (($this->runs[$token]['finalize'] ?? null) === $owner) {
            unset($this->runs[$token]['finalize']);
        }
    }

    public function supportsAtomicCoordination(): bool
    {
        return true;
    }
}
