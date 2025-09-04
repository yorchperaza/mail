<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Company;
use App\Entity\Domain;
use MonkeysLegion\Http\Message\JsonResponse;
use MonkeysLegion\Query\QueryBuilder;
use MonkeysLegion\Repository\RepositoryFactory;
use MonkeysLegion\Router\Attributes\Route;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use App\Service\WebhookDispatcher; // <-- NEW

final class TlsRptController
{
    public function __construct(
        private RepositoryFactory $repos,
        private QueryBuilder      $qb,
        private WebhookDispatcher $dispatcher, // <-- NEW
    ) {}

    private function authUser(ServerRequestInterface $r): int
    {
        $uid = (int)$r->getAttribute('user_id', 0);
        if ($uid <= 0) throw new RuntimeException('Unauthorized', 401);
        return $uid;
    }

    private function companyByHashForUser(string $hash, int $userId): Company
    {
        /** @var \App\Repository\CompanyRepository $companyRepo */
        $companyRepo = $this->repos->getRepository(Company::class);
        /** @var Company|null $company */
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
        $to   = isset($qs['to'])   ? (string)$qs['to']   : null;

        $tz = new \DateTimeZone('UTC');
        if (!$from || !$to) {
            $today = (new \DateTimeImmutable('today', $tz));
            $fromI = $today->modify('-29 days');
            $toI   = $today;
        } else {
            $fromI = new \DateTimeImmutable($from, $tz);
            $toI   = new \DateTimeImmutable($to,   $tz);
        }
        if ($fromI > $toI) [$fromI, $toI] = [$toI, $fromI];
        return [$fromI->format('Y-m-d'), $toI->format('Y-m-d')];
    }

    /** Normalize RFC 8460 TLS-RPT into a single row */
    private function normalizeTlsRpt(array $payload): array
    {
        $org       = (string)($payload['organization-name'] ?? $payload['organization'] ?? '');
        $reportId  = (string)($payload['report-id'] ?? '');
        $contact   = (string)($payload['contact-info'] ?? '');

        $startIso  = (string)($payload['date-range']['start-datetime'] ?? '');
        $endIso    = (string)($payload['date-range']['end-datetime']   ?? '');

        $policies  = is_array($payload['policies'] ?? null) ? $payload['policies'] : [];
        $policyDomain = null;
        $succ = 0;
        $fail = 0;
        $allFailures = [];

        foreach ($policies as $p) {
            $summary = (array)($p['summary'] ?? []);
            $succ += (int)($summary['total-successful-session-count'] ?? 0);
            $fail += (int)($summary['total-failure-session-count'] ?? 0);

            if (!$policyDomain) {
                $pd = $p['policy']['policy-domain'] ?? $p['policy']['domain'] ?? null;
                if (is_string($pd) && $pd !== '') $policyDomain = $pd;
            }

            if (!empty($p['failure-details']) && is_array($p['failure-details'])) {
                $allFailures = array_merge($allFailures, $p['failure-details']);
            }
        }

        if (!$policyDomain) {
            foreach ($policies as $p) {
                $pd = $p['policy']['policy-domain'] ?? $p['policy']['domain'] ?? null;
                if (is_string($pd) && $pd !== '') { $policyDomain = $pd; break; }
            }
        }

        $start = $startIso ? new \DateTimeImmutable($startIso) : null;
        $end   = $endIso   ? new \DateTimeImmutable($endIso)   : null;

        return [
            'policy_domain'  => $policyDomain,
            'reporter'       => $org ?: $contact ?: null,
            'report_id'      => $reportId ?: null,
            'date_start'     => $start,
            'date_end'       => $end,
            'success_count'  => $succ,
            'failure_count'  => $fail,
            'details'        => $allFailures,
        ];
    }

