#!/usr/bin/env php
<?php
declare(strict_types=1);

use App\Service\OutboundMailService;
use App\Service\Ports\MailQueue;
use MonkeysLegion\Framework\Bootstrap;

require __DIR__ . '/../vendor/autoload.php';

/** @var MonkeysLegion\DI\Container $c */
$c = Bootstrap::container();

/** @var MailQueue $queue */
$queue = $c->get(MailQueue::class);
/** @var OutboundMailService $service */
$service = $c->get(OutboundMailService::class);

/** @var Redis $redis */
$redis = $c->get(Redis::class);

// Ensure stream/group exists
if (method_exists($queue, 'ensureGroup')) {
    $queue->ensureGroup();
}

$stream = $queue->getStream();
$group  = $queue->getGroup();
$consumer = gethostname() . '-' . getmypid();

$blockMs     = 5000;   // block 5s
$batch       = 20;     // max msgs / read
$claimIdleMs = 60000;  // claim msgs idle > 60s
$maxRetries  = 5;      // store in msg field "retries"

echo "[worker] listening on stream={$stream} group={$group} consumer={$consumer}\n";

while (true) {
    try {
        // 1) Claim long-idle pending messages (crashed consumers)
        $pending = $redis->xPending($stream, $group, '-', '+', 50);
        foreach ($pending as $p) {
            [$id, , $idle, $delivered] = $p; // [id, consumer, idle, deliveries]
            if ($idle >= $claimIdleMs) {
                try {
                    $redis->xClaim($stream, $group, $consumer, $claimIdleMs, [$id], ['JUSTID' => false]);
                } catch (\Throwable $e) {
                    // ignore claim errors
                }
            }
        }

        // 2) Read new + claimed messages
        $msgs = $redis->xReadGroup(
            $group, $consumer,
            [$stream => '>'],  // '>' = new messages for this group
            $batch,
            $blockMs
        );

        if (!$msgs || !isset($msgs[$stream])) continue;

        foreach ($msgs[$stream] as $entryId => $fields) {
            $payloadRaw = $fields['payload'] ?? null;
            $payload    = is_string($payloadRaw) ? json_decode($payloadRaw, true) : null;

            // retry counter
            $retries = isset($fields['retries']) ? (int)$fields['retries'] : 0;

            if (!is_array($payload) || !isset($payload['message_id'])) {
                // bad message -> ack and continue
                $redis->xAck($stream, $group, [$entryId]);
                continue;
            }

            try {
                $service->processJob($payload);
                $redis->xAck($stream, $group, [$entryId]);
            } catch (\Throwable $e) {
                $retries++;
                if ($retries > $maxRetries) {
                    // Move to a dead-letter stream
                    $redis->xAdd($stream . ':dlq', '*', [
                        'payload' => $payloadRaw,
                        'error'   => $e->getMessage(),
                        'at'      => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
                    ]);
                    $redis->xAck($stream, $group, [$entryId]);
                } else {
                    // Re-add with a retries hint and ack the old one
                    $redis->xAdd($stream, '*', ['payload' => $payloadRaw, 'retries' => (string)$retries]);
                    $redis->xAck($stream, $group, [$entryId]);
                }
            }
        }
    } catch (\RedisException $e) {
        fwrite(STDERR, "[worker] redis error: {$e->getMessage()}\n");
        usleep(500_000);
    } catch (\Throwable $e) {
        fwrite(STDERR, "[worker] fatal: {$e->getMessage()}\n");
        usleep(500_000);
    }
}
