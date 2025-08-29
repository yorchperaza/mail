<?php
declare(strict_types=1);

namespace App\Service\Infra;

use App\Service\Ports\MailQueue;
use App\Service\Ports\MailSender;

/**
 * Development inline queue:
 * - Implements MailQueue contract
 * - Processes jobs synchronously (best-effort) when possible
 * - Always returns true from enqueue() to mimic a successful queue push
 */
final class DevInlineMailQueue implements MailQueue
{
    public function __construct(
        private MailSender $sender
    ) {}

    /** Queue name (synthetic for dev) */
    public function getStream(): string
    {
        return 'inline';
    }

    /** Consumer group (synthetic for dev) */
    public function getGroup(): string
    {
        return 'inline';
    }

    /**
     * Process immediately in dev (if the sender exposes a compatible hook),
     * otherwise just report success so the rest of the flow continues locally.
     */
    public function enqueue(array $payload): bool
    {
        // Don’t hard-couple to a specific sender API: call opportunistically.
        // If your MailSender has a method like sendFromQueuePayload(array $job): void|bool,
        // we’ll invoke it; otherwise this becomes a no-op that still "queues".
        if (method_exists($this->sender, 'sendFromQueuePayload')) {
            try {
                // @phpstan-ignore-next-line dynamic dispatch in dev
                $this->sender->sendFromQueuePayload($payload);
            } catch (\Throwable) {
                // Swallow in dev mode; still return true to behave like a queue write.
            }
        }

        return true;
    }
}
