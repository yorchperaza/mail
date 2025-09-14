<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\InboundRoute;
use App\Entity\Company;
use App\Entity\Domain;
use App\Service\CompanyResolver;
use MonkeysLegion\Http\Message\JsonResponse;
use MonkeysLegion\Repository\RepositoryFactory;
use MonkeysLegion\Router\Attributes\Route;
use MonkeysLegion\Query\QueryBuilder;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

final class InboundRouteController
{
    public function __construct(
        private RepositoryFactory $repos,
        private CompanyResolver   $companyResolver,
        private QueryBuilder      $qb,
    )
    {
    }

    /* ---------------------------- helpers ---------------------------- */

    private function auth(ServerRequestInterface $r): int
    {
        $uid = (int)$r->getAttribute('user_id', 0);
        if ($uid <= 0) throw new RuntimeException('Unauthorized', 401);
        return $uid;
    }

    /**
     * @throws \ReflectionException
     */
    private function company(string $hash, int $uid): Company
    {
        $c = $this->companyResolver->resolveCompanyForUser($hash, $uid);
        if (!$c) throw new RuntimeException('Company not found or access denied', 404);
        return $c;
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private function toStringOrNull(mixed $v): ?string
    {
        if ($v === null) return null;
        $s = is_string($v) ? trim($v) : (string)$v;
        return $s === '' || strtolower($s) === 'null' ? null : $s;
    }

    private function toFloatOrNull(mixed $v): ?float
    {
        if ($v === null || $v === '') return null;
        if (is_numeric($v)) return (float)$v;
        return null;
    }

    private function toBool01OrNull(mixed $v): ?int
    {
        // accepts true/false, "1"/"0", 1/0, "true"/"false"
        if ($v === null || $v === '') return null;
        if (is_bool($v)) return $v ? 1 : 0;
        $s = is_string($v) ? strtolower(trim($v)) : $v;
        if ($s === 'true' || $s === 1 || $s === '1') return 1;
        if ($s === 'false' || $s === 0 || $s === '0') return 0;
        return null;
    }

    private function toIntOrNull(mixed $v): ?int
    {
        if ($v === null || $v === '') return null;
        if (is_numeric($v)) return (int)$v;
        return null;
    }

    private function toArrayOrNull(mixed $v): ?array
    {
        if ($v === null) return null;
        if (is_array($v)) return $v;
        // allow JSON string
        if (is_string($v)) {
            $v = trim($v);
            if ($v === '') return null;
            try {
                $decoded = json_decode($v, true, 512, JSON_THROW_ON_ERROR);
                return is_array($decoded) ? $decoded : null;
            } catch (\Throwable) {
                return null;
            }
        }
        return null;
    }

    private function shape(InboundRoute $r): array
    {
        $d = $r->getDomain();
        return [
            'id' => $r->getId(),
            'pattern' => $r->getPattern(),
            'action' => $r->getAction(),
            'destination' => $r->getDestination(),
            'spam_threshold' => $r->getSpam_threshold(),
            'dkim_required' => $r->getDkim_required(), // 0/1 or null
            'tls_required' => $r->getTls_required(),  // 0/1 or null
            'created_at' => $r->getCreated_at()?->format(\DateTimeInterface::ATOM),
            'domain' => $d ? [
                'id' => $d->getId(),
                'domain' => $d->getDomain() ?? null,
            ] : null,
        ];
    }

    /* ------------------------------ list ------------------------------ */
    /**
     * Query params:
     *   search? (pattern/action contains), domainId?, page?=1, perPage?=25
     */
    #[Route(methods: 'GET', path: '/companies/{hash}/inbound-routes')]
    public function list(ServerRequestInterface $r): JsonResponse
    {
        $uid = $this->auth($r);
        $co = $this->company((string)$r->getAttribute('hash'), $uid);

        /** @var \App\Repository\InboundRouteRepository $repo */
        $repo = $this->repos->getRepository(InboundRoute::class);

        $q = $r->getQueryParams();
        $search = trim((string)($q['search'] ?? ''));
        $domainId = $this->toIntOrNull($q['domainId'] ?? null);
        $page = max(1, (int)($q['page'] ?? 1));
        $perPage = max(1, min(200, (int)($q['perPage'] ?? 25)));

        // If repo doesn't auto-filter by company, do it here.
        $rows = array_values(array_filter(
            $repo->findBy([]),
            fn(InboundRoute $ir) => $ir->getCompany()?->getId() === $co->getId()
        ));

        if ($domainId !== null) {
            $rows = array_values(array_filter($rows, fn(InboundRoute $ir) => $ir->getDomain()?->getId() === $domainId));
        }

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $rows = array_values(array_filter($rows, function (InboundRoute $ir) use ($needle) {
                $p = mb_strtolower((string)($ir->getPattern() ?? ''));
                $a = mb_strtolower((string)($ir->getAction() ?? ''));
                return str_contains($p, $needle) || str_contains($a, $needle);
            }));
        }

        $total = count($rows);
        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return new JsonResponse([
            'meta' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
                'totalPages' => (int)ceil($total / $perPage),
            ],
            'items' => array_map(fn(InboundRoute $ir) => $this->shape($ir), $slice),
        ]);
    }

    /* ------------------------------ create ----------------------------- */
    /**
     * Body:
     *   pattern?, action?, destination?(object), spam_threshold?(float),
     *   dkim_required?(bool/int 0|1), tls_required?(bool/int 0|1),
     *   domainId?(int|null)
     */
    #[Route(methods: 'POST', path: '/companies/{hash}/inbound-routes')]
    public function create(ServerRequestInterface $r): JsonResponse
    {
        $uid = $this->auth($r);
        $co = $this->company((string)$r->getAttribute('hash'), $uid);

        $body = json_decode((string)$r->getBody(), true) ?: [];

        $pattern = $this->toStringOrNull($body['pattern'] ?? null);
        $action = $this->toStringOrNull($body['action'] ?? null);
        $destination = $this->toArrayOrNull($body['destination'] ?? null);
        $spamThreshold = $this->toFloatOrNull($body['spam_threshold'] ?? null);
        $dkimRequired = $this->toBool01OrNull($body['dkim_required'] ?? null);
        $tlsRequired = $this->toBool01OrNull($body['tls_required'] ?? null);
        $domainId = $this->toIntOrNull($body['domainId'] ?? null);

        /** @var \App\Repository\DomainRepository $domainRepo */
        $domainRepo = $this->repos->getRepository(Domain::class);
        $domain = null;
        if ($domainId !== null) {
            $domain = $domainRepo->find($domainId);
            if (!$domain || $domain->getCompany()?->getId() !== $co->getId()) {
                throw new RuntimeException('Domain not found for this company', 404);
            }
        }

        /** @var \App\Repository\InboundRouteRepository $repo */
        $repo = $this->repos->getRepository(InboundRoute::class);

        $ir = (new InboundRoute())
            ->setCompany($co)
            ->setDomain($domain)
            ->setPattern($pattern)
            ->setAction($action)
            ->setDestination($destination)
            ->setSpam_threshold($spamThreshold)
            ->setDkim_required($dkimRequired)
            ->setTls_required($tlsRequired)
            ->setCreated_at($this->now());

        $repo->save($ir);

        return new JsonResponse($this->shape($ir), 201);
    }

    /* -------------------------------- get ------------------------------- */
    #[Route(methods: 'GET', path: '/companies/{hash}/inbound-routes/{id}')]
    public function get(ServerRequestInterface $r): JsonResponse
    {
        $uid = $this->auth($r);
        $co = $this->company((string)$r->getAttribute('hash'), $uid);
        $id = (int)$r->getAttribute('id');

        /** @var \App\Repository\InboundRouteRepository $repo */
        $repo = $this->repos->getRepository(InboundRoute::class);

        $ir = $repo->find($id);
        if (!$ir || $ir->getCompany()?->getId() !== $co->getId()) {
            throw new RuntimeException('Inbound route not found', 404);
        }

        return new JsonResponse($this->shape($ir));
    }

    /* ------------------------------- update ----------------------------- */
    /**
     * Body (all optional):
     *   pattern?, action?, destination?(object), spam_threshold?(float),
     *   dkim_required?(bool/int 0|1), tls_required?(bool/int 0|1),
     *   domainId?(int|null)
     */
    #[Route(methods: 'PATCH', path: '/companies/{hash}/inbound-routes/{id}')]
    public function update(ServerRequestInterface $r): JsonResponse
    {
        $uid = $this->auth($r);
        $co = $this->company((string)$r->getAttribute('hash'), $uid);
        $id = (int)$r->getAttribute('id');

        /** @var \App\Repository\InboundRouteRepository $repo */
        $repo = $this->repos->getRepository(InboundRoute::class);
        /** @var \App\Repository\DomainRepository $domainRepo */
        $domainRepo = $this->repos->getRepository(Domain::class);

        $ir = $repo->find($id);
        if (!$ir || $ir->getCompany()?->getId() !== $co->getId()) {
            throw new RuntimeException('Inbound route not found', 404);
        }

        $body = json_decode((string)$r->getBody(), true) ?: [];

        if (array_key_exists('pattern', $body)) {
            $ir->setPattern($this->toStringOrNull($body['pattern']));
        }
        if (array_key_exists('action', $body)) {
            $ir->setAction($this->toStringOrNull($body['action']));
        }
        if (array_key_exists('destination', $body)) {
            $dest = $this->toArrayOrNull($body['destination']);
            if ($dest === null && $body['destination'] !== null) {
                throw new RuntimeException('destination must be an object/array or null', 400);
            }
            $ir->setDestination($dest);
        }
        if (array_key_exists('spam_threshold', $body)) {
            $val = $this->toFloatOrNull($body['spam_threshold']);
            if ($val === null && $body['spam_threshold'] !== null && $body['spam_threshold'] !== '') {
                throw new RuntimeException('spam_threshold must be a number or null', 400);
            }
            $ir->setSpam_threshold($val);
        }
        if (array_key_exists('dkim_required', $body)) {
            $val = $this->toBool01OrNull($body['dkim_required']);
            if ($val === null && $body['dkim_required'] !== null && $body['dkim_required'] !== '') {
                throw new RuntimeException('dkim_required must be boolean-ish (0/1/true/false) or null', 400);
            }
            $ir->setDkim_required($val);
        }
        if (array_key_exists('tls_required', $body)) {
            $val = $this->toBool01OrNull($body['tls_required']);
            if ($val === null && $body['tls_required'] !== null && $body['tls_required'] !== '') {
                throw new RuntimeException('tls_required must be boolean-ish (0/1/true/false) or null', 400);
            }
            $ir->setTls_required($val);
        }
        if (array_key_exists('domainId', $body)) {
            if ($body['domainId'] === null) {
                $ir->setDomain(null);
            } else {
                $did = $this->toIntOrNull($body['domainId']);
                if ($did === null) throw new RuntimeException('domainId must be int or null', 400);
                $dom = $domainRepo->find($did);
                if (!$dom || $dom->getCompany()?->getId() !== $co->getId()) {
                    throw new RuntimeException('Domain not found for this company', 404);
                }
                $ir->setDomain($dom);
            }
        }

        $repo->save($ir);

        return new JsonResponse($this->shape($ir));
    }

    /* ------------------------------- delete ----------------------------- */
    #[Route(methods: 'DELETE', path: '/companies/{hash}/inbound-routes/{id}')]
    public function delete(ServerRequestInterface $r): JsonResponse
    {
        $uid = $this->auth($r);
        $co = $this->company((string)$r->getAttribute('hash'), $uid);
        $id = (int)$r->getAttribute('id');

        /** @var \App\Repository\InboundRouteRepository $repo */
        $repo = $this->repos->getRepository(InboundRoute::class);
        $ir = $repo->find($id);
        if (!$ir || $ir->getCompany()?->getId() !== $co->getId()) {
            throw new RuntimeException('Inbound route not found', 404);
        }

        if (method_exists($repo, 'delete')) $repo->delete($ir);
        elseif (method_exists($repo, 'remove')) $repo->remove($ir);
        else $this->qb->delete('inbound_route')->where('id', '=', $id)->execute();

        return new JsonResponse(null, 204);
    }

}
