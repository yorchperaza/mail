#!/usr/bin/env php
<?php
declare(strict_types=1);

use App\Service\OutboundMailService;
use App\Service\Ports\MailQueue;
use Predis\Client as PredisClient;

require __DIR__ . '/../vendor/autoload.php';

$wrap = require __DIR__ . '/../bootstrap.php';
/** @var Psr\Container\ContainerInterface $container */
$container = $wrap->getContainer();

/** @var MailQueue $queue */
$queue   = $container->get(MailQueue::class);
/** @var OutboundMailService $service */
$service = $container->get(OutboundMailService::class);

// --- Get Redis client from container if available, else build from env ---
$redis = null;
if (method_exists($container, 'has') && $container->has(Redis::class)) {
    /** @var Redis $redis */
    $redis = $container->get(Redis::class);
} elseif (method_exists($container, 'has') && $container->has(PredisClient::class)) {
    /** @var PredisClient $redis */
    $redis = $container->get(PredisClient::class);
} else {
    // Fallback: construct a client from REDIS_URL or REDIS_* pieces
    $url = getenv('REDIS_URL');
    if (!$url || trim($url) === '') {
        $host = getenv('REDIS_HOST') ?: '127.0.0.1';
        $port = (int)(getenv('REDIS_PORT') ?: 6379);
        $db   = (int)(getenv('REDIS_DB')   ?: 0);
        $auth = getenv('REDIS_AUTH') ?: '';
        if (class_exists(\Redis::class)) {
            $r = new Redis();
            $r->connect($host, $port, 1.5);
            if ($auth !== '') {
                $user = getenv('REDIS_USERNAME') ?: null;
                $user ? $r->auth([$user, $auth]) : $r->auth($auth);
            }
            if ($db > 0) $r->select($db);
            $redis = $r;
        } else {
            $redis = new PredisClient(sprintf('redis://%s%s:%d/%d',
                    $auth !== '' ? (':'.rawurlencode($auth).'@') : '',
                    $host, $port, $db
            ));
        }
    } else {
        if (class_exists(\Redis::class)) {
            $parts = parse_url($url) ?: [];
            $host  = $parts['host'] ?? '127.0.0.1';
            $port  = (int)($parts['port'] ?? 6379);
            $db    = isset($parts['path']) ? (int)trim($parts['path'], '/') : 0;
            $user  = isset($parts['user']) ? rawurldecode($parts['user']) : (getenv('REDIS_USERNAME') ?: null);
            $pass  = isset($parts['pass']) ? rawurldecode($parts['pass']) : (getenv('REDIS_AUTH') ?: null);

            $r = new Redis();
            $r->connect($host, $port, 1.5);
            if (is_string($pass) && $pass !== '') {
                $user ? $r->auth([$user, $pass]) : $r->auth($pass);
            }
            if ($db > 0) $r->select($db);
            $redis = $r;
        } else {
            $redis = new PredisClient($url);
        }
    }
}

if (!$redis) {
    fwrite(STDERR, "[worker] No Redis client available.\n");
    exit(1);
}

// --- Resolve stream / group / consumer names ---
$stream   = method_exists($queue, 'getStream') ? $queue->getStream() : (getenv('MAIL_STREAM') ?: 'mail:outbound');
$group    = method_exists($queue, 'getGroup')  ? $queue->getGroup()  : (getenv('MAIL_GROUP')  ?: 'senders');
$consumer = (gethostname() ?: 'consumer') . '-' . getmypid();

$blockMs     = (int)(getenv('MAIL_BLOCK_MS')      ?: 5000);   // XREADGROUP block
$batch       = (int)(getenv('MAIL_BATCH')         ?: 20);     // max msgs per read
$claimIdleMs = (int)(getenv('MAIL_CLAIM_IDLE_MS') ?: 60000);  // claim PEL idle > 60s
$maxRetries  = (int)(getenv('MAIL_MAX_RETRIES')   ?: 5);

fwrite(STDERR, "[worker] stream={$stream} group={$group} consumer={$consumer}\n");

