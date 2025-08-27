<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\IpPool;
use App\Entity\Company;
use App\Service\CompanyResolver;
use MonkeysLegion\Http\Message\JsonResponse;
use MonkeysLegion\Repository\RepositoryFactory;
use MonkeysLegion\Router\Attributes\Route;
use MonkeysLegion\Query\QueryBuilder;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use DateTimeImmutable;

final class IpPoolController
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

    /** Uniform JSON shape for an IpPool row */
    private function shape(IpPool $p): array {
        $company = $p->getCompany();
        return [
            'id'               => $p->getId(),
            'name'             => $p->getName(),
            'ips'              => $p->getIps(),
            'reputation_score' => $p->getReputation_score(),
            'warmup_state'     => $p->getWarmup_state(),
            'created_at'       => $p->getCreated_at()?->format(DATE_ATOM),
            'company'          => $company ? [
                'id'   => $company->getId(),
                'name' => $company->getName(),
            ] : null,
        ];
    }

    /** ----- helpers for safe coercion / validation ----- */
    private function toIntOrNull(mixed $v): ?int {
        if ($v === null) return null;
        if (is_string($v)) $v = trim($v);
        if ($v === '' || $v === 'null') return null;
        if (is_numeric($v)) return (int)$v;
        return null;
    }
    private function toStringOrNull(mixed $v): ?string {
        if ($v === null) return null;
        $s = is_string($v) ? trim($v) : (string)$v;
        return $s === '' || $s === 'null' ? null : $s;
    }
    private function toDateTimeOrNull(mixed $v): ?DateTimeImmutable {
        if ($v === null) return null;
        if (is_string($v)) {
            $v = trim($v);
            if ($v === '' || $v === 'null') return null;
            try { return new DateTimeImmutable($v); } catch (\Throwable) { return null; }
        }
        return null;
    }
    /** Accepts array of strings; filters to valid IPv4/IPv6; returns null if empty input */
    private function toIpArrayOrNull(mixed $v): ?array {
        if ($v === null) return null;
        if (!is_array($v)) return null;
        $out = [];
        foreach ($v as $ip) {
            if (!is_string($ip)) continue;
            $ip = trim($ip);
            if ($ip === '') continue;
            if (filter_var($ip, FILTER_VALIDATE_IP)) $out[] = $ip;
        }
        return $out;
    }

    /** @return array{0:array<int,IpPool>,1:int} [rows,total] after in-memory filters */
    private function applyFilters(array $rows, array $q): array {
        $search      = trim((string)($q['search'] ?? ''));           // name/ip/company contains (also id substrings)
        $warmupState = trim((string)($q['warmupState'] ?? ''));       // exact match
        $companyId   = (string)($q['companyId'] ?? '');
        $minRep      = (string)($q['minReputation'] ?? '');
        $maxRep      = (string)($q['maxReputation'] ?? '');
        $createdFrom = trim((string)($q['createdFrom'] ?? ''));       // ISO8601/date
        $createdTo   = trim((string)($q['createdTo'] ?? ''));

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $rows = array_values(array_filter($rows, static function (IpPool $p) use ($needle) {
                // Pool name
                if (str_contains(mb_strtolower((string)($p->getName() ?? '')), $needle)) return true;

                // Any IP in the pool
                foreach (($p->getIps() ?? []) as $ip) {
                    if (str_contains(mb_strtolower((string)$ip), $needle)) return true;
                }

                // Company name
                $company = $p->getCompany();
                if ($company && str_contains(mb_strtolower((string)($company->getName() ?? '')), $needle)) return true;

                // (Bonus) match by IDs if the search looks like a number or substring
                if (str_contains((string)$p->getId(), $needle)) return true;
                if ($company && str_contains((string)$company->getId(), $needle)) return true;

                return false;
            }));
        }

        if ($warmupState !== '') {
            $rows = array_values(array_filter($rows, static function (IpPool $p) use ($warmupState) {
                return (string)($p->getWarmup_state() ?? '') === $warmupState;
            }));
        }

        if ($companyId !== '') {
            $cid = (int)$companyId;
            $rows = array_values(array_filter($rows, static function (IpPool $p) use ($cid) {
                return $p->getCompany()?->getId() === $cid;
            }));
        }

        if ($minRep !== '' || $maxRep !== '') {
            $min = $minRep === '' ? null : (int)$minRep;
            $max = $maxRep === '' ? null : (int)$maxRep;
            $rows = array_values(array_filter($rows, static function (IpPool $p) use ($min, $max) {
                $r = $p->getReputation_score();
                if ($r === null) return false;
                if ($min !== null && $r < $min) return false;
                if ($max !== null && $r > $max) return false;
                return true;
            }));
        }

        if ($createdFrom !== '' || $createdTo !== '') {
            $from = $createdFrom !== '' ? @new DateTimeImmutable($createdFrom) : null;
            $to   = $createdTo   !== '' ? @new DateTimeImmutable($createdTo)   : null;
            $rows = array_values(array_filter($rows, static function (IpPool $p) use ($from, $to) {
                $ts = $p->getCreated_at();
                if (!$ts) return false;
                if ($from && $ts < $from) return false;
                if ($to && $ts > $to) return false;
                return true;
            }));
        }

        return [$rows, count($rows)];
    }

    /* -------------------------------- list -------------------------------- */

    /**
     * Query params:
     *   search? (pool name / ip / company name / id substrings),
     *   warmupState?, companyId?, minReputation?, maxReputation?,
     *   createdFrom?, createdTo? (ISO8601), page?=1, perPage?=25 (max 200)
     */
    #[Route(methods: 'GET', path: '/ippools')]
    public function list(ServerRequestInterface $r): JsonResponse {
        $this->auth($r);

        /** @var \App\Repository\IpPoolRepository $repo */
        $repo = $this->repos->getRepository(IpPool::class);

        $q       = $r->getQueryParams();
        $page    = max(1, (int)($q['page'] ?? 1));
        $perPage = max(1, min(200, (int)($q['perPage'] ?? 25)));

        $rows = $repo->findBy([]); // all
        [$filtered, $total] = $this->applyFilters($rows, $q);
        $slice = array_slice($filtered, ($page - 1) * $perPage, $perPage);

        return new JsonResponse([
            'meta'  => [
                'page'       => $page,
                'perPage'    => $perPage,
                'total'      => $total,
                'totalPages' => (int)ceil($total / $perPage),
            ],
            'items' => array_map(fn(IpPool $p) => $this->shape($p), $slice),
        ]);
    }

    /* ------------------------------ brief list ------------------------------ */

    /** Returns only id + name for quick selectors. Optional ?q= filter. */
    #[Route(methods: 'GET', path: '/ippools/brief')]
    public function listBrief(ServerRequestInterface $r): JsonResponse {
        $this->auth($r);

        /** @var \App\Repository\IpPoolRepository $repo */
        $repo = $this->repos->getRepository(IpPool::class);

        $q = trim((string)($r->getQueryParams()['q'] ?? ''));
        $rows = $repo->findBy([]);
        if ($q !== '') {
            $needle = mb_strtolower($q);
            $rows = array_values(array_filter($rows, static function (IpPool $p) use ($needle) {
                // name or id contains (brief list keeps it simple)
                return str_contains(mb_strtolower((string)($p->getName() ?? '')), $needle)
                    || str_contains((string)$p->getId(), $needle);
            }));
        }

        usort($rows, static function (IpPool $a, IpPool $b) {
            $na = (string)($a->getName() ?? '');
            $nb = (string)($b->getName() ?? '');
            $cmp = strcasecmp($na, $nb);
            return $cmp === 0 ? $a->getId() <=> $b->getId() : $cmp;
        });

        $items = array_map(static fn(IpPool $p) => [
            'id'   => $p->getId(),
            'name' => $p->getName(),
        ], $rows);

        return new JsonResponse($items);
    }

    /* ------------------------------- create ------------------------------- */

    /**
     * Body (JSON):
     *   name (required)
     *   ips?                (string[])  – validated IPv4/IPv6; send [] to clear
     *   reputation_score?   (int)
     *   warmup_state?       (string)
     *   created_at?         (ISO8601 string)
     *   companyId?          (int|null)  – set or clear association
     * @throws \JsonException
     */
    #[Route(methods: 'POST', path: '/ippools')]
    public function create(ServerRequestInterface $r): JsonResponse {
        $this->auth($r);

        /** @var \App\Repository\IpPoolRepository $repo */
        $repo = $this->repos->getRepository(IpPool::class);
        /** @var \App\Repository\CompanyRepository $companyRepo */
        $companyRepo = $this->repos->getRepository(Company::class);

        $body = json_decode((string)$r->getBody(), true) ?: [];

        $name = $this->toStringOrNull($body['name'] ?? null);
        if (($name ?? '') === '') {
            throw new RuntimeException('Name is required', 400);
        }

        $ips        = $this->toIpArrayOrNull($body['ips'] ?? null) ?? [];
        $reputation = $this->toIntOrNull($body['reputation_score'] ?? null);
        $warmup     = $this->toStringOrNull($body['warmup_state'] ?? null);
        $createdAt  = $this->toDateTimeOrNull($body['created_at'] ?? null) ?? new DateTimeImmutable();

        $company = null;
        $companyIdRaw = $body['companyId'] ?? ($body['company_id'] ?? null);
        if ($companyIdRaw !== null) {
            $cid = $this->toIntOrNull($companyIdRaw);
            if ($cid !== null) {
                $company = $companyRepo->find($cid);
                if (!$company) throw new RuntimeException('Company not found', 404);
            }
        }

        $p = (new IpPool())
            ->setName($name)
            ->setIps($ips)
            ->setReputation_score($reputation)
            ->setWarmup_state($warmup)
            ->setCreated_at($createdAt)
            ->setCompany($company);

        $repo->save($p);

        return new JsonResponse($this->shape($p), 201);
    }

    /* --------------------------------- get -------------------------------- */

    #[Route(methods: 'GET', path: '/ippools/{id}')]
    public function get(ServerRequestInterface $r): JsonResponse {
        $this->auth($r);
        $id = (int)$r->getAttribute('id');

        /** @var \App\Repository\IpPoolRepository $repo */
        $repo = $this->repos->getRepository(IpPool::class);
        $p = $repo->find($id);
        if (!$p) throw new RuntimeException('IpPool not found', 404);

        return new JsonResponse($this->shape($p));
    }

    /* ------------------------------- update ------------------------------- */

    /**
     * Body (all optional):
     *   name?, ips?(string[]), reputation_score?, warmup_state?, created_at?(ISO8601),
     *   companyId?(int|null)
     */
    #[Route(methods: 'PATCH', path: '/ippools/{id}')]
    public function update(ServerRequestInterface $r): JsonResponse {
        $this->auth($r);
        $id = (int)$r->getAttribute('id');

        /** @var \App\Repository\IpPoolRepository $repo */
        $repo = $this->repos->getRepository(IpPool::class);
        /** @var \App\Repository\CompanyRepository $companyRepo */
        $companyRepo = $this->repos->getRepository(Company::class);

        $p = $repo->find($id);
        if (!$p) throw new RuntimeException('IpPool not found', 404);

        $body = json_decode((string)$r->getBody(), true) ?: [];

        if (array_key_exists('name', $body)) {
            $name = $this->toStringOrNull($body['name']);
            if (($name ?? '') === '') throw new RuntimeException('Name cannot be empty', 400);
            $p->setName($name);
        }
        if (array_key_exists('ips', $body)) {
            $ips = $this->toIpArrayOrNull($body['ips']);
            if ($ips === null && $body['ips'] !== null) throw new RuntimeException('ips must be an array of valid IP strings', 400);
            $p->setIps($ips ?? []);
        }
        if (array_key_exists('reputation_score', $body)) {
            $p->setReputation_score($this->toIntOrNull($body['reputation_score']));
        }
        if (array_key_exists('warmup_state', $body)) {
            $p->setWarmup_state($this->toStringOrNull($body['warmup_state']));
        }
        if (array_key_exists('created_at', $body)) {
            $dt = $this->toDateTimeOrNull($body['created_at']);
            if ($dt === null && $body['created_at'] !== null) {
                throw new RuntimeException('created_at must be ISO8601 datetime or null', 400);
            }
            $p->setCreated_at($dt);
        }
        if (array_key_exists('companyId', $body)) {
            if ($body['companyId'] === null) {
                $p->removeCompany();
            } else {
                $cid = $this->toIntOrNull($body['companyId']);
                if ($cid === null) throw new RuntimeException('companyId must be int or null', 400);
                $company = $companyRepo->find($cid);
                if (!$company) throw new RuntimeException('Company not found', 404);
                $p->setCompany($company);
            }
        }

        $repo->save($p);

        return new JsonResponse($this->shape($p));
    }

    /* ------------------------------- delete ------------------------------- */

    #[Route(methods: 'DELETE', path: '/ippools/{id}')]
    public function delete(ServerRequestInterface $r): JsonResponse {
        $this->auth($r);
        $id = (int)$r->getAttribute('id');

        /** @var \App\Repository\IpPoolRepository $repo */
        $repo = $this->repos->getRepository(IpPool::class);
        $p = $repo->find($id);
        if (!$p) throw new RuntimeException('IpPool not found', 404);

        if (method_exists($repo, 'delete'))      $repo->delete($p);
        elseif (method_exists($repo, 'remove'))  $repo->remove($p);
        else $this->qb->delete('ip_pool')->where('id', '=', $id)->execute();

        return new JsonResponse(null, 204);
    }

    /**
     * @throws \ReflectionException
     * @throws \JsonException
     */
    #[Route(methods: 'GET', path: '/ippools-companies/{companyId}')]
    public function listByCompany(ServerRequestInterface $r): JsonResponse {
        $uid = $this->auth($r);

        $companyId = (int)$r->getAttribute('companyId');
        /** @var \App\Repository\CompanyRepository $companyRepo */

        $company = $this->company((string)$r->getAttribute('companyId'), $uid);
        if (!$company) throw new RuntimeException('Company not found', 404);

        /** @var \App\Repository\IpPoolRepository $repo */
        $repo = $this->repos->getRepository(IpPool::class);

        $q       = $r->getQueryParams();
        $page    = max(1, (int)($q['page'] ?? 1));
        $perPage = max(1, min(200, (int)($q['perPage'] ?? 25)));

        // Fetch all pools for this company
        $rows = array_values($repo->findBy(['company' => $company]));

        // Reuse your in-memory filters (search, warmupState, rep range, created range)
        [$filtered, $total] = $this->applyFilters($rows, $q);
        $slice = array_slice($filtered, ($page - 1) * $perPage, $perPage);

        return new JsonResponse([
            'company' => ['id' => $company->getId(), 'name' => $company->getName()],
            'meta'    => [
                'page'       => $page,
                'perPage'    => $perPage,
                'total'      => $total,
                'totalPages' => (int)ceil($total / $perPage),
            ],
            'items'   => array_map(fn(IpPool $p) => $this->shape($p), $slice),
        ]);
    }

    #[Route(methods: 'GET', path: '/companies/{companyId}/ippools/brief')]
    public function listBriefByCompany(ServerRequestInterface $r): JsonResponse {
        $this->auth($r);

        $companyId = (int)$r->getAttribute('companyId');
        /** @var \App\Repository\CompanyRepository $companyRepo */
        $companyRepo = $this->repos->getRepository(Company::class);
        $company = $companyRepo->find($companyId);
        if (!$company) throw new RuntimeException('Company not found', 404);

        /** @var \App\Repository\IpPoolRepository $repo */
        $repo = $this->repos->getRepository(IpPool::class);

        $q = trim((string)($r->getQueryParams()['q'] ?? ''));

        $rows = array_values(array_filter($repo->findBy([]), static function (IpPool $p) use ($companyId) {
            return $p->getCompany()?->getId() === $companyId;
        }));

        if ($q !== '') {
            $needle = mb_strtolower($q);
            $rows = array_values(array_filter($rows, static function (IpPool $p) use ($needle) {
                return str_contains(mb_strtolower((string)($p->getName() ?? '')), $needle)
                    || str_contains((string)$p->getId(), $needle);
            }));
        }

        usort($rows, static function (IpPool $a, IpPool $b) {
            $na = (string)($a->getName() ?? '');
            $nb = (string)($b->getName() ?? '');
            $cmp = strcasecmp($na, $nb);
            return $cmp === 0 ? $a->getId() <=> $b->getId() : $cmp;
        });

        $items = array_map(static fn(IpPool $p) => [
            'id'   => $p->getId(),
            'name' => $p->getName(),
        ], $rows);

        return new JsonResponse($items);
    }

    /**
     * @throws \ReflectionException
     * @throws \JsonException
     */
    #[Route(methods: 'GET', path: '/companies/{companyId}/ippools/{poolId}')]
    public function getByCompany(ServerRequestInterface $r): JsonResponse {
        $uid = $this->auth($r);

        $companyId = (int)$r->getAttribute('companyId');
        $poolId    = (int)$r->getAttribute('poolId');

        $company = $this->company((string)$r->getAttribute('companyId'), $uid);

        /** @var \App\Repository\IpPoolRepository $repo */
        $repo = $this->repos->getRepository(IpPool::class);
        $p = $repo->findOneBy(['company' => $company, 'id' => $poolId]);

        return new JsonResponse($this->shape($p));
    }

    #[Route(methods: 'DELETE', path: '/companies/{companyId}/ippools/{poolId}')]
    public function deleteByCompany(ServerRequestInterface $r): JsonResponse {
        $this->auth($r);
        $companyId = (int)$r->getAttribute('companyId');
        $poolId    = (int)$r->getAttribute('poolId');

        /** @var \App\Repository\CompanyRepository $companyRepo */
        $companyRepo = $this->repos->getRepository(Company::class);
        $company = $companyRepo->find($companyId);
        if (!$company) throw new RuntimeException('Company not found', 404);

        /** @var \App\Repository\IpPoolRepository $repo */
        $repo = $this->repos->getRepository(IpPool::class);
        $p = $repo->find($poolId);
        if (!$p || $p->getCompany()?->getId() !== $companyId) {
            throw new RuntimeException('IpPool not found for this company', 404);
        }

        if (method_exists($repo, 'delete'))      $repo->delete($p);
        elseif (method_exists($repo, 'remove'))  $repo->remove($p);
        else $this->qb->delete('ip_pool')->where('id', '=', $poolId)->execute();

        return new JsonResponse(null, 204);
    }


}
