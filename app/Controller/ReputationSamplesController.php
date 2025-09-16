<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Company;
use App\Entity\Domain;
use App\Service\ReputationStore;
use MonkeysLegion\Http\Message\JsonResponse;
use MonkeysLegion\Repository\RepositoryFactory;
use MonkeysLegion\Router\Attributes\Route;
use MonkeysLegion\Query\QueryBuilder;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

final class ReputationSamplesController
{
    public function __construct(
        private RepositoryFactory $repos,
        private QueryBuilder      $qb,
        private ReputationStore   $store,
    )
    {
    }

    private function authUser(ServerRequestInterface $r): int
    {
        $uid = (int)$r->getAttribute('user_id', 0);
        if ($uid <= 0) throw new RuntimeException('Unauthorized', 401);
        return $uid;
    }

    private function companyByHashForUser(string $hash, int $userId): Company
    {
        $companyRepo = $this->repos->getRepository(Company::class);
        $company = $companyRepo->findOneBy(['hash' => $hash]);
        if (!$company) throw new RuntimeException('Company not found', 404);
        $belongs = array_filter($company->getUsers() ?? [], fn($u) => $u->getId() === $userId);
        if (empty($belongs)) throw new RuntimeException('Forbidden', 403);
        return $company;
    }

    private function parseRange(ServerRequestInterface $r): array
    {
        parse_str((string)$r->getUri()->getQuery(), $qs);
        $from = isset($qs['from']) ? (string)$qs['from'] : null;
        $to = isset($qs['to']) ? (string)$qs['to'] : null;
        $tz = new \DateTimeZone('UTC');

        if (!$from || !$to) {
            $today = new \DateTimeImmutable('today', $tz);
            $fromI = $today->modify('-29 days');
            $toI = $today;
        } else {
            $fromI = new \DateTimeImmutable($from, $tz);
            $toI = new \DateTimeImmutable($to, $tz);
        }
        if ($fromI > $toI) [$fromI, $toI] = [$toI, $fromI];
        return [$fromI->format('Y-m-d'), $toI->format('Y-m-d')];
    }

    private function assertIngestToken(ServerRequestInterface $r): void
    {
        $expected = $_ENV['REPUTATION_INGEST_TOKEN'] ?? '';
        if ($expected === '') return; // allow if not configured

        // accept both headers
        $got = $r->getHeaderLine('X-Rep-Token');
        if ($got === '') $got = $r->getHeaderLine('X-Rep-Ingest-Token');

        if ($got === '' || !hash_equals($expected, $got)) {
            throw new RuntimeException('Unauthorized (bad ingest token)', 401);
        }
    }

    /** Resolve a company by 64-char hash or by numeric id. */
    private function resolveCompany(?string $hash, ?int $companyId): ?Company
    {
        /** @var \App\Repository\CompanyRepository $companyRepo */
        $companyRepo = $this->repos->getRepository(Company::class);

        if (is_string($hash) && strlen($hash) === 64) {
            $c = $companyRepo->findOneBy(['hash' => $hash]);
            if ($c) return $c;
        }
        if ($companyId && $companyId > 0) {
            /** @var Company|null $c */
            $c = $companyRepo->find($companyId);
            if ($c) return $c;
        }
        return null;
    }