    /**
     * POST /ingest/tlsrpt
     * Body: TLS-RPT JSON or { payload: {...}, receivedAt?: ISO }
     */
    #[Route(methods: 'POST', path: '/ingest/tlsrpt')]
    public function ingestTlsRpt(ServerRequestInterface $r): JsonResponse
    {
        $this->authUser($r);

        $raw = (string)$r->getBody();
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return new JsonResponse(['ok' => false, 'error' => 'Invalid JSON'], 400);
        }

        $payload = isset($json['payload']) && is_array($json['payload']) ? $json['payload'] : $json;
        $receivedAtIso = isset($json['receivedAt']) ? (string)$json['receivedAt'] : (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        $norm = $this->normalizeTlsRpt($payload);
        if (empty($norm['policy_domain'])) {
            return new JsonResponse(['ok' => false, 'error' => 'policy-domain not found in report'], 400);
        }

        /** @var \App\Repository\DomainRepository $domainRepo */
        $domainRepo = $this->repos->getRepository(Domain::class);
        /** @var Domain|null $domain */
        $domain = $domainRepo->findOneBy(['name' => $norm['policy_domain']]); // adjust if your field is different
        if (!$domain) {
            return new JsonResponse(['ok' => false, 'error' => 'Domain not found: ' . $norm['policy_domain']], 404);
        }

        // Upsert by (domain_id, report_id)
        $fields = [
            'domain_id'     => $domain->getId(),
            'reporter'      => $norm['reporter'],
            'report_id'     => $norm['report_id'],
            'date_start'    => $norm['date_start']?->format('Y-m-d H:i:s'),
            'date_end'      => $norm['date_end']?->format('Y-m-d H:i:s'),
            'success_count' => (int)$norm['success_count'],
            'failure_count' => (int)$norm['failure_count'],
            'details'       => json_encode($norm['details'] ?? []),
            'received_at'   => (new \DateTimeImmutable($receivedAtIso))->format('Y-m-d H:i:s'),
        ];

        try {
            $cols         = array_keys($fields);
            $insertCols   = implode(', ', $cols);
            $placeholders = implode(', ', array_map(fn($c) => ':' . $c, $cols));
            $updates      = implode(', ', array_map(fn($c) => "$c = VALUES($c)", array_diff($cols, ['domain_id','report_id'])));

            $sql = "INSERT INTO tlsrptreport ($insertCols) VALUES ($placeholders)
                    ON DUPLICATE KEY UPDATE $updates";

            $pdo  = $this->qb->pdo();
            $stmt = $pdo->prepare($sql);
            $params = [];
            foreach ($fields as $k => $v) $params[":$k"] = $v;
            $ok = $stmt->execute($params);
            if (!$ok) {
                [$state, $code, $msg] = $stmt->errorInfo();
                throw new \RuntimeException("TLS-RPT upsert failed: $state/$code – $msg");
            }

            // Determine id (insert vs duplicate)
            $rowId = (int)$pdo->lastInsertId();
            if ($rowId === 0) {
                $stmt2 = $pdo->prepare("SELECT id FROM tlsrptreport WHERE domain_id = :did AND report_id = :rid LIMIT 1");
                $stmt2->bindValue(':did', $fields['domain_id'], \PDO::PARAM_INT);
                $stmt2->bindValue(':rid', $fields['report_id'], \PDO::PARAM_STR);
                $stmt2->execute();
                $rowId = (int)($stmt2->fetchColumn() ?: 0);
            }

            // Dispatch webhook event to the company's subscribers
            $companyId = (int)($domain->getCompany()?->getId() ?? 0);
            if ($companyId > 0) {
                $this->dispatcher->dispatch(
                    $companyId,
                    'tlsrpt.received',
                    [
                        'tlsrptId'   => $rowId ?: null,
                        'domainId'   => $domain->getId(),
                        'domain'     => method_exists($domain, 'getDomain') ? $domain->getDomain() : ($norm['policy_domain'] ?? null),
                        'reportId'   => $norm['report_id'],
                        'reporter'   => $norm['reporter'],
                        'window'     => [
                            'start' => $norm['date_start']?->format(\DateTimeInterface::ATOM),
                            'end'   => $norm['date_end']?->format(\DateTimeInterface::ATOM),
                        ],
                        'summary'    => [
                            'success' => (int)$norm['success_count'],
                            'failure' => (int)$norm['failure_count'],
                        ],
                        'receivedAt' => (new \DateTimeImmutable($receivedAtIso))->format(\DateTimeInterface::ATOM),
                    ],
                    $rowId ?: null
                );
            }

            return new JsonResponse([
                'ok'         => true,
                'tlsrptId'   => $rowId,
                'domainId'   => $domain->getId(),
                'dispatched' => $companyId > 0,
            ]);

        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /companies/{hash}/reports/tlsrpt?from=YYYY-MM-DD&to=YYYY-MM-DD
     */
    #[Route(methods: 'GET', path: '/companies/{hash}/reports/tlsrpt')]
    public function companyTlsRpt(ServerRequestInterface $r): JsonResponse
    {
        $uid  = $this->authUser($r);
        $hash = (string)$r->getAttribute('hash');
        if (!is_string($hash) || strlen($hash) !== 64) throw new RuntimeException('Invalid company identifier', 400);

        $company = $this->companyByHashForUser($hash, $uid);
        [$from, $to] = $this->parseRange($r);

        /** @var \App\Repository\DomainRepository $domainRepo */
        $domainRepo = $this->repos->getRepository(Domain::class);
        $domains = $domainRepo->findBy(['company' => $company]);

        $domainIds = array_map(fn(Domain $d) => $d->getId(), $domains);
        if (!$domainIds) {
            return new JsonResponse([
                'company' => ['id' => $company->getId(), 'hash' => $company->getHash(), 'name' => $company->getName()],
                'range'   => compact('from', 'to'),
                'domains' => [],
                'reports' => [],
            ]);
        }

        $qb = (clone $this->qb)
            ->select([
                'tr.id','tr.domain_id','tr.reporter','tr.report_id',
                'tr.date_start','tr.date_end','tr.success_count','tr.failure_count',
                'tr.details','tr.received_at'
            ])
            ->from('tlsrptreport', 'tr')
            ->whereIn('tr.domain_id', $domainIds)
            ->andWhere('tr.date_end',   '>=', $from . ' 00:00:00')
            ->andWhere('tr.date_start', '<=', $to   . ' 23:59:59')
            ->orderBy('tr.date_start', 'ASC');

        $rows = $qb->fetchAll();

        $byDomain = [];
        foreach ($rows as $row) {
            $did = (int)$row['domain_id'];
            $byDomain[$did] ??= [];
            $byDomain[$did][] = [
                'id'        => (int)$row['id'],
                'org'       => $row['reporter'] ?? null,
                'reportId'  => $row['report_id'] ?? null,
                'window'    => [
                    'start' => $row['date_start'] ? (new \DateTimeImmutable($row['date_start']))->format(\DateTimeInterface::ATOM) : null,
                    'end'   => $row['date_end']   ? (new \DateTimeImmutable($row['date_end']))->format(\DateTimeInterface::ATOM)   : null,
                ],
                'summary'   => [
                    'success' => (int)($row['success_count'] ?? 0),
                    'failure' => (int)($row['failure_count'] ?? 0),
                ],
                'details'   => is_string($row['details']) ? json_decode($row['details'], true) : $row['details'],
                'receivedAt'=> $row['received_at'] ? (new \DateTimeImmutable($row['received_at']))->format(\DateTimeInterface::ATOM) : null,
            ];
        }

        $domainMap = [];
        foreach ($domains as $d) {
            $domainMap[] = ['id' => $d->getId(), 'name' => $d->getDomain()];
        }

        return new JsonResponse([
            'company' => ['id' => $company->getId(), 'hash' => $company->getHash(), 'name' => $company->getName()],
            'range'   => compact('from', 'to'),
            'domains' => $domainMap,
            'reports' => $byDomain,
        ]);
    }
}
