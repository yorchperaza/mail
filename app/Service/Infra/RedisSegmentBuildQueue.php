<?php

namespace App\Service\Infra;

use App\Service\Ports\SegmentBuildQueue;
use App\Service\Ports\DistributedLock;
use Random\RandomException;
use Redis;

final class RedisSegmentBuildQueue implements SegmentBuildQueue
{
    public function __construct(private Redis $redis, private string $stream = 'seg:builds')
    {
    }

    public function enqueue(array $job): string|int|null
    {
        // Use XADD (Streams) – durable, ordered, good for multiple workers
        return $this->redis->xAdd($this->stream, '*', ['data' => json_encode($job)]);
    }

    public function getStream(): string
    {
        return $this->stream;
    }
}

final class RedisLock implements DistributedLock
{
    public function __construct(private Redis $redis)
    {
    }

    /**
     * @throws RandomException
     */
    public function acquire(string $key, int $ttlSec): ?string
    {
        $token = bin2hex(random_bytes(16));
        // SET key token NX EX ttl
        $ok = $this->redis->set($key, $token, ['NX', 'EX' => $ttlSec]);
        return $ok ? $token : null;
    }

    public function release(string $key, string $token): void
    {
        // Safe release (Lua compare-and-del)
        $lua = <<<LUA
if redis.call("GET", KEYS[1]) == ARGV[1] then
  return redis.call("DEL", KEYS[1])
else
  return 0
end
LUA;
        $this->redis->eval($lua, [$key, $token], 1);
    }
}
