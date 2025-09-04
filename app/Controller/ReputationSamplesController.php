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

    /** POST /companies/{hash}/reputation/samples
     * Body: { items: [{ domainId, provider, score, notes?, sampledAt? }, ...] }
     */
    #[Route(methods: 'POST', path: '/companies/{hash}/reputation/samples')]
    public function createSamples(ServerRequestInterface $r): JsonResponse
    {
        $uid = $this->authUser($r);
        $company = $this->companyByHashForUser((string)$r->getAttribute('hash'), $uid);

        $raw = (string)$r->getBody();
        $body = json_decode($raw, true);
        if (!is_array($body) || !isset($body['items']) || !is_array($body['items'])) {
            throw new RuntimeException('Invalid JSON body', 400);
        }

        // Load company domains into a map to validate ownership
        $domainRepo = $this->repos->getRepository(Domain::class);
        $domains = $domainRepo->findBy(['company' => $company]);
        $domainMap = [];
        foreach ($domains as $d) $domainMap[$d->getId()] = $d;

        $saved = [];
        foreach ($body['items'] as $it) {
            $domainId = (int)($it['domainId'] ?? 0);
            $provider = (string)($it['provider'] ?? '');
            $score = (int)($it['score'] ?? 0);
            $notes = isset($it['notes']) ? (string)$it['notes'] : null;
            $sampledAt = isset($it['sampledAt']) ? new \DateTimeImmutable((string)$it['sampledAt'], new \DateTimeZone('UTC')) : null;

            if ($domainId <= 0 || $provider === '') continue;
            if (!isset($domainMap[$domainId])) {
                // skip or error: domain not in this company
                continue;
            }

            $id = $this->store->upsertDomainSample($domainMap[$domainId], $provider, $score, $notes, $sampledAt);
            $saved[] = ['domainId' => $domainId, 'id' => $id, 'provider' => $provider, 'score' => $score];
        }

        return new JsonResponse(['ok' => true, 'saved' => $saved], 201);
    }

    /** GET /companies/{hash}/reputation/history?from=YYYY-MM-DD&to=YYYY-MM-DD */
    #[Route(methods: 'GET', path: '/companies/{hash}/reputation/history')]
    public function history(ServerRequestInterface $r): JsonResponse
    {
        $uid = $this->authUser($r);
        $company = $this->companyByHashForUser((string)$r->getAttribute('hash'), $uid);
        [$from, $to] = $this->parseRange($r);

        $domainRepo = $this->repos->getRepository(Domain::class);
        $domains = $domainRepo->findBy(['company' => $company]);
        $domainIds = array_map(fn(Domain $d) => $d->getId(), $domains);
        if (!$domainIds) {
            return new JsonResponse(['company' => ['id' => $company->getId(), 'hash' => $company->getHash(), 'name' => $company->getName()], 'range' => compact('from', 'to'), 'history' => []]);
        }

        $rows = (clone $this->qb)
            ->select(['rs.id', 'rs.domain_id', 'rs.provider', 'rs.score', 'rs.sampled_at', 'rs.notes'])
            ->from('reputationsample', 'rs')
            ->whereIn('rs.domain_id', $domainIds)
            ->andWhere('rs.sampled_at', '>=', $from . ' 00:00:00')
            ->andWhere('rs.sampled_at', '<=', $to . ' 23:59:59')
            ->orderBy('rs.sampled_at', 'DESC')
            ->fetchAll();

        $byDomain = [];
        foreach ($rows as $rrow) {
            $did = (int)$rrow['domain_id'];
            $byDomain[$did] ??= [];
            $byDomain[$did][] = [
                'id' => (int)$rrow['id'],
                'provider' => $rrow['provider'],
                'score' => (int)$rrow['score'],
                'sampledAt' => (new \DateTimeImmutable($rrow['sampled_at']))->format(\DateTimeInterface::ATOM),
                'notes' => $rrow['notes'],
            ];
        }

        // domain metadata for the client
        $domainMeta = array_map(fn($d) => ['id' => $d->getId(), 'name' => $d->getDomain()], $domains);

        return new JsonResponse([
            'company' => ['id' => $company->getId(), 'hash' => $company->getHash(), 'name' => $company->getName()],
            'range' => compact('from', 'to'),
            'domains' => $domainMeta,
            'history' => $byDomain,
        ]);
    }
}
