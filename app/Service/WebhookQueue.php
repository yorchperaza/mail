<?php
declare(strict_types=1);

namespace App\Service;

final class WebhookQueue
{
    private \Redis $redis;
    private string $queueKey;

    public function __construct(string $redisUrl, string $queueKey = 'webhooks:deliveries')
    {
        $this->redis = new \Redis();
        $parts = parse_url($redisUrl);
        if (!$parts || !isset($parts['host'], $parts['port'])) {
            throw new \RuntimeException('Invalid REDIS_URL');
        }
        $this->redis->connect($parts['host'], (int)$parts['port'], 2.5);
        if (!empty($parts['pass'])) $this->redis->auth($parts['pass']);
        if (isset($parts['path'])) $this->redis->select((int)ltrim($parts['path'], '/')); // db index
        $this->queueKey = $queueKey;
    }

    /** Push a job (JSON-serializable array) */
    public function push(array $job): void
    {
        $job['enqueued_at'] = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
        $this->redis->lPush($this->queueKey, json_encode($job, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }

    /** Blocking pop with timeout seconds (0=infinite) */
    public function pop(int $timeout = 5): ?array
    {
        $res = $this->redis->brPop([$this->queueKey], $timeout);
        if (!$res || !isset($res[1])) return null;
        $decoded = json_decode($res[1], true);
        return is_array($decoded) ? $decoded : null;
    }
}
