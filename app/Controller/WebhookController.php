<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Company;
use App\Entity\Event;
use App\Entity\Webhook;
use App\Entity\WebhookDelivery;
use App\Service\WebhookQueue;
use MonkeysLegion\Http\Message\JsonResponse;
use MonkeysLegion\Query\QueryBuilder;
use MonkeysLegion\Repository\RepositoryFactory;
use MonkeysLegion\Router\Attributes\Route;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

final class WebhookController
{
    public function __construct(
        private RepositoryFactory $repos,
        private QueryBuilder $qb
    ) {}

    private function queue(): WebhookQueue
    {
        $url = $_ENV['REDIS_URL'] ?? 'redis://127.0.0.1:6379/0';
        return new WebhookQueue($url);
    }

    /** Ensure requester belongs to company; returns Company */
    private function mustCompany(ServerRequestInterface $request, string $hash): Company
    {
        $uid = (int)$request->getAttribute('user_id', 0);
        if ($uid <= 0) throw new RuntimeException('Unauthorized', 401);

        /** @var \App\Repository\CompanyRepository $companyRepo */
        $companyRepo = $this->repos->getRepository(Company::class);
        /** @var ?Company $company */
        $company = $companyRepo->findOneBy(['hash' => $hash]);
        if (!$company) throw new RuntimeException('Company not found', 404);

        $belongs = array_filter($company->getUsers() ?? [], fn($u) => $u->getId() === $uid);
        if (empty($belongs)) throw new RuntimeException('Forbidden', 403);

        return $company;
    }

    #[Route(methods: 'GET', path: '/companies/{hash}/webhooks')]
    public function list(ServerRequestInterface $request): JsonResponse
    {
        $hash = (string)$request->getAttribute('hash');
        $company = $this->mustCompany($request, $hash);

        /** @var \App\Repository\WebhookRepository $repo */
        $repo = $this->repos->getRepository(Webhook::class);
        $rows = $repo->findBy(['company_id' => $company->getId()], orderBy: ['id'=>'DESC']);

        $out = array_map(function (Webhook $w) {
            return [
                'id'          => $w->getId(),
                'url'         => $w->getUrl(),
                'events'      => $w->getEvents(),
                'status'      => $w->getStatus(),
                'batch_size'  => $w->getBatch_size(),
                'max_retries' => $w->getMax_retries(),
                'retry_backoff' => $w->getRetry_backoff(),
                'created_at'  => $w->getCreated_at()?->format(\DateTimeInterface::ATOM),
            ];
        }, $rows);

        return new JsonResponse($out);
    }

    #[Route(methods: 'POST', path: '/companies/{hash}/webhooks')]
    public function create(ServerRequestInterface $request): JsonResponse
    {
        $hash = (string)$request->getAttribute('hash');
        $company = $this->mustCompany($request, $hash);

        $body = json_decode((string)$request->getBody(), true, JSON_THROW_ON_ERROR);
        $url  = trim((string)($body['url'] ?? ''));
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Valid url is required', 422);
        }
        $events = $body['events'] ?? ['message.delivered','message.bounced','tlsrpt.received','dmarc.processed','reputation.sampled'];
        if (!is_array($events)) $events = [];

        $secret  = $body['secret'] ?? bin2hex(random_bytes(32));
        $status  = in_array($body['status'] ?? 'active', ['active','disabled'], true) ? $body['status'] : 'active';
        $batch   = (int)($body['batch_size'] ?? 1);
        $retries = (int)($body['max_retries'] ?? 5);
        $backoff = (string)($body['retry_backoff'] ?? 'exponential:2,60,3600');

        $wh = new Webhook();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $wh
            ->setCompany($company)
            ->setUrl($url)
            ->setEvents(array_values(array_map('strval', $events)))
            ->setSecret((string)$secret)
            ->setStatus($status)
            ->setBatch_size(max(1, $batch))
            ->setMax_retries(max(0, $retries))
            ->setRetry_backoff($backoff)
            ->setCreated_at($now);

        /** @var \App\Repository\WebhookRepository $repo */
        $repo = $this->repos->getRepository(Webhook::class);
        $repo->save($wh);

