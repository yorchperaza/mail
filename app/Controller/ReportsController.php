<?php


declare(strict_types=1);

namespace App\Controller;

use App\Entity\Company;
use App\Entity\Domain;
use App\Entity\DmarcAggregate;
use MonkeysLegion\Http\Message\JsonResponse;
use MonkeysLegion\Query\QueryBuilder;
use MonkeysLegion\Repository\RepositoryFactory;
use MonkeysLegion\Router\Attributes\Route;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

final class ReportsController
{
    public function __construct(
        private RepositoryFactory $repos,
        private QueryBuilder      $qb,
    )
    {
    }

    /* ============================================================
     * Redis connection (phpredis or Predis) and helpers
     * ============================================================ */

    /** @var \Redis|\Predis\Client|null */
    private $redis = null;

    private function redis()
    {
        if ($this->redis) return $this->redis;

        $url = $_ENV['REDIS_URL'] ?? '';
        if ($url === '') throw new RuntimeException('REDIS_URL is not configured', 500);

        $parts = parse_url($url);
        if ($parts === false) throw new RuntimeException('Invalid REDIS_URL', 500);

        $scheme = $parts['scheme'] ?? 'redis';
        $host = $parts['host'] ?? '127.0.0.1';
        $port = (int)($parts['port'] ?? 6379);
        $pass = $parts['pass'] ?? null;
        $db = 0;
        if (isset($parts['path']) && $parts['path'] !== '') {
            // /0, /1, ...
            $db = (int)ltrim($parts['path'], '/');
        }

        // Try phpredis first
        if (class_exists(\Redis::class)) {
            $r = new \Redis();
            // Use persistent connection for lower overhead in FPM
            $r->pconnect($host, $port, 1.5);
            if ($pass) $r->auth($pass);
            if ($db) $r->select($db);
            $this->redis = $r;
            return $this->redis;
        }

        // Fallback: Predis
        if (class_exists(\Predis\Client::class)) {
            $this->redis = new \Predis\Client($url, [
                'timeout' => 1.5,
                'read_write_timeout' => 1.5,
            ]);
            // A ping also validates auth/db selection
            $this->redis->ping();
            return $this->redis;
        }

        throw new RuntimeException('Neither phpredis nor Predis is available', 500);
    }

    private function rGetInt(string $key): int
    {
        try {
            $r = $this->redis()->get($key);
            if ($r === false || $r === null) return 0;
            return (int)$r;
        } catch (\Throwable) {
            return 0;
        }
    }

    /** MGET for many keys; returns array<int> aligned to keys order. */
    private function rMGetInts(array $keys): array
    {
        if ($keys === []) return [];
        try {
            // phpredis returns array of strings|null; Predis returns the same.
            $vals = $this->redis()->mGet($keys);
            if (!is_array($vals)) return array_fill(0, count($keys), 0);
            return array_map(static fn($v) => (int)($v ?? 0), $vals);
        } catch (\Throwable) {
            return array_fill(0, count($keys), 0);
        }
    }

    /** Inclusive date sequence (UTC) formatted Y-m-d */
    private function eachDate(string $from, string $to): array
    {
        $out = [];
        $s = new \DateTimeImmutable($from, new \DateTimeZone('UTC'));
        $e = new \DateTimeImmutable($to, new \DateTimeZone('UTC'));
        if ($s > $e) return $out;
        for ($d = $s; $d <= $e; $d = $d->modify('+1 day')) {
            $out[] = $d->format('Y-m-d');
        }
        return $out;
    }

    /**
     * Build daily keys for a metric and read them via MGET.
     * Returns ['series' => ['YYYY-MM-DD' => int, ...], 'total' => int]
     */
    private function readDailySeries(int $companyId, string $metricKeyPattern, string $from, string $to): array
    {
        // metricKeyPattern example: 'mm:stats:company:%d:sent:%s'
        $days = $this->eachDate($from, $to);
        $keys = array_map(fn($day) => sprintf($metricKeyPattern, $companyId, $day), $days);
        $vals = $this->rMGetInts($keys);

        $series = [];
        $total = 0;
        foreach ($days as $i => $day) {
            $v = $vals[$i] ?? 0;
            $series[$day] = $v;
            $total += $v;
        }
        return ['series' => $series, 'total' => $total];
    }

