<?php

declare(strict_types=1);

namespace Matchish\ScoutElasticSearch\Import;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;

final class RedisImportRunStore implements ImportRunStore
{
    private const START_SCRIPT = <<<'LUA'
local t = redis.call('GET', KEYS[2])
if not t then redis.call('SET', KEYS[2], ARGV[1])
elseif t ~= ARGV[1] then return redis.error_reply('total mismatch for token') end
local ix = redis.call('GET', KEYS[3])
if not ix then redis.call('SET', KEYS[3], ARGV[2])
elseif ix ~= ARGV[2] then return redis.error_reply('index mismatch for token') end
if not redis.call('GET', KEYS[1]) then redis.call('SET', KEYS[1], 'running') end
return 1
LUA;

    private const MARK_DONE_SCRIPT = <<<'LUA'
redis.call('SADD', KEYS[1], ARGV[1])
return redis.call('SCARD', KEYS[1])
LUA;

    private const FAIL_IF_NOT_DONE_SCRIPT = <<<'LUA'
if redis.call('GET', KEYS[1]) ~= 'running' then return 0 end
if redis.call('SISMEMBER', KEYS[2], ARGV[1]) == 1 then return 0 end
redis.call('SET', KEYS[1], 'failed'); return 1
LUA;

    private const CLAIM_FINALIZATION_SCRIPT = <<<'LUA'
local s = redis.call('GET', KEYS[1])
if s == 'finalizing' then return 1 end
if s ~= 'running' then return 0 end
local total = tonumber(redis.call('GET', KEYS[3]) or '-1')
local done  = redis.call('SCARD', KEYS[2])
if total == 0 or (total >= 0 and done == total) then
    redis.call('SET', KEYS[1], 'finalizing'); return 1
end
return 0
LUA;

    private const SUCCEED_SCRIPT = <<<'LUA'
if redis.call('GET', KEYS[2]) ~= ARGV[1] then return 0 end
if redis.call('GET', KEYS[1]) == 'finalizing' then
    redis.call('SET', KEYS[1], 'succeeded'); return 1
end
return 0
LUA;

    private const FINALIZE_FAILED_SCRIPT = <<<'LUA'
if redis.call('GET', KEYS[2]) ~= ARGV[1] then return 0 end
if redis.call('GET', KEYS[1]) == 'finalizing' then
    redis.call('SET', KEYS[1], 'finalize_failed'); return 1
end
return 0
LUA;

    private const RENEW_LOCK_SCRIPT = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
    redis.call('EXPIRE', KEYS[1], ARGV[2]); return 1
end
return 0
LUA;

    private const RELEASE_LOCK_SCRIPT = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then return redis.call('DEL', KEYS[1]) end
return 0
LUA;

    private const SUPPORTS_COORDINATION_SCRIPT = <<<'LUA'
redis.call('SET', KEYS[1], ARGV[1])
redis.call('SADD', KEYS[2], ARGV[2])
local ok = redis.call('GET', KEYS[1]) == ARGV[1] and redis.call('SCARD', KEYS[2]) == 1
redis.call('DEL', KEYS[1], KEYS[2])
if ok then return 1 end
return 0
LUA;

    private Connection $connection;

    public function __construct(RedisFactory $redis)
    {
        $connection = config('elasticsearch.import.redis.connection');
        $this->connection = $redis->connection(is_string($connection) ? $connection : null);
    }

    public function start(string $token, int $total, string $index): void
    {
        $this->eval(self::START_SCRIPT, [
            $this->key($token, 'status'),
            $this->key($token, 'total'),
            $this->key($token, 'index'),
        ], [(string) $total, $index]);
    }

    public function markDone(string $token, int $chunkId): int
    {
        return $this->toInt($this->eval(self::MARK_DONE_SCRIPT, [
            $this->key($token, 'done'),
        ], [(string) $chunkId]));
    }

    public function isDone(string $token, int $chunkId): bool
    {
        return (int) $this->connection->sismember($this->key($token, 'done'), (string) $chunkId) === 1;
    }

    public function total(string $token): int
    {
        return $this->toInt($this->connection->get($this->key($token, 'total')));
    }

    public function status(string $token): ?string
    {
        $status = $this->connection->get($this->key($token, 'status'));

        return is_string($status) ? $status : null;
    }

    public function snapshot(string $token): array
    {
        $index = $this->connection->get($this->key($token, 'index'));

        return [
            'status' => $this->status($token),
            'done' => (int) $this->connection->scard($this->key($token, 'done')),
            'total' => $this->total($token),
            'index' => is_string($index) ? $index : null,
        ];
    }

    public function refreshTtls(string $token, int $ttl): void
    {
        foreach (['status', 'total', 'index', 'done', 'finalize'] as $suffix) {
            $this->connection->expire($this->key($token, $suffix), $ttl);
        }
    }

    public function failIfNotDone(string $token, int $chunkId): bool
    {
        return $this->toInt($this->eval(self::FAIL_IF_NOT_DONE_SCRIPT, [
            $this->key($token, 'status'),
            $this->key($token, 'done'),
        ], [(string) $chunkId])) === 1;
    }

    public function claimFinalization(string $token): bool
    {
        return $this->toInt($this->eval(self::CLAIM_FINALIZATION_SCRIPT, [
            $this->key($token, 'status'),
            $this->key($token, 'done'),
            $this->key($token, 'total'),
        ])) === 1;
    }

    public function succeedIfFinalizing(string $token, string $lockOwner): bool
    {
        return $this->toInt($this->eval(self::SUCCEED_SCRIPT, [
            $this->key($token, 'status'),
            $this->key($token, 'finalize'),
        ], [$lockOwner])) === 1;
    }

    public function finalizeFailedIfFinalizing(string $token, string $lockOwner): bool
    {
        return $this->toInt($this->eval(self::FINALIZE_FAILED_SCRIPT, [
            $this->key($token, 'status'),
            $this->key($token, 'finalize'),
        ], [$lockOwner])) === 1;
    }

    public function acquireFinalizeLock(string $token, string $owner, int $ttlSeconds): bool
    {
        $key = $this->key($token, 'finalize');

        if ($this->connection->set($key, $owner, 'EX', $ttlSeconds, 'NX')) {
            return true;
        }

        return $this->renewFinalizeLock($token, $owner, $ttlSeconds);
    }

    public function renewFinalizeLock(string $token, string $owner, int $ttlSeconds): bool
    {
        return $this->toInt($this->eval(self::RENEW_LOCK_SCRIPT, [
            $this->key($token, 'finalize'),
        ], [$owner, (string) $ttlSeconds])) === 1;
    }

    public function releaseFinalizeLock(string $token, string $owner): void
    {
        $this->eval(self::RELEASE_LOCK_SCRIPT, [
            $this->key($token, 'finalize'),
        ], [$owner]);
    }

    public function supportsAtomicCoordination(): bool
    {
        try {
            $token = 'probe-'.bin2hex(random_bytes(8));

            return $this->toInt($this->eval(self::SUPPORTS_COORDINATION_SCRIPT, [
                $this->key($token, 'probe'),
                $this->key($token, 'probe_set'),
            ], ['1', 'member'])) === 1;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function key(string $token, string $suffix): string
    {
        return 'scout:import:run:{'.$token.'}:'.$suffix;
    }

    /**
     * @param  string[]  $keys
     * @param  string[]  $args
     * @return mixed
     */
    private function eval(string $script, array $keys, array $args = []): mixed
    {
        return $this->connection->eval($script, count($keys), ...array_merge($keys, $args));
    }

    private function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