        return new JsonResponse([
            'id' => $wh->getId(),
            'secret' => $wh->getSecret(),
        ], 201);
    }

    #[Route(methods: 'PATCH', path: '/companies/{hash}/webhooks/{id}')]
    public function update(ServerRequestInterface $request): JsonResponse
    {
        $hash = (string)$request->getAttribute('hash');
        $company = $this->mustCompany($request, $hash);

        $id = (int)$request->getAttribute('id');
        /** @var \App\Repository\WebhookRepository $repo */
        $repo = $this->repos->getRepository(Webhook::class);
        /** @var ?Webhook $wh */
        $wh = $repo->findOneBy(['id' => $id, 'company_id' => $company->getId()]);
        if (!$wh) throw new RuntimeException('Webhook not found', 404);

        $body = json_decode((string)$request->getBody(), true, JSON_THROW_ON_ERROR);

        if (array_key_exists('url', $body)) {
            $url = trim((string)$body['url']);
            if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
                throw new RuntimeException('Valid url is required', 422);
            }
            $wh->setUrl($url);
        }
        if (array_key_exists('events', $body)) {
            $wh->setEvents(is_array($body['events']) ? array_values(array_map('strval',$body['events'])) : []);
        }
        if (array_key_exists('status', $body)) {
            $st = (string)$body['status'];
            if (!in_array($st, ['active','disabled'], true)) {
                throw new RuntimeException('Invalid status', 422);
            }
            $wh->setStatus($st);
        }
        if (array_key_exists('batch_size', $body)) {
            $wh->setBatch_size(max(1, (int)$body['batch_size']));
        }
        if (array_key_exists('max_retries', $body)) {
            $wh->setMax_retries(max(0, (int)$body['max_retries']));
        }
        if (array_key_exists('retry_backoff', $body)) {
            $wh->setRetry_backoff((string)$body['retry_backoff']);
        }
        if (array_key_exists('rotate_secret', $body) && (bool)$body['rotate_secret'] === true) {
            $wh->setSecret(bin2hex(random_bytes(16)));
        }

        $repo->save($wh);

        return new JsonResponse([
            'id'     => $wh->getId(),
            'status' => $wh->getStatus(),
        ]);
    }

    #[Route(methods: 'DELETE', path: '/companies/{hash}/webhooks/{id}')]
    public function disable(ServerRequestInterface $request): JsonResponse
    {
        $hash = (string)$request->getAttribute('hash');
        $company = $this->mustCompany($request, $hash);

        $id = (int)$request->getAttribute('id');
        /** @var \App\Repository\WebhookRepository $repo */
        $repo = $this->repos->getRepository(Webhook::class);
        /** @var ?Webhook $wh */
        $wh = $repo->findOneBy(['id' => $id, 'company_id' => $company->getId()]);
        if (!$wh) throw new RuntimeException('Webhook not found', 404);

        $wh->setStatus('disabled');
        $repo->save($wh);

        return new JsonResponse(['status'=>'disabled']);
    }

    /**
     * Internal helper to enqueue an event to all matching webhooks of a company.
     * Call this from the places where you create system events (message delivered, tlsrpt, etc).
     */
    public function dispatchEvent(int $companyId, string $type, array $payload, ?int $eventId = null): void
    {
        /** @var \App\Repository\WebhookRepository $whRepo */
        $whRepo = $this->repos->getRepository(Webhook::class);

        $webhooks = $whRepo->findBy(['company_id' => $companyId, 'status' => 'active']);
        if (!$webhooks) return;

        $evt = [
            'id'         => $eventId ?? 0,
            'type'       => $type,
            'company_id' => $companyId,
            'payload'    => $payload,
            'created_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
        ];

        $queue = $this->queue();
        foreach ($webhooks as $wh) {
            // filter by events
            $list = $wh->getEvents() ?? [];
            if (!in_array($type, $list, true)) continue;

            $queue->push([
                'webhook_id' => $wh->getId(),
                'event'      => $evt,
                'attempt'    => 1,
                'delivery_id'=> null,
            ]);
        }
    }
}