    /** POST /companies/{hash}/reputation/samples
     * Body: { items: [{ domainId, provider, score, notes?, sampledAt? }, ...] }
     */
    /** POST /companies/{hash}/reputation/samples
     * Body: {
     *   companyId?: number,
     *   items: [{
     *      domainId?: number,
     *      domain?: string,         // e.g. "monkeyscms.com"
     *      provider: string,
     *      score: number,           // 0..100
     *      notes?: string,
     *      sampledAt?: ISO-8601
     *   }, ...]
     * }
     */
    #[Route(methods: 'POST', path: '/companies/{hash}/reputation/samples')]
    public function createSamples(ServerRequestInterface $r): JsonResponse
    {
        // Shared-secret (optional but recommended)
        $this->assertIngestToken($r);

        $routeHash = (string)$r->getAttribute('hash');
        $raw = (string)$r->getBody();

        try {
            $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new RuntimeException('Invalid JSON body', 400);
        }

        if (!isset($body['items']) || !is_array($body['items'])) {
            throw new RuntimeException('Body must be: { "items": [ ... ] }', 400);
        }

        $companyIdInBody = isset($body['companyId']) ? (int)$body['companyId'] : null;
        $scopedCompany   = $this->resolveCompany($routeHash, $companyIdInBody);

        /** @var \App\Repository\DomainRepository $domainRepo */
        $domainRepo = $this->repos->getRepository(Domain::class);

        // If we know the company up-front, preload its domains to speed lookups.
        $domainMap = [];
        if ($scopedCompany) {
            $domains = $domainRepo->findBy(['company' => $scopedCompany]);
            foreach ($domains as $d) {
                $domainMap[(int)$d->getId()] = $d;
            }
        }

        $saved  = [];
        $errors = [];

        foreach ($body['items'] as $idx => $it) {
            // --- Normalize fields
            $provider = strtolower(trim((string)($it['provider'] ?? '')));
            $score    = (int)($it['score'] ?? 0);
            $notes    = isset($it['notes']) ? (string)$it['notes'] : null;

            // score clamp 0..100
            if ($score < 0)   $score = 0;
            if ($score > 100) $score = 100;

            // sampledAt
            $sampledAt = null;
            if (!empty($it['sampledAt'])) {
                try {
                    $sampledAt = new \DateTimeImmutable((string)$it['sampledAt'], new \DateTimeZone('UTC'));
                } catch (\Throwable) {
                    $errors[] = ['index' => $idx, 'error' => 'Invalid sampledAt'];
                    continue;
                }
            }

            // --- Resolve domain (by id OR by name)
            $domainEntity = null;

            $domainId = isset($it['domainId']) ? (int)$it['domainId'] : 0;
            $domainNm = isset($it['domain']) ? trim((string)$it['domain']) : '';

            if ($domainId > 0) {
                // If we preloaded for a scoped company, try map first
                if ($scopedCompany && isset($domainMap[$domainId])) {
                    $domainEntity = $domainMap[$domainId];
                } else {
                    /** @var Domain|null $found */
                    $found = $domainRepo->find($domainId);
                    if ($found) $domainEntity = $found;
                }
            } elseif ($domainNm !== '') {
                /** @var Domain|null $foundByName */
                $foundByName = $domainRepo->findOneBy(['domain' => $domainNm]);
                if ($foundByName) $domainEntity = $foundByName;
            }

            if (!$provider || !$domainEntity) {
                $errors[] = ['index' => $idx, 'error' => 'Missing provider or unknown domain'];
                continue;
            }

            // --- If company scope is provided, enforce domain belongs to it
            if ($scopedCompany) {
                $belongsId = (int)($domainEntity->getCompany()?->getId() ?? 0);
                if ($belongsId !== (int)$scopedCompany->getId()) {
                    $errors[] = ['index' => $idx, 'error' => 'Domain does not belong to scoped company'];
                    continue;
                }
            }

            // --- Upsert sample
            try {
                $id = $this->store->upsertDomainSample($domainEntity, $provider, $score, $notes, $sampledAt);
                $saved[] = [
                    'id'       => $id,
                    'domainId' => (int)$domainEntity->getId(),
                    'domain'   => method_exists($domainEntity, 'getDomain') ? $domainEntity->getDomain() : null,
                    'provider' => $provider,
                    'score'    => $score,
                ];
            } catch (\Throwable $e) {
                $errors[] = ['index' => $idx, 'error' => 'Upsert failed: ' . $e->getMessage()];
            }
        }

        $status = !empty($saved) ? 201 : 400;
        return new JsonResponse(['ok' => !empty($saved), 'saved' => $saved, 'errors' => $errors], $status);
    }