    /* ============================================================
     * Common guards
     * ============================================================ */

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
        $to = isset($qs['to']) ? (string)$qs['to'] : null;

        $tz = new \DateTimeZone('UTC');

        if (!$from || !$to) {
            // Default: last 30 days including today (UTC)
            $today = (new \DateTimeImmutable('today', $tz));
            $fromI = $today->modify('-29 days');
            $toI = $today;
        } else {
            $fromI = new \DateTimeImmutable($from, $tz);
            $toI = new \DateTimeImmutable($to, $tz);
        }

        if ($fromI > $toI) {
            [$fromI, $toI] = [$toI, $fromI];
        }

        return [$fromI->format('Y-m-d'), $toI->format('Y-m-d')];
    }

    /* ============================================================
     * 1) Health / Redis info
     * ============================================================ */

    /**
     * @throws \JsonException
     */
    #[Route(methods: 'GET', path: '/reports/redis-info')]
    public function redisInfo(ServerRequestInterface $r): JsonResponse
    {
        $this->authUser($r);

        $info = [];
        try {
            $redis = $this->redis();
            // phpredis: info(); Predis: info() returns array grouped by section
            $raw = $redis->info();
            if (is_array($raw)) {
                // Normalize a few useful fields
                $flat = [];
                $iter = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($raw));
                foreach ($iter as $k => $v) {
                    $flat[(string)$k] = $v;
                }
                foreach ([
                             'redis_version', 'role', 'connected_clients', 'used_memory_human',
                             'total_commands_processed', 'total_connections_received', 'uptime_in_seconds',
                             'instantaneous_ops_per_sec', 'aof_enabled', 'rdb_last_save_time'
                         ] as $k) {
                    if (isset($flat[$k])) $info[$k] = $flat[$k];
                }
            }
            // quick ping
            $pong = $redis->ping();
            $info['ping'] = is_string($pong) ? $pong : 'PONG';
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }

        return new JsonResponse(['ok' => true, 'info' => $info]);
    }

    /* ============================================================
     * 2) Company overview from Redis (totals + daily series)
     *    GET /companies/{hash}/reports/overview?from=YYYY-MM-DD&to=YYYY-MM-DD
     * ============================================================ */

    #[Route(methods: 'GET', path: '/companies/{hash}/reports/overview')]
    public function companyOverview(ServerRequestInterface $r): JsonResponse
    {
        $uid = $this->authUser($r);
        $hash = (string)$r->getAttribute('hash');
        if (!is_string($hash) || strlen($hash) !== 64) throw new RuntimeException('Invalid company identifier', 400);

        $company = $this->companyByHashForUser($hash, $uid);
        $companyId = $company->getId();

        [$from, $to] = $this->parseRange($r);

        // Centralized metric key patterns — tweak here if your producer uses different names.
        $metricKeys = [
            'sent' => 'mm:stats:company:%d:sent:%s',
            'delivered' => 'mm:stats:company:%d:delivered:%s',
            'opened' => 'mm:stats:company:%d:opened:%s',
            'bounced' => 'mm:stats:company:%d:bounced:%s',
            'spam' => 'mm:stats:company:%d:spam:%s',
            'rejected' => 'mm:stats:company:%d:rejected:%s',
        ];

        $series = [];
        $totals = [];

        foreach ($metricKeys as $name => $pattern) {
            $res = $this->readDailySeries($companyId, $pattern, $from, $to);
            $series[$name] = $res['series'];
            $totals[$name] = $res['total'];
        }

        // Basic derived rates (guard division by zero)
        $sent = max(1, (int)$totals['sent']);
        $delv = (int)$totals['delivered'];
        $open = (int)$totals['opened'];
        $bnc = (int)$totals['bounced'];

        $rates = [
            'deliveryRate' => $sent ? round(($delv / $sent) * 100, 2) : 0.0,
            'openRate' => $delv ? round(($open / max(1, $delv)) * 100, 2) : 0.0,
            'bounceRate' => $sent ? round(($bnc / $sent) * 100, 2) : 0.0,
        ];

        return new JsonResponse([
            'company' => [
                'id' => $companyId,
                'hash' => $company->getHash(),
                'name' => $company->getName(),
            ],
            'range' => compact('from', 'to'),
            'totals' => $totals,
            'rates' => $rates,
            'series' => $series,
        ]);
    }

    /* ============================================================
     * 3) DMARC aggregates for a company’s domains
     *    GET /companies/{hash}/reports/dmarc?from=YYYY-MM-DD&to=YYYY-MM-DD
     * ============================================================ */

    /**
     * @throws \DateMalformedStringException
     * @throws \JsonException
     */
    #[Route(methods: 'GET', path: '/companies/{hash}/reports/dmarc')]
    public function companyDmarc(ServerRequestInterface $r): JsonResponse
    {
        $uid = $this->authUser($r);
        $hash = (string)$r->getAttribute('hash');
        if (!is_string($hash) || strlen($hash) !== 64) throw new RuntimeException('Invalid company identifier', 400);

        $company = $this->companyByHashForUser($hash, $uid);
        [$from, $to] = $this->parseRange($r);

        /** @var \App\Repository\DomainRepository $domainRepo */
        $domainRepo = $this->repos->getRepository(Domain::class);

        // All domains for this company
        $domains = $domainRepo->findBy(['company' => $company]);
        error_log('Found ' . count($domains) . ' domains for company ' . $company->getId());
        $domainIds = array_map(fn(Domain $d) => $d->getId(), $domains);
        if (empty($domainIds)) {
            return new JsonResponse([
                'company' => ['id' => $company->getId(), 'hash' => $company->getHash(), 'name' => $company->getName()],
                'range' => compact('from', 'to'),
                'domains' => [],
                'reports' => [],
            ]);
        }
        error_log('Found1 ' . count($domainIds) . ' domains for company ' . $company->getId());
        // Query DMARC aggregates by domain_id IN (...) and overlap with date range
        // date_start/date_end in your entity are report window bounds.
        $qb = (clone $this->qb)
            ->select(
            // NOTE: select() accepts string or array; we pass a single string so we can backtick+alias safely
                "`da`.`id`,
         `da`.`domain_id`,
         `da`.`org_name`,
         `da`.`report_id`,
         `da`.`date_start`,
         `da`.`date_end`,
         `da`.`adkim`,
         `da`.`aspf`,
         `da`.`p`   AS `policy_p`,
         `da`.`sp`  AS `policy_sp`,
         `da`.`pct`,
         `da`.`rows` AS `rows_json`,
         `da`.`received_at`"
            )
            // Quote the table name too (your preflight will still resolve pluralization if needed)
            ->from('`dmarcaggregate`', 'da')
            // whereIn does placeholders for values; we provide a quoted column here
            ->whereIn('`da`.`domain_id`', $domainIds)
            // These use bound params under the hood; keep the column quoted
            ->andWhere('`da`.`date_end`', '>=', $from . ' 00:00:00')
            ->andWhere('`da`.`date_start`', '<=', $to . ' 23:59:59')
            ->orderBy('`da`.`date_start`', 'ASC');

        try {
            $rows = $qb->fetchAll();
        } catch (\Throwable $e) {
            error_log('[DMARC SQL ERROR] ' . $e->getMessage());
            error_log('[DMARC SQL] ' . $qb->toDebugSql());
            throw new \RuntimeException('Failed to fetch DMARC reports', 500);
        }
        error_log('Found2 ' . count($rows) . ' domains for company ' . $company->getId());
        // Group by domain_id and normalize payload
        $byDomain = [];
        foreach ($rows as $row) {
            $did = (int)($row['domain_id'] ?? 0);
            $byDomain[$did] ??= [];
            $byDomain[$did][] = [
                'id'       => (int)$row['id'],
                'org'      => $row['org_name'] ?? null,
                'reportId' => $row['report_id'] ?? null,
                'window'   => [
                    'start' => !empty($row['date_start']) ? (new \DateTimeImmutable($row['date_start']))->format(\DateTimeInterface::ATOM) : null,
                    'end'   => !empty($row['date_end'])   ? (new \DateTimeImmutable($row['date_end']))->format(\DateTimeInterface::ATOM)   : null,
                ],
                'policy'   => [
                    'adkim' => $row['adkim'] ?? null,
                    'aspf'  => $row['aspf']  ?? null,
                    'p'     => $row['policy_p']  ?? null,   // <- aliased
                    'sp'    => $row['policy_sp'] ?? null,   // <- aliased
                    'pct'   => isset($row['pct']) ? (int)$row['pct'] : null,
                ],
                'rows'       => is_string($row['rows_json'] ?? null) ? json_decode($row['rows_json'], true) : ($row['rows_json'] ?? null),
                'receivedAt' => !empty($row['received_at']) ? (new \DateTimeImmutable($row['received_at']))->format(\DateTimeInterface::ATOM) : null,
            ];
        }

        error_log('Found3 ' . count($byDomain) . ' domains for company ' . $company->getId());

        // Attach domain names
        $domainMap = [];
        foreach ($domains as $d) {
            $domainMap[$d->getId()] = [
                'id' => $d->getId(),
                'name' => $d->getDomain(), // adjust getter if different
            ];
        }
        error_log('Found4 ' . count($byDomain) . ' domains for company ' . $company->getId());
        return new JsonResponse([
            'company' => [
                'id' => $company->getId(),
                'hash' => $company->getHash(),
                'name' => $company->getName(),
            ],
            'range' => compact('from', 'to'),
            'domains' => array_values($domainMap),
            'reports' => $byDomain,
        ]);
    }

    /* ============================================================
     * 4) OPTIONAL: Per-domain overview (same metrics, but scoped)
     *    GET /companies/{hash}/domains/{domainId}/reports/overview
     *    Uses the same Redis scheme with domain dimension if you keep one.
     * ============================================================ */

    #[Route(methods: 'GET', path: '/companies/{hash}/domains/{domainId}/reports/overview')]
    public function domainOverview(ServerRequestInterface $r): JsonResponse
    {
        $uid = $this->authUser($r);
        $hash = (string)$r->getAttribute('hash');
        $domainId = (int)$r->getAttribute('domainId');

        $company = $this->companyByHashForUser($hash, $uid);

        /** @var \App\Repository\DomainRepository $domainRepo */
        $domainRepo = $this->repos->getRepository(Domain::class);
        /** @var Domain|null $domain */
        $domain = $domainRepo->find($domainId);
        if (!$domain || (int)$domain->getCompany()?->getId() !== (int)$company->getId()) {
            throw new RuntimeException('Domain not found', 404);
        }

        [$from, $to] = $this->parseRange($r);

        // If you also store domain-scoped keys, define them here.
        $metricKeys = [
            'sent' => 'mm:stats:domain:%d:sent:%s',
            'delivered' => 'mm:stats:domain:%d:delivered:%s',
            'opened' => 'mm:stats:domain:%d:opened:%s',
            'bounced' => 'mm:stats:domain:%d:bounced:%s',
            'spam' => 'mm:stats:domain:%d:spam:%s',
            'rejected' => 'mm:stats:domain:%d:rejected:%s',
        ];

        $series = [];
        $totals = [];

        foreach ($metricKeys as $name => $pattern) {
            $res = $this->readDailySeries($domainId, $pattern, $from, $to);
            $series[$name] = $res['series'];
            $totals[$name] = $res['total'];
        }

        $sent = max(1, (int)($totals['sent'] ?? 0));
        $delv = (int)($totals['delivered'] ?? 0);
        $open = (int)($totals['opened'] ?? 0);
        $bnc = (int)($totals['bounced'] ?? 0);

        $rates = [
            'deliveryRate' => $sent ? round(($delv / $sent) * 100, 2) : 0.0,
            'openRate' => $delv ? round(($open / max(1, $delv)) * 100, 2) : 0.0,
            'bounceRate' => $sent ? round(($bnc / $sent) * 100, 2) : 0.0,
        ];

        return new JsonResponse([
            'company' => ['id' => $company->getId(), 'hash' => $company->getHash(), 'name' => $company->getName()],
            'domain' => ['id' => $domain->getId(), 'name' => $domain->getName()],
            'range' => compact('from', 'to'),
            'totals' => $totals,
            'rates' => $rates,
            'series' => $series,
        ]);
    }
}