// --- Ensure group at 0 (read backlog) ---
$ensureGroupAtZero = function(string $stream, string $group) use ($redis) {
    try {
        if ($redis instanceof Redis) {
            $redis->xGroup('CREATE', $stream, $group, '0', true); // MKSTREAM
            fwrite(STDERR, "[worker] XGROUP CREATE {$stream} {$group} 0 (mkstream)\n");
        } else {
            /** @var PredisClient $redis */
            $redis->executeRaw(['XGROUP','CREATE',$stream,$group,'0','MKSTREAM']);
            fwrite(STDERR, "[worker] XGROUP CREATE {$stream} {$group} 0 (mkstream)\n");
        }
    } catch (\Throwable $e) {
        // If BUSYGROUP, still force start id to 0 once (safe even if already 0)
        try {
            if ($redis instanceof Redis) {
                $redis->xGroup('SETID', $stream, $group, '0');
                fwrite(STDERR, "[worker] XGROUP SETID {$stream} {$group} 0\n");
            } else {
                /** @var PredisClient $redis */
                $redis->executeRaw(['XGROUP','SETID',$stream,$group,'0']);
                fwrite(STDERR, "[worker] XGROUP SETID {$stream} {$group} 0\n");
            }
        } catch (\Throwable $ignored) {}
    }
};
$ensureGroupAtZero($stream, $group);

// --- Helpers to smooth phpredis / Predis differences ---
$readGroup = function (array $streams, int $count, int $blockMs) use ($redis, $group, $consumer) {
    if ($redis instanceof Redis) {
        return $redis->xReadGroup($group, $consumer, $streams, $count, $blockMs) ?: [];
    }
    /** @var PredisClient $redis */
    $cmd = ['XREADGROUP','GROUP',$group,$consumer,'COUNT',(string)$count,'BLOCK',(string)$blockMs,'STREAMS'];
    foreach ($streams as $s => $_) $cmd[] = $s;
    foreach ($streams as $_ => $id) $cmd[] = $id;
    $raw = $redis->executeRaw($cmd);
    $out = [];
    if (!is_array($raw)) return $out;
    foreach ($raw as $streamArr) {
        if (!is_array($streamArr) || count($streamArr) < 2) continue;
        $sName = $streamArr[0];
        $entries = $streamArr[1] ?? [];
        foreach ($entries as $e) {
            $entryId = $e[0] ?? null;
            $kv      = $e[1] ?? [];
            $fields  = [];
            for ($i=0; $i < count($kv); $i+=2) {
                $fields[(string)$kv[$i]] = (string)($kv[$i+1] ?? '');
            }
            if ($entryId) $out[$sName][$entryId] = $fields;
        }
    }
    return $out;
};

$xAck = function (string $stream, string $group, array $ids) use ($redis) {
    if ($redis instanceof Redis) return (bool)$redis->xAck($stream, $group, $ids);
    /** @var PredisClient $redis */
    $redis->executeRaw(array_merge(['XACK',$stream,$group], $ids));
    return true;
};

$xPending = function (string $stream, string $group, int $count) use ($redis) {
    if ($redis instanceof Redis) {
        return $redis->xPending($stream, $group, '-', '+', $count) ?: [];
    }
    /** @var PredisClient $redis */
    $raw = $redis->executeRaw(['XPENDING',$stream,$group,'-','+',(string)$count]);
    return is_array($raw) ? $raw : [];
};

$xClaim = function (string $stream, string $group, string $consumer, int $minIdle, array $ids) use ($redis) {
    try {
        if ($redis instanceof Redis) {
            return $redis->xClaim($stream, $group, $consumer, $minIdle, $ids, ['JUSTID' => false]);
        }
        /** @var PredisClient $redis */
        $cmd = array_merge(['XCLAIM',$stream,$group,$consumer,(string)$minIdle], $ids);
        return $redis->executeRaw($cmd);
    } catch (\Throwable) {
        return null;
    }
};

$xAdd = function (string $stream, array $fields) use ($redis) {
    if ($redis instanceof Redis) {
        return $redis->xAdd($stream, '*', $fields);
    }
    /** @var PredisClient $redis */
    $cmd = ['XADD',$stream,'*'];
    foreach ($fields as $k=>$v) { $cmd[] = (string)$k; $cmd[] = (string)$v; }
    return $redis->executeRaw($cmd);
};

