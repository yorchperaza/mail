<?php

declare(strict_types=1);

namespace App\Service\Adapters;

use App\Service\Ports\MailQueue;
use Predis\Client as Predis;

final class PredisStreamsMailQueue implements MailQueue
{
    public function __construct(
        private Predis $redis,
        private string $stream = 'mail:outbound',
        private string $group = 'senders',
    )
    {
        // Create consumer group if needed (MKSTREAM creates the stream)
        try {
            $this->redis->xgroup('CREATE', $this->stream, $this->group, '$', 'MKSTREAM');
        } catch (\Throwable $e) {
            // Ignore BUSYGROUP / already exists
        }
    }

    public function enqueue(array $payload): bool
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Predis can want variadic or associative; try variadic first.
        try {
            $id = $this->redis->xadd(
                $this->stream,
                '*',
                'type', 'outbound',
                'json', (string)$json
            );
        } catch (\TypeError|\ArgumentCountError $e) {
            $id = $this->redis->xadd(
                $this->stream,
                '*',
                ['type' => 'outbound', 'json' => (string)$json]
            );
        }

        return is_string($id) && $id !== '';
    }

    public function getStream(): string
    {
        return $this->stream;
    }

    public function getGroup(): string
    {
        return $this->group;
    }
}
