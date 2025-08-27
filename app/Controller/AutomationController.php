<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Automation;
use App\Entity\Company;
use App\Service\CompanyResolver;
use MonkeysLegion\Http\Message\JsonResponse;
use MonkeysLegion\Repository\RepositoryFactory;
use MonkeysLegion\Router\Attributes\Route;
use MonkeysLegion\Query\QueryBuilder;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

final class AutomationController
{
    public function __construct(
        private RepositoryFactory $repos,
        private CompanyResolver   $companyResolver,
        private QueryBuilder      $qb,
    ) {}

    /* ---------------------------- helpers ---------------------------- */

    private function auth(ServerRequestInterface $r): int {
        $uid = (int)$r->getAttribute('user_id', 0);
        if ($uid <= 0) throw new RuntimeException('Unauthorized', 401);
        return $uid;
    }

    /**
     * @throws \ReflectionException
     */
    private function company(string $hash, int $uid): Company {
        $c = $this->companyResolver->resolveCompanyForUser($hash, $uid);
        if (!$c) throw new RuntimeException('Company not found or access denied', 404);
        return $c;
    }

    /**
     * @throws \DateMalformedStringException
     */
    private function now(): \DateTimeImmutable {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private function shape(Automation $a): array {
        return [
            'id'           => $a->getId(),
            'name'         => $a->getName(),
            'trigger'      => $a->getTrigger(),             // e.g. 'webhook', 'time', 'event'
            'flow'         => $a->getFlow(),                // JSON definition
            'status'       => $a->getStatus(),              // e.g. 'draft' | 'active' | 'paused' | 'disabled'
            'last_run_at'  => $a->getLast_run_at()?->format(\DateTimeInterface::ATOM),
            'created_at'   => $a->getCreated_at()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /* ------------------------------ list ------------------------------ */

    #[Route(methods: 'GET', path: '/companies/{hash}/automations')]
    public function list(ServerRequestInterface $r): JsonResponse {
        $uid = $this->auth($r);
        $co  = $this->company((string)$r->getAttribute('hash'), $uid);

        /** @var \App\Repository\AutomationRepository $repo */
        $repo = $this->repos->getRepository(Automation::class);

        $q        = $r->getQueryParams();
        $status   = (string)($q['status'] ?? '');
        $trigger  = (string)($q['trigger'] ?? '');
        $search   = trim((string)($q['search'] ?? ''));
        $page     = max(1, (int)($q['page'] ?? 1));
        $perPage  = max(1, min(200, (int)($q['perPage'] ?? 25)));

        $rows = $repo->findBy(['company_id' => (int)$co->getId()]);

        if ($status !== '') {
            $rows = array_values(array_filter($rows, fn(Automation $a) => (string)$a->getStatus() === $status));
        }
        if ($trigger !== '') {
            $rows = array_values(array_filter($rows, fn(Automation $a) => (string)$a->getTrigger() === $trigger));
        }
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $rows = array_values(array_filter($rows, function(Automation $a) use ($needle) {
                return str_contains(mb_strtolower((string)$a->getName()), $needle)
                    || str_contains(mb_strtolower((string)$a->getTrigger()), $needle)
                    || str_contains(json_encode($a->getFlow() ?? [], JSON_UNESCAPED_UNICODE), $needle);
            }));
        }

        $total = count($rows);
        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return new JsonResponse([
            'meta'  => [
                'page'       => $page,
                'perPage'    => $perPage,
                'total'      => $total,
                'totalPages' => (int)ceil($total / $perPage),
            ],
            'items' => array_map(fn(Automation $a) => $this->shape($a), $slice),
        ]);
    }

    /* ------------------------------ create ----------------------------- */

    /**
     * Body:
     *   name (required), trigger?, flow?(json), status?
     */
    #[Route(methods: 'POST', path: '/companies/{hash}/automations')]
    public function create(ServerRequestInterface $r): JsonResponse {
        $uid = $this->auth($r);
        $co  = $this->company((string)$r->getAttribute('hash'), $uid);

        $body = json_decode((string)$r->getBody(), true) ?: [];
        $name = trim((string)($body['name'] ?? ''));
        if ($name === '') throw new RuntimeException('Name is required', 400);

        /** @var \App\Repository\AutomationRepository $repo */
        $repo = $this->repos->getRepository(Automation::class);

        $a = (new Automation())
            ->setCompany($co)
            ->setName($name)
            ->setTrigger(isset($body['trigger']) ? (string)$body['trigger'] : null)
            ->setFlow(isset($body['flow']) && is_array($body['flow']) ? $body['flow'] : null)
            ->setStatus(isset($body['status']) ? (string)$body['status'] : 'draft')
            ->setCreated_at($this->now())
            ->setLast_run_at(null);

        $repo->save($a);
        return new JsonResponse($this->shape($a), 201);
    }

    /* -------------------------------- get ------------------------------- */

    #[Route(methods: 'GET', path: '/companies/{hash}/automations/{id}')]
    public function get(ServerRequestInterface $r): JsonResponse {
        $uid = $this->auth($r);
        $co  = $this->company((string)$r->getAttribute('hash'), $uid);
        $id  = (int)$r->getAttribute('id');

        /** @var \App\Repository\AutomationRepository $repo */
        $repo = $this->repos->getRepository(Automation::class);
        $a = $repo->find($id);
        if (!$a || $a->getCompany()?->getId() !== $co->getId()) {
            throw new RuntimeException('Automation not found', 404);
        }

        return new JsonResponse($this->shape($a));
    }

    /* ------------------------------- update ----------------------------- */

    /**
     * Body (all optional):
     *   name?, trigger?, flow?(json), status?
     * @throws \JsonException
     */
    #[Route(methods: 'PATCH', path: '/companies/{hash}/automations/{id}')]
    public function update(ServerRequestInterface $r): JsonResponse {
        $uid = $this->auth($r);
        $co  = $this->company((string)$r->getAttribute('hash'), $uid);
        $id  = (int)$r->getAttribute('id');

        /** @var \App\Repository\AutomationRepository $repo */
        $repo = $this->repos->getRepository(Automation::class);
        $a = $repo->find($id);
        if (!$a || $a->getCompany()?->getId() !== $co->getId()) {
            throw new RuntimeException('Automation not found', 404);
        }

        $body = json_decode((string)$r->getBody(), true) ?: [];

        if (array_key_exists('name', $body)) {
            $name = trim((string)$body['name']);
            if ($name === '') throw new RuntimeException('Name cannot be empty', 400);
            $a->setName($name);
        }
        if (array_key_exists('trigger', $body)) {
            $a->setTrigger((string)$body['trigger'] ?: null);
        }
        if (array_key_exists('flow', $body)) {
            $a->setFlow(is_array($body['flow']) ? $body['flow'] : null);
        }
        if (array_key_exists('status', $body)) {
            $a->setStatus((string)$body['status'] ?: null);
        }

        $repo->save($a);
        return new JsonResponse($this->shape($a));
    }

    /* ------------------------------- delete ----------------------------- */

    /**
     * @throws \ReflectionException
     * @throws \JsonException
     */
    #[Route(methods: 'DELETE', path: '/companies/{hash}/automations/{id}')]
    public function delete(ServerRequestInterface $r): JsonResponse {
        $uid = $this->auth($r);
        $co  = $this->company((string)$r->getAttribute('hash'), $uid);
        $id  = (int)$r->getAttribute('id');

        /** @var \App\Repository\AutomationRepository $repo */
        $repo = $this->repos->getRepository(Automation::class);
        $a = $repo->find($id);
        if (!$a || $a->getCompany()?->getId() !== $co->getId()) {
            throw new RuntimeException('Automation not found', 404);
        }

        if (method_exists($repo, 'delete')) $repo->delete($a);
        elseif (method_exists($repo, 'remove')) $repo->remove($a);
        else $this->qb->delete('automation')->where('id', '=', $id)->execute();

        return new JsonResponse(null, 204);
    }

    /* --------------------------- lifecycle ops --------------------------- */

    // Activate (status → active)
    #[Route(methods: 'POST', path: '/companies/{hash}/automations/{id}/enable')]
    public function enable(ServerRequestInterface $r): JsonResponse {
        return $this->setStatus($r, 'active');
    }

    // Pause (status → paused)
    #[Route(methods: 'POST', path: '/companies/{hash}/automations/{id}/pause')]
    public function pause(ServerRequestInterface $r): JsonResponse {
        return $this->setStatus($r, 'paused');
    }

    // Disable (status → disabled)
    #[Route(methods: 'POST', path: '/companies/{hash}/automations/{id}/disable')]
    public function disable(ServerRequestInterface $r): JsonResponse {
        return $this->setStatus($r, 'disabled');
    }

    // Manual run (records last_run_at); enqueue real work elsewhere if needed
    #[Route(methods: 'POST', path: '/companies/{hash}/automations/{id}/run')]
    public function run(ServerRequestInterface $r): JsonResponse {
        $uid = $this->auth($r);
        $co  = $this->company((string)$r->getAttribute('hash'), $uid);
        $id  = (int)$r->getAttribute('id');

        /** @var \App\Repository\AutomationRepository $repo */
        $repo = $this->repos->getRepository(Automation::class);
        $a = $repo->find($id);
        if (!$a || $a->getCompany()?->getId() !== $co->getId()) {
            throw new RuntimeException('Automation not found', 404);
        }

        // (Optional) Validate status before running
        if ($a->getStatus() === 'disabled') {
            throw new RuntimeException('Disabled automations cannot be run', 400);
        }

        $a->setLast_run_at($this->now());
        $repo->save($a);

        // TODO: enqueue job / trigger dispatcher here

        return new JsonResponse([
            'automation' => $this->shape($a),
            'run' => [
                'started_at' => $a->getLast_run_at()?->format(\DateTimeInterface::ATOM),
                'status'     => 'queued', // or 'started'
            ],
        ]);
    }

    /* ---------------------------- utilities ---------------------------- */

    private function setStatus(ServerRequestInterface $r, string $status): JsonResponse {
        $uid = $this->auth($r);
        $co  = $this->company((string)$r->getAttribute('hash'), $uid);
        $id  = (int)$r->getAttribute('id');

        /** @var \App\Repository\AutomationRepository $repo */
        $repo = $this->repos->getRepository(Automation::class);
        $a = $repo->find($id);
        if (!$a || $a->getCompany()?->getId() !== $co->getId()) {
            throw new RuntimeException('Automation not found', 404);
        }

        $a->setStatus($status);
        $repo->save($a);

        return new JsonResponse($this->shape($a));
    }
}
