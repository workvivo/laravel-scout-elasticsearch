<?php

declare(strict_types=1);

namespace Tests\Fakes;

use LogicException;
use Matchish\ScoutElasticSearch\Import\ImportRunStore;

final class FakeImportRunStore implements ImportRunStore
{
    /**
     * @var array<string,array{status?:string,total?:int,index?:string,done:array<int,true>,finalize?:string,failed?:array<int,true>,leases?:array<int,array{owner:string,expires_at:float}>,redispatch?:array<int,int>,bounds?:array<int,array{0:int,1:mixed,2:mixed}>,profile?:array<string,array{count:int,weight:float|null,payload:string}>}>
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

    /**
     * @deprecated See ImportRunStore::failIfNotDone().
     */
    public function failIfNotDone(string $token, int $chunkId): bool
    {
        if ($this->status($token) !== self::STATUS_RUNNING || $this->isDone($token, $chunkId)) {
            return false;
        }

        $this->runs[$token]['status'] = self::STATUS_FAILED;

        return true;
    }

    public function claimChunk(string $token, int $chunkId, string $owner, int $ttlSeconds): bool
    {
        $lease = $this->lease($token, $chunkId);

        if ($lease !== null && $lease['owner'] !== $owner) {
            return false;
        }

        $this->runs[$token]['leases'][$chunkId] = [
            'owner' => $owner,
            'expires_at' => microtime(true) + max(1, $ttlSeconds),
        ];

        return true;
    }

    public function releaseChunk(string $token, int $chunkId, string $owner): void
    {
        $lease = $this->lease($token, $chunkId);

        if ($lease !== null && $lease['owner'] === $owner) {
            unset($this->runs[$token]['leases'][$chunkId]);
        }
    }

    public function chunkInFlight(string $token, int $chunkId): bool
    {
        return $this->lease($token, $chunkId) !== null;
    }

    public function recordFailure(string $token, int $chunkId): int
    {
        if ($this->isDone($token, $chunkId)) {
            return 0;
        }

        $this->runs[$token]['failed'][$chunkId] = true;

        return count($this->runs[$token]['failed']);
    }

    public function failureCount(string $token): int
    {
        return count($this->runs[$token]['failed'] ?? []);
    }

    public function failRun(string $token): bool
    {
        if ($this->status($token) !== self::STATUS_RUNNING) {
            return false;
        }

        $this->runs[$token]['status'] = self::STATUS_FAILED;

        return true;
    }

    public function bumpRedispatch(string $token, int $chunkId): int
    {
        $count = ($this->runs[$token]['redispatch'][$chunkId] ?? 0) + 1;
        $this->runs[$token]['redispatch'][$chunkId] = $count;

        return $count;
    }

    /**
     * @param  array<int, array{0:int, 1:mixed, 2:mixed}>  $bounds
     */
    public function pushBounds(string $token, array $bounds): void
    {
        foreach ($bounds as $triple) {
            // Round-trip through JSON like the Redis store does, so a test
            // cannot depend on object identity (or an int-typed chunk id
            // surviving) in a way the real store would not honour.
            /** @var array{0:int, 1:mixed, 2:mixed} $decoded */
            $decoded = json_decode((string) json_encode(array_values($triple)), true);
            $this->runs[$token]['bounds'][] = [(int) $decoded[0], $decoded[1], $decoded[2]];
        }
    }

    /**
     * @return array<int, array{0:int, 1:mixed, 2:mixed}>
     */
    public function popBounds(string $token, int $count): array
    {
        if ($count < 1 || ! isset($this->runs[$token]['bounds'])) {
            return [];
        }

        $popped = array_splice($this->runs[$token]['bounds'], 0, $count);

        return array_values($popped);
    }

    public function pendingBounds(string $token): int
    {
        return count($this->runs[$token]['bounds'] ?? []);
    }

    public function recordProfileFinding(string $token, string $code, float $weight, string $payload, int $ttlSeconds, int $cap): bool
    {
        if ($cap < 1) {
            return false;
        }

        $findings = $this->runs[$token]['profile'] ?? [];

        // The cap counts DISTINCT codes and never applies to a code already
        // tracked, exactly as the Lua does — otherwise a full hash would start
        // under-counting a finding it is already reporting.
        if (! isset($findings[$code]) && count($findings) >= $cap) {
            return false;
        }

        $existing = $findings[$code] ?? ['count' => 0, 'weight' => null, 'payload' => ''];

        // -1 is the same sentinel the Lua uses for "no weight recorded yet", so
        // a first finding whose weight is below it leaves the weight unset here
        // too, and both stores then report it as 0.
        $wins = $weight > ($existing['weight'] ?? -1.0);

        $findings[$code] = [
            'count' => $existing['count'] + 1,
            // Worst example wins, strictly: a tie keeps the earlier payload.
            'weight' => $wins ? $weight : $existing['weight'],
            'payload' => $wins ? $payload : $existing['payload'],
        ];

        $this->runs[$token]['profile'] = $findings;

        return true;
    }

    /**
     * @return array<string, array{count:int, weight:float, data:array<string,scalar>}>
     */
    public function profileFindings(string $token): array
    {
        $findings = [];

        foreach ($this->runs[$token]['profile'] ?? [] as $code => $finding) {
            // Round-trip the payload through JSON and keep only scalars, like
            // the Redis store does, so a test cannot rely on richer data
            // surviving than the real store would return.
            $decoded = json_decode($finding['payload'], true);
            $data = [];

            foreach (is_array($decoded) ? $decoded : [] as $field => $value) {
                if (is_scalar($value)) {
                    $data[(string) $field] = $value;
                }
            }

            $findings[$code] = [
                'count' => $finding['count'],
                'weight' => $finding['weight'] ?? 0.0,
                'data' => $data,
            ];
        }

        ksort($findings);

        return $findings;
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

    /**
     * Test helper: drop a chunk lease as if its TTL had elapsed, which is what a
     * worker killed mid-flight (SIGALRM / OOM) leaves behind once the lease
     * expires. Not part of ImportRunStore.
     */
    public function expireChunkLease(string $token, int $chunkId): void
    {
        unset($this->runs[$token]['leases'][$chunkId]);
    }

    /**
     * Live lease for a chunk, or null when there is none or it has expired.
     * Expiry is evaluated on read because nothing runs in the background here.
     *
     * @return array{owner:string,expires_at:float}|null
     */
    private function lease(string $token, int $chunkId): ?array
    {
        $lease = $this->runs[$token]['leases'][$chunkId] ?? null;

        if ($lease === null) {
            return null;
        }

        if ($lease['expires_at'] <= microtime(true)) {
            unset($this->runs[$token]['leases'][$chunkId]);

            return null;
        }

        return $lease;
    }
}