// --- One-time backlog drain (deliver everything pending since 0) ---
$drainOnce = function() use ($readGroup, $stream, $service, $xAck, $group) {
    fwrite(STDERR, "[worker] draining backlog from id 0…\n");
    // Use a small loop to drain in batches in case there are lots of entries
    for ($i = 0; $i < 20; $i++) { // up to 20 batches
        $msgs = $readGroup([$stream => '0'], 100, 100); // quick sweep
        if (!$msgs || empty($msgs[$stream])) break;
        foreach ($msgs[$stream] as $entryId => $fields) {
            $raw = $fields['payload'] ?? ($fields['data'] ?? null);
            $payload = is_string($raw) ? (json_decode($raw, true) ?: null) : null;
            if (!$payload && isset($fields['message_id'])) {
                $payload = [
                        'message_id' => (int)$fields['message_id'],
                        'company_id' => isset($fields['company_id']) ? (int)$fields['company_id'] : null,
                        'domain_id'  => isset($fields['domain_id'])  ? (int)$fields['domain_id']  : null,
                        'envelope'   => isset($fields['envelope']) && is_string($fields['envelope'])
                                ? (json_decode($fields['envelope'], true) ?: [])
                                : [],
                ];
            }
            if (is_array($payload) && isset($payload['message_id'])) {
                fwrite(STDERR, "[worker][drain] processing entry {$entryId} message_id=".($payload['message_id'] ?? 'n/a')."\n");
                $service->processJob($payload);
            } else {
                fwrite(STDERR, "[worker][drain] skip malformed entry {$entryId}\n");
            }
            $xAck($stream, $group, [$entryId]);
        }
    }
    fwrite(STDERR, "[worker] backlog drain done.\n");
};
$drainOnce();

// --- Main loop ---
while (true) {
    try {
        // 1) Claim long-idle pending (another consumer crashed)
        foreach ($xPending($stream, $group, 50) as $p) {
            // format: [id, consumer, idleMs, deliveries]
            $id   = is_array($p) ? ($p[0] ?? null) : null;
            $idle = (int)(is_array($p) ? ($p[2] ?? 0) : 0);
            if ($id && $idle >= $claimIdleMs) {
                $xClaim($stream, $group, $consumer, $claimIdleMs, [$id]);
            }
        }

        // 2) Read new messages
        $msgs = $readGroup([$stream => '>'], $batch, $blockMs);
        if (!$msgs || empty($msgs[$stream])) {
            continue;
        }

        foreach ($msgs[$stream] as $entryId => $fields) {
            $payload = null;
            $raw     = $fields['payload'] ?? ($fields['data'] ?? null);

            // A) Common: JSON payload
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) $payload = $decoded;
            }

            // B) Flat fields (already key/values)
            if (!$payload && isset($fields['message_id'])) {
                $payload = [
                        'message_id' => (int)$fields['message_id'],
                        'company_id' => isset($fields['company_id']) ? (int)$fields['company_id'] : null,
                        'domain_id'  => isset($fields['domain_id'])  ? (int)$fields['domain_id']  : null,
                        'envelope'   => isset($fields['envelope']) && is_string($fields['envelope'])
                                ? (json_decode($fields['envelope'], true) ?: [])
                                : [],
                ];
            }

            // C) Last resort: single JSON field
            if (!$payload && count($fields) === 1) {
                $maybe = json_decode((string)reset($fields), true);
                if (is_array($maybe)) $payload = $maybe;
            }

            if (!is_array($payload) || !isset($payload['message_id'])) {
                $xAck($stream, $group, [$entryId]);
                fwrite(STDERR, "[worker] ack malformed entry {$entryId}\n");
                continue;
            }

            try {
                fwrite(STDERR, "[worker] processing entry {$entryId} message_id=".($payload['message_id'] ?? 'n/a')."\n");
                $service->processJob($payload);
                $xAck($stream, $group, [$entryId]);
            } catch (\Throwable $e) {
                $retries = isset($fields['retries']) ? (int)$fields['retries'] : 0;
                $retries++;

                if ($retries > $maxRetries) {
                    // DLQ
                    $xAdd($stream . ':dlq', [
                            'payload' => $raw ?: json_encode($payload, JSON_UNESCAPED_SLASHES),
                            'error'   => $e->getMessage(),
                            'at'      => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
                    ]);
                    $xAck($stream, $group, [$entryId]);
                } else {
                    // Requeue with retries++
                    $xAdd($stream, [
                            'payload' => $raw ?: json_encode($payload, JSON_UNESCAPED_SLASHES),
                            'retries' => (string)$retries,
                    ]);
                    $xAck($stream, $group, [$entryId]);
                }

                fwrite(STDERR, "[worker][ERR] {$e->getMessage()} @ {$e->getFile()}:{$e->getLine()}\n");
            }
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, "[worker][loop][ERR] {$e->getMessage()}\n");
        usleep(500000); // backoff 0.5s
    }
}
