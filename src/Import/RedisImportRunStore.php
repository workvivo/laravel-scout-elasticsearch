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

    // NX takes the lease; the GET branch makes the claim re-entrant for the
    // owner that already holds it (and refreshes the TTL), so a retry inside the
    // same execution does not deadlock against itself.
    private const CLAIM_CHUNK_SCRIPT = <<<'LUA'
if redis.call('SET', KEYS[1], ARGV[1], 'NX', 'EX', ARGV[2]) then return 1 end
if redis.call('GET', KEYS[1]) == ARGV[1] then
    redis.call('EXPIRE', KEYS[1], ARGV[2]); return 1
end
return 0
LUA;

    // A chunk that finished never counts as failed (a duplicate delivery of a
    // completed chunk must not spend budget), and the failed set makes the count
    // idempotent per chunk however many times that chunk is re-delivered.
    private const RECORD_FAILURE_SCRIPT = <<<'LUA'
if redis.call('SISMEMBER', KEYS[1], ARGV[1]) == 1 then return 0 end
redis.call('SADD', KEYS[2], ARGV[1])
return redis.call('SCARD', KEYS[2])
LUA;

    private const FAIL_RUN_SCRIPT = <<<'LUA'
if redis.call('GET', KEYS[1]) ~= 'running' then return 0 end
redis.call('SET', KEYS[1], 'failed'); return 1
LUA;

    // First increment gets the TTL so an abandoned run's per-chunk counters do
    // not outlive it; refreshTtls() cannot reach these keys because it does not
    // know which chunk ids exist.
    private const BUMP_REDISPATCH_SCRIPT = <<<'LUA'
local n = redis.call('INCR', KEYS[1])
if n == 1 then redis.call('EXPIRE', KEYS[1], ARGV[1]) end
return n
LUA;

    // LPOP in a loop rather than `LPOP key count`, which needs Redis 6.2. The
    // whole batch leaves the list in one atomic step, so two concurrent fan-out
    // hops can never dispatch the same chunk.
    private const POP_BOUNDS_SCRIPT = <<<'LUA'