    /** GET /companies/{hash}/reputation/history?from=YYYY-MM-DD&to=YYYY-MM-DD */
    #[Route(methods: 'GET', path: '/companies/{hash}/reputation/history')]
    public function history(ServerRequestInterface $r): JsonResponse
    {
        $uid     = $this->authUser($r);
        $company = $this->companyByHashForUser((string)$r->getAttribute('hash'), $uid);
        [$from, $to] = $this->parseRange($r);

        $domainRepo = $this->repos->getRepository(Domain::class);
        $domains    = $domainRepo->findBy(['company' => $company]);

        if (!$domains) {
            return new JsonResponse([
                'company' => ['id' => $company->getId(), 'hash' => $company->getHash(), 'name' => $company->getName()],
                'range'   => compact('from', 'to'),
                'domains' => [],
                'history' => [],
            ]);
        }

        $domainIds = array_map(fn(Domain $d) => $d->getId(), $domains);
        $domainById = [];
        foreach ($domains as $d) {
            $domainById[(int)$d->getId()] = $d->getDomain();
        }

        $rows = (clone $this->qb)
            ->select(['rs.id', 'rs.domain_id', 'rs.provider', 'rs.score', 'rs.sampled_at', 'rs.notes'])
            ->from('reputationsample', 'rs')
            ->whereIn('rs.domain_id', $domainIds)
            ->andWhere('rs.sampled_at', '>=', $from . ' 00:00:00')
            ->andWhere('rs.sampled_at', '<=', $to . ' 23:59:59')
            ->orderBy('rs.sampled_at', 'ASC')   // ascending is nicer for charts
            ->fetchAll();

        $byDomain = [];
        foreach ($rows as $rrow) {
            $did = (int)$rrow['domain_id'];
            $byDomain[$did] ??= [];
            $byDomain[$did][] = [
                'id'         => (int)$rrow['id'],
                'domain'     => $domainById[$did] ?? null, // handy for the client
                'provider'   => $rrow['provider'],
                'score'      => (int)$rrow['score'],
                'sampledAt'  => (new \DateTimeImmutable($rrow['sampled_at']))->format(\DateTimeInterface::ATOM),
                'notes'      => $rrow['notes'],
            ];
        }

        // domain metadata for the client
        $domainMeta = array_map(
            fn($d) => ['id' => $d->getId(), 'name' => $d->getDomain()],
            $domains
        );

        return new JsonResponse([
            'company' => ['id' => $company->getId(), 'hash' => $company->getHash(), 'name' => $company->getName()],
            'range'   => compact('from', 'to'),
            'domains' => $domainMeta,
            'history' => $byDomain,
        ]);
    }

    #[Route(methods: 'GET', path: '/companies/{hash}/reputation/latest')]
    public function latest(ServerRequestInterface $r): JsonResponse
    {
        $uid     = $this->authUser($r);
        $company = $this->companyByHashForUser((string)$r->getAttribute('hash'), $uid);

        $domainRepo = $this->repos->getRepository(Domain::class);
        $domains    = $domainRepo->findBy(['company' => $company]);
        if (!$domains) {
            return new JsonResponse(['company' => ['id'=>$company->getId(),'hash'=>$company->getHash(),'name'=>$company->getName()], 'latest'=>[]]);
        }
        $domainIds = array_map(fn(Domain $d) => $d->getId(), $domains);

        // Latest per (domain_id, provider)
        $rows = (clone $this->qb)
            ->select(['rs1.id','rs1.domain_id','rs1.provider','rs1.score','rs1.sampled_at','rs1.notes'])
            ->from('reputationsample rs1')
            ->innerJoin(
                '(SELECT domain_id, provider, MAX(sampled_at) AS max_ts
              FROM reputationsample
              WHERE domain_id IN (' . implode(',', array_map('intval', $domainIds)) . ')
              GROUP BY domain_id, provider)',
                'latest',
                'latest.domain_id = rs1.domain_id AND latest.provider = rs1.provider AND latest.max_ts = rs1.sampled_at'
            )
            ->orderBy('rs1.domain_id', 'ASC')
            ->orderBy('rs1.provider', 'ASC')
            ->fetchAll();

        $latest = [];
        foreach ($rows as $rrow) {
            $did = (int)$rrow['domain_id'];
            $latest[$did] ??= [];
            $latest[$did][] = [
                'id'        => (int)$rrow['id'],
                'provider'  => $rrow['provider'],
                'score'     => (int)$rrow['score'],
                'sampledAt' => (new \DateTimeImmutable($rrow['sampled_at']))->format(\DateTimeInterface::ATOM),
                'notes'     => $rrow['notes'],
            ];
        }

        return new JsonResponse([
            'company' => ['id'=>$company->getId(),'hash'=>$company->getHash(),'name'=>$company->getName()],
            'latest'  => $latest,
        ]);
    }

}
