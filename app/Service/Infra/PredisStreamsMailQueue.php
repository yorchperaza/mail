<?php
declare(strict_types=1);

namespace App\Service\Infra;

use App\Service\Ports\MailQueue;
use Predis\Client as Predis;

final class PredisStreamsMailQueue implements MailQueue
{
    public function __construct(
        private Predis $redis,
        private string $stream = 'mail:outbound',
        private string $group  = 'senders',
    ) {
        // Ensure consumer group exists (MKSTREAM creates stream if missing)
        try {
            // XGROUP CREATE <stream> <group> $ MKSTREAM
            $this->redis->executeRaw([
                'XGROUP', 'CREATE', $this->stream, $this->group, '$', 'MKSTREAM'
            ]);
        } catch (\Throwable $e) {
            // Ignore "BUSYGROUP" errors if the group already exists
            $msg = $e->getMessage();
            if (stripos($msg, 'BUSYGROUP') === false) {
                // different error -> rethrow
                throw $e;
            }
        }
    }

    /**
     * Enqueue a message (JSON payload) onto the Redis stream.
     */
    public function enqueue(array $payload): bool
    {
        // IMPORTANT: throw on JSON errors so we can log them properly upstream
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        // XADD <stream> * type outbound json <json>
        $id = $this->redis->executeRaw([
            'XADD', $this->stream, '*',
            'type', 'campaign',
            'json', $json,
        ]);

        return is_string($id) && $id !== '';
    }

    public function getGroup(): string
    {
        return $this->group;
    }

    public function getStream(): string
    {
        return $this->stream;
    }
}