local out = {}
local count = tonumber(ARGV[1])
for i = 1, count do
    local item = redis.call('LPOP', KEYS[1])
    if not item then break end
    out[#out + 1] = item
end
return out
LUA;

    // Three sibling hashes (counts / weights / worst) instead of a list of
    // encoded findings: a run that profiles 100k chunks and trips the same rule
    // every time collapses to one row per code, and the script never has to
    // decode a payload — cjson is not guaranteed to be present in every managed
    // Redis, so the payload is carried through opaquely and only ever compared
    // via the separate numeric weight.
    //
    // The cap is on DISTINCT codes and is checked only for codes that are NOT
    // already tracked, so once a code is being reported its count stays exact
    // however full the hash is.
    private const RECORD_PROFILE_FINDING_SCRIPT = <<<'LUA'
local cap = tonumber(ARGV[5]) or 0
if redis.call('HLEN', KEYS[1]) >= cap and redis.call('HEXISTS', KEYS[1], ARGV[1]) == 0 then return 0 end
redis.call('HINCRBY', KEYS[1], ARGV[1], 1)
local weight = tonumber(ARGV[2]) or 0
if weight > tonumber(redis.call('HGET', KEYS[2], ARGV[1]) or '-1') then
    redis.call('HSET', KEYS[2], ARGV[1], ARGV[2])
    redis.call('HSET', KEYS[3], ARGV[1], ARGV[3])
end
redis.call('EXPIRE', KEYS[1], ARGV[4])
redis.call('EXPIRE', KEYS[2], ARGV[4])
redis.call('EXPIRE', KEYS[3], ARGV[4])
return 1
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
        // The finalize key is deliberately absent: it is a lock with its own,
        // much shorter TTL, and stretching it to the run TTL would let a
        // straggler chunk keep an orphaned finalize lock alive for the rest of
        // the run, blocking every later attempt to finalize.
        foreach ([
            'status', 'total', 'index', 'done', 'failed', 'bounds',
            'profile:counts', 'profile:weights', 'profile:worst',
        ] as $suffix) {
            $this->connection->expire($this->key($token, $suffix), $ttl);
        }
    }

    /**
     * @deprecated See ImportRunStore::failIfNotDone().
     */
    public function failIfNotDone(string $token, int $chunkId): bool
    {
        return $this->toInt($this->eval(self::FAIL_IF_NOT_DONE_SCRIPT, [
            $this->key($token, 'status'),
            $this->key($token, 'done'),
        ], [(string) $chunkId])) === 1;
    }

    public function claimChunk(string $token, int $chunkId, string $owner, int $ttlSeconds): bool
    {
        return $this->toInt($this->eval(self::CLAIM_CHUNK_SCRIPT, [
            $this->chunkKey($token, $chunkId, 'lease'),
        ], [$owner, (string) max(1, $ttlSeconds)])) === 1;
    }

    public function releaseChunk(string $token, int $chunkId, string $owner): void
    {
        $this->eval(self::RELEASE_LOCK_SCRIPT, [
            $this->chunkKey($token, $chunkId, 'lease'),
        ], [$owner]);
    }

    public function chunkInFlight(string $token, int $chunkId): bool
    {
        return (int) $this->connection->exists($this->chunkKey($token, $chunkId, 'lease')) === 1;
    }

    public function recordFailure(string $token, int $chunkId): int
    {
        return $this->toInt($this->eval(self::RECORD_FAILURE_SCRIPT, [
            $this->key($token, 'done'),
            $this->key($token, 'failed'),
        ], [(string) $chunkId]));
    }

    public function failureCount(string $token): int
    {
        return (int) $this->connection->scard($this->key($token, 'failed'));
    }

    public function failRun(string $token): bool
    {
        return $this->toInt($this->eval(self::FAIL_RUN_SCRIPT, [
            $this->key($token, 'status'),
        ])) === 1;
    }

    public function bumpRedispatch(string $token, int $chunkId): int
    {
        return $this->toInt($this->eval(self::BUMP_REDISPATCH_SCRIPT, [
            $this->chunkKey($token, $chunkId, 'redispatch'),
        ], [(string) $this->runTtl()]));
    }

    /**
     * @param  array<int, array{0:int, 1:mixed, 2:mixed}>  $bounds
     */
    public function pushBounds(string $token, array $bounds): void
    {
        if ($bounds === []) {
            return;
        }

        $key = $this->key($token, 'bounds');

        // A large table plans millions of chunks, so the triples go over in
        // slices instead of one command with an unbounded argument list.
        foreach (array_chunk(array_values($bounds), 1000) as $slice) {
            $payload = array_map(function (array $triple): string {
                return (string) json_encode(array_values($triple));
            }, $slice);

            // Spread rather than passing the array: phpredis takes RPUSH values
            // variadically, and Predis normalises a variadic call the same way.
            $this->connection->rpush($key, ...$payload);
        }
    }

    /**
     * @return array<int, array{0:int, 1:mixed, 2:mixed}>
     */
    public function popBounds(string $token, int $count): array
    {
        if ($count < 1) {
            return [];
        }

        $items = $this->eval(self::POP_BOUNDS_SCRIPT, [
            $this->key($token, 'bounds'),
        ], [(string) $count]);

        if (! is_array($items)) {
            return [];
        }

        $bounds = [];

        foreach ($items as $item) {
            $decoded = is_string($item) ? json_decode($item, true) : null;

            if (is_array($decoded) && count($decoded) === 3) {
                /** @var array{0:int, 1:mixed, 2:mixed} $triple */
                $triple = [(int) $decoded[0], $decoded[1], $decoded[2]];
                $bounds[] = $triple;
            }
        }

        return $bounds;
    }

    public function pendingBounds(string $token): int
    {
        return (int) $this->connection->llen($this->key($token, 'bounds'));
    }

    public function recordProfileFinding(string $token, string $code, float $weight, string $payload, int $ttlSeconds, int $cap): bool
    {
        // A cap of 0 disables publication outright, and it must do so without
        // touching Redis at all: profiling is a diagnostic, so switching it off
        // has to cost the import literally nothing.
        if ($cap < 1) {
            return false;
        }

        return $this->toInt($this->eval(self::RECORD_PROFILE_FINDING_SCRIPT, [
            $this->key($token, 'profile:counts'),
            $this->key($token, 'profile:weights'),
            $this->key($token, 'profile:worst'),
        ], [
            $code,
            $this->numberToString($weight),
            $payload,
            (string) max(1, $ttlSeconds),
            (string) $cap,
        ])) === 1;
    }

    /**
     * @return array<string, array{count:int, weight:float, data:array<string,scalar>}>
     */
    public function profileFindings(string $token): array
    {
        $counts = $this->hash($this->key($token, 'profile:counts'));

        if ($counts === []) {
            return [];
        }

        $weights = $this->hash($this->key($token, 'profile:weights'));
        $worst = $this->hash($this->key($token, 'profile:worst'));

        $findings = [];

        foreach ($counts as $code => $count) {
            $weight = $weights[$code] ?? null;

            $findings[$code] = [
                'count' => $this->toInt($count),
                'weight' => is_numeric($weight) ? (float) $weight : 0.0,
                'data' => $this->decodeFindingData($worst[$code] ?? null),
            ];
        }

        // Redis hash iteration order is arbitrary; sorting by code makes the
        // result reproducible and identical to the in-memory test double, so a
        // caller can render findings without re-sorting them first.
        ksort($findings);

        return $findings;
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
     * Per-chunk key. Routed through key() so the {token} hash tag is preserved
     * and every key of a run lands in the same Redis Cluster slot — scripts that
     * touch a chunk key together with a run key depend on it.
     */
    private function chunkKey(string $token, int $chunkId, string $suffix): string
    {
        return $this->key($token, 'chunk:'.$chunkId.':'.$suffix);
    }

    /**
     * TTL for keys that refreshTtls() cannot reach, so they are collected with
     * the rest of the run instead of leaking.
     */
    private function runTtl(): int
    {
        $ttl = config('elasticsearch.import.lock_ttl', 3600);

        return max(60, is_numeric($ttl) ? (int) $ttl : 3600);
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

    /**
     * @return array<string, mixed>
     */
    private function hash(string $key): array
    {
        $raw = $this->connection->hgetall($key);

        if (! is_array($raw)) {
            return [];
        }

        $hash = [];

        foreach ($raw as $field => $value) {
            $hash[(string) $field] = $value;
        }

        return $hash;
    }

    /**
     * Decode a stored worst-example payload. Anything that is not a JSON object
     * of scalars is dropped rather than trusted: the payload was encoded by a
     * different process (a queue worker, possibly running an older release), so
     * its shape is an assumption, not a guarantee.
     *
     * @return array<string, scalar>
     */
    private function decodeFindingData(mixed $payload): array
    {
        $decoded = is_string($payload) ? json_decode($payload, true) : null;

        if (! is_array($decoded)) {
            return [];
        }

        $data = [];

        foreach ($decoded as $field => $value) {
            if (is_scalar($value)) {
                $data[(string) $field] = $value;
            }
        }

        return $data;
    }

    /**
     * Weights cross the wire as strings, and PHP's default float formatting can
     * emit locale- or precision-mangled output for large values. Rendering
     * through a fixed format keeps the value something Lua's tonumber() reads
     * back as the same number.
     */
    private function numberToString(float $value): string
    {
        if (! is_finite($value)) {
            return '0';
        }

        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }
}
