<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Webhook;
use MonkeysLegion\Repository\RepositoryFactory;
use Predis\Client as PredisClient;

final class WebhookDispatcher
{
    public function __construct(
        private RepositoryFactory $repos,
        private PredisClient      $redis,                          // ← injected Predis
        private string            $queueKey = 'webhooks:deliveries'// ← configurable key
    ) {}

    /**
     * Dispatch to all active webhooks of a company that subscribed to $type.
     * A worker should consume JSON jobs from $queueKey (RPUSH / LPOP).
     */
    public function dispatch(int $companyId, string $type, array $payload, ?int $eventId = null): void
    {
        /** @var \App\Repository\WebhookRepository $whRepo */
        $whRepo = $this->repos->getRepository(Webhook::class);

        /** @var Webhook[] $webhooks */
        // If your repo expects a relation name use ['company' => $companyId] instead of company_id.
        $webhooks = $whRepo->findBy(['company_id' => $companyId, 'status' => 'active']);
        if (!$webhooks) {
            return;
        }

        $evt = [
            'id'         => $eventId ?? 0,
            'type'       => $type,
            'company_id' => $companyId,
            'payload'    => $payload,
            'created_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
        ];

        foreach ($webhooks as $wh) {
            $want = $wh->getEvents() ?? [];
            if (!in_array($type, $want, true)) {
                continue;
            }

            $job = [
                'webhook_id'  => $wh->getId(),
                'event'       => $evt,
                'attempt'     => 1,
                'delivery_id' => null,
                'enqueued_at' => $evt['created_at'],
            ];

            // Enqueue (right push) for FIFO; worker can LPOP/BLPOP.
            $this->redis->rpush($this->queueKey, [json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        }
    }
}
