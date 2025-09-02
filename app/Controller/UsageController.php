<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Company;
use App\Entity\RateLimitCounter;
use App\Entity\UsageAggregate;
use MonkeysLegion\Http\Message\JsonResponse;
use MonkeysLegion\Query\QueryBuilder;
use MonkeysLegion\Repository\RepositoryFactory;
use MonkeysLegion\Router\Attributes\Route;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

final class UsageController
{
    private const RL_KEY_MONTH_PREFIX = 'messages:month:'; // must match service

    public function __construct(
        private RepositoryFactory $repos,
        private QueryBuilder      $qb
    ) {}

    /* ============================== ROUTES ============================== */

    /**
     * GET /usage-summary/companies/{hash}?date=YYYY-MM-DD
     */
    #[Route(methods: 'GET', path: '/usage-summary/companies/{hash}')]
    public function summary(ServerRequestInterface $request): JsonResponse
    {
        error_log('[Usage][summary] start');
        try {
            [$company, $userId] = $this->mustCompanyFromHash($request);
            $this->ensureMembership($company, $userId);

            parse_str((string)$request->getUri()->getQuery(), $qs);
            $dateRaw  = (string)($qs['date'] ?? 'now');
            error_log('[Usage][summary] qs='.json_encode($qs).' dateRaw='.$dateRaw);

            $dateUtc  = $this->parseDate($dateRaw)->setTimezone(new \DateTimeZone('UTC'));
            $dayStart = $dateUtc->setTime(0,0,0);

            error_log('[Usage][summary] companyId='.$company->getId().' userId='.$userId.' day='.$dayStart->format('Y-m-d'));

            $dailyCount   = $this->getDailySent($company->getId(), $dayStart);
            error_log('[Usage][summary] dailyCount='.$dailyCount);

            $monthAnchor  = $this->firstOfMonth($dateUtc);
            error_log('[Usage][summary] monthAnchor='.$monthAnchor->format('Y-m-01'));

            $monthlyCount = $this->getMonthlyCount($company, $monthAnchor);
            error_log('[Usage][summary] monthlyCount='.$monthlyCount);

            [$dailyLimit, $monthlyLimit] = $this->resolveQuotas($company);
            error_log('[Usage][summary] limits daily='.$dailyLimit.' monthly='.$monthlyLimit);

            $payload = [
                'companyId' => $company->getId(),
                'date'      => $dayStart->format('Y-m-d'),
                'daily'     => ['count' => $dailyCount,   'limit' => $dailyLimit],
                'monthly'   => ['count' => $monthlyCount, 'limit' => $monthlyLimit],
                'updatedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            ];
            error_log('[Usage][summary] ok payload='.json_encode($payload));
            return new JsonResponse($payload);
        } catch (\RuntimeException $e) {
            error_log('[Usage][summary][RTE] '.$e->getMessage().' code='.$e->getCode());
            return new JsonResponse(['error' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            error_log('[Usage][summary][EXC] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
            return new JsonResponse(['error' => 'Internal error'], 500);
        }
    }

    /**
     * GET /usage-daily/companies/{hash}?from=YYYY-MM-DD&to=YYYY-MM-DD
     */
    #[Route(methods: 'GET', path: '/usage-daily/companies/{hash}')]
    public function daily(ServerRequestInterface $request): JsonResponse
    {
        error_log('[Usage][daily] start');
        try {
            [$company, $userId] = $this->mustCompanyFromHash($request);
            $this->ensureMembership($company, $userId);

            [$from, $to] = $this->parseRange($request, 30);
            error_log('[Usage][daily] companyId='.$company->getId().' userId='.$userId.' from='.$from->format('Y-m-d').' to='.$to->format('Y-m-d'));

            $series = $this->getDailySeries($company->getId(), $from, $to);
            $total  = array_sum($series);
            error_log('[Usage][daily] seriesDays='.count($series).' total='.$total);

            $payload = [
                'companyId' => $company->getId(),
                'from'      => $from->format('Y-m-d'),
                'to'        => $to->format('Y-m-d'),
                'series'    => $series,
                'total'     => $total,
            ];
            error_log('[Usage][daily] ok payload='.substr(json_encode($payload),0,800)); // avoid huge logs
            return new JsonResponse($payload);
        } catch (\RuntimeException $e) {
            error_log('[Usage][daily][RTE] '.$e->getMessage().' code='.$e->getCode());
            return new JsonResponse(['error' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            error_log('[Usage][daily][EXC] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
            return new JsonResponse(['error' => 'Internal error'], 500);
        }
    }

    /**
     * GET /usage-month/companies/{hash}?month=YYYY-MM-01
     */
    #[Route(methods: 'GET', path: '/usage-month/companies/{hash}')]
    public function month(ServerRequestInterface $request): JsonResponse
    {
        error_log('[Usage][month] start');
        try {
            [$company, $userId] = $this->mustCompanyFromHash($request);
            $this->ensureMembership($company, $userId);

            parse_str((string)$request->getUri()->getQuery(), $qs);
            $monthParam = trim((string)($qs['month'] ?? 'now'));
            error_log('[Usage][month] qs='.json_encode($qs).' monthParam='.$monthParam);

            $monthAnchor = $this->firstOfMonth($this->parseDate($monthParam));
            $count       = $this->getMonthlyCount($company, $monthAnchor);
            [, $monthlyLimit] = $this->resolveQuotas($company);

            $payload = [
                'companyId' => $company->getId(),
                'month'     => $monthAnchor->format('Y-m-01'),
                'count'     => $count,
                'limit'     => $monthlyLimit,
            ];
            error_log('[Usage][month] ok payload='.json_encode($payload));
            return new JsonResponse($payload);
        } catch (\RuntimeException $e) {
            error_log('[Usage][month][RTE] '.$e->getMessage().' code='.$e->getCode());
            return new JsonResponse(['error' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            error_log('[Usage][month][EXC] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
            return new JsonResponse(['error' => 'Internal error'], 500);
        }
    }

    /**
     * GET /usage-compare/companies/{hash}?months=N
     */
    #[Route(methods: 'GET', path: '/usage-compare/companies/{hash}')]
    public function compare(ServerRequestInterface $request): JsonResponse
    {
        error_log('[Usage][compare] start');
        try {
            [$company, $userId] = $this->mustCompanyFromHash($request);
            $this->ensureMembership($company, $userId);

            parse_str((string)$request->getUri()->getQuery(), $qs);
            $monthsBack = max(1, (int)($qs['months'] ?? 3));
            error_log('[Usage][compare] companyId='.$company->getId().' monthsBack='.$monthsBack);

            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $cur = $this->firstOfMonth($now);

            $out = [];
            for ($i = 0; $i < $monthsBack; $i++) {
                $m = $cur->modify("-{$i} month");
                $out[$m->format('Y-m-01')] = $this->getMonthlyCount($company, $m);
            }
            ksort($out);

            $payload = [
                'companyId' => $company->getId(),
                'months'    => $out,
            ];
            error_log('[Usage][compare] ok months='.json_encode($out));
            return new JsonResponse($payload);
        } catch (\RuntimeException $e) {
            error_log('[Usage][compare][RTE] '.$e->getMessage().' code='.$e->getCode());
            return new JsonResponse(['error' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            error_log('[Usage][compare][EXC] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
            return new JsonResponse(['error' => 'Internal error'], 500);
        }
    }

    /* ============================== HELPERS ============================== */

    private function mustCompanyFromHash(ServerRequestInterface $request): array
    {
        error_log('[Usage][mustCompanyFromHash] enter');
        $userId = (int)$request->getAttribute('user_id', 0);
        $hash   = $request->getAttribute('hash');

        error_log('[Usage][mustCompanyFromHash] userId='.$userId.' hashType='.gettype($hash));
        if ($userId <= 0) throw new RuntimeException('Unauthorized', 401);
        if (!is_string($hash) || strlen($hash) !== 64) {
            error_log('[Usage][mustCompanyFromHash] invalid hash: '.print_r($hash,true));
            throw new RuntimeException('Invalid company identifier', 400);
        }

        /** @var \App\Repository\CompanyRepository $companyRepo */
        $companyRepo = $this->repos->getRepository(Company::class);
        error_log('[Usage][mustCompanyFromHash] repo='.get_class($companyRepo));

        /** @var Company|null $company */
        $company = $companyRepo->findOneBy(['hash' => $hash]);
        error_log('[Usage][mustCompanyFromHash] companyFound='.(bool)$company);

        if (!$company) throw new RuntimeException('Company not found', 404);

        error_log('[Usage][mustCompanyFromHash] ok companyId='.$company->getId());
        return [$company, $userId];
    }

    private function ensureMembership(Company $company, int $userId): void
    {
        error_log('[Usage][ensureMembership] enter userId='.$userId);
        $users = $company->getUsers() ?? [];
        error_log('[Usage][ensureMembership] usersType='.gettype($users).' count='. (is_countable($users)? count($users): -1));

        // tolerate non-hydrated relations
        $belongs = array_filter($users, function ($u) use ($userId) {
            if (is_object($u) && method_exists($u, 'getId')) return (int)$u->getId() === $userId;
            if (is_array($u) && isset($u['id']))           return (int)$u['id'] === $userId;
            if (is_int($u) || ctype_digit((string)$u))      return (int)$u === $userId;
            return false;
        });

        error_log('[Usage][ensureMembership] belongs='. (empty($belongs) ? 'no' : 'yes'));
        if (empty($belongs)) throw new RuntimeException('Forbidden', 403);
    }

    private function parseDate(string $date): \DateTimeImmutable
    {
        try {
            if ($date === 'now' || $date === '') {
                $dt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            } else {
                $dt = new \DateTimeImmutable($date, new \DateTimeZone('UTC'));
            }
            error_log('[Usage][parseDate] input='.$date.' -> '.$dt->format(\DateTimeInterface::ATOM));
            return $dt;
        } catch (\Throwable $e) {
            error_log('[Usage][parseDate][EXC] '.$e->getMessage());
            throw new RuntimeException('Invalid date', 400);
        }
    }

    private function firstOfMonth(\DateTimeImmutable $when): \DateTimeImmutable
    {
        $m = $when->setDate((int)$when->format('Y'), (int)$when->format('m'), 1)->setTime(0,0,0)->setTimezone(new \DateTimeZone('UTC'));
        error_log('[Usage][firstOfMonth] input='.$when->format('Y-m-d').' -> '.$m->format('Y-m-01'));
        return $m;
    }

    /** Returns [from, to) as UTC midnights. Default: last $defaultDays days. */
    private function parseRange(ServerRequestInterface $request, int $defaultDays = 30): array
    {
        parse_str((string)$request->getUri()->getQuery(), $qs);
        error_log('[Usage][parseRange] qs='.json_encode($qs).' defaultDays='.$defaultDays);

        $now  = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $from = isset($qs['from']) ? $this->parseDate((string)$qs['from']) : $now->modify("-{$defaultDays} days");
        $to   = isset($qs['to'])   ? $this->parseDate((string)$qs['to'])   : $now->modify('+1 day');

        $from = $from->setTime(0,0,0);
        $to   = $to->setTime(0,0,0);
        if ($to <= $from) $to = $from->modify('+1 day');

        error_log('[Usage][parseRange] from='.$from->format('Y-m-d').' to='.$to->format('Y-m-d'));
        return [$from, $to];
    }

    /* --------- UsageAggregate (daily) --------- */

    private function getDailySent(int $companyId, \DateTimeImmutable $dayStartUtc): int
    {
        /** @var \App\Repository\UsageAggregateRepository $repo */
        $repo = $this->repos->getRepository(UsageAggregate::class);
        $dateStr = $dayStartUtc->format('Y-m-d H:i:s');

        error_log('[Usage][getDailySent] companyId='.$companyId.' date='.$dateStr.' repo='.get_class($repo));
        try {
            $row = $repo->findOneBy(['company_id' => $companyId, 'date' => $dateStr])
                ?: $repo->findOneBy(['company_id' => $companyId, 'date' => $dayStartUtc]);
        } catch (\Throwable $e) {
            error_log('[Usage][getDailySent][EXC] '.$e->getMessage());
            $row = null;
        }

        $sent = $row ? (int)($row->getSent() ?? 0) : 0;
        error_log('[Usage][getDailySent] sent='.$sent);
        return $sent;
    }

    private function getDailySeries(int $companyId, \DateTimeImmutable $fromUtc, \DateTimeImmutable $toUtc): array
    {
        /** @var \App\Repository\UsageAggregateRepository $repo */
        $repo = $this->repos->getRepository(UsageAggregate::class);
        error_log('[Usage][getDailySeries] companyId='.$companyId.' from='.$fromUtc->format('Y-m-d').' to='.$toUtc->format('Y-m-d'));

        $from = $fromUtc->setTime(0,0,0);
        $to   = $toUtc->setTime(0,0,0);

        // Initialize output array with zeros for all days in range
        $out = [];
        $cursor = $from;
        while ($cursor < $to) {
            $out[$cursor->format('Y-m-d')] = 0;
            $cursor = $cursor->modify('+1 day');
        }

        // Try multiple query strategies to find the data
        try {
            // Strategy 1: Try with company_id as integer
            $rows = $repo->findBy(['company_id' => $companyId]);
            error_log('[Usage][getDailySeries] Strategy 1: found '.count($rows).' rows with company_id');
        } catch (\Throwable $e) {
            error_log('[Usage][getDailySeries][FETCH][EXC] '.$e->getMessage());
            $rows = [];
        }

        // Process the rows we found
        $rowIndex = 0;
        foreach ((array)$rows as $ua) {
            try {
                $rowIndex++;

                // Get the sent count - try all possible methods
                $sent = null;
                if (is_object($ua)) {
                    if (method_exists($ua, 'getSent')) {
                        $sent = $ua->getSent();
                    } elseif (property_exists($ua, 'sent')) {
                        $sent = $ua->sent;
                    } elseif (isset($ua->sent)) {
                        $sent = $ua->sent;
                    }
                } elseif (is_array($ua)) {
                    $sent = $ua['sent'] ?? null;
                }

                error_log('[Usage][getDailySeries][ROW'.$rowIndex.'] sent value: '.var_export($sent, true));

                $sent = (int)($sent ?? 0);
                if ($sent <= 0) {
                    error_log('[Usage][getDailySeries][ROW'.$rowIndex.'] Skipping - no messages sent');
                    continue;
                }

                // Get the date - try all possible methods
                $dateValue = null;
                if (is_object($ua)) {
                    if (method_exists($ua, 'getDate')) {
                        $dateValue = $ua->getDate();
                    } elseif (property_exists($ua, 'date')) {
                        $dateValue = $ua->date;
                    } elseif (isset($ua->date)) {
                        $dateValue = $ua->date;
                    }
                } elseif (is_array($ua)) {
                    $dateValue = $ua['date'] ?? null;
                }

                error_log('[Usage][getDailySeries][ROW'.$rowIndex.'] date value: '.var_export($dateValue, true).' type: '.gettype($dateValue));

                if (!$dateValue) {
                    error_log('[Usage][getDailySeries][ROW'.$rowIndex.'] No date found');
                    continue;
                }

                // Convert date to DateTimeImmutable
                $day = null;
                if ($dateValue instanceof \DateTimeInterface) {
                    $day = ($dateValue instanceof \DateTimeImmutable)
                        ? $dateValue
                        : \DateTimeImmutable::createFromInterface($dateValue);
                } elseif (is_string($dateValue)) {
                    // Handle string dates (YYYY-MM-DD or YYYY-MM-DD HH:II:SS)
                    $day = new \DateTimeImmutable($dateValue, new \DateTimeZone('UTC'));
                }

                if (!$day) {
                    error_log('[Usage][getDailySeries][ROW'.$rowIndex.'] Could not parse date');
                    continue;
                }

                // Normalize to UTC midnight
                $day = $day->setTime(0,0,0)->setTimezone(new \DateTimeZone('UTC'));
                error_log('[Usage][getDailySeries][ROW'.$rowIndex.'] Normalized date: '.$day->format('Y-m-d').' comparing to range ['.$from->format('Y-m-d').', '.$to->format('Y-m-d').']');

                // Check if date is in range [from, to] inclusive
                if ($day >= $from && $day <= $to) {  // Changed from < to <= to include the end date
                    $dayKey = $day->format('Y-m-d');
                    $out[$dayKey] = $sent;
                    error_log('[Usage][getDailySeries][ROW'.$rowIndex.'] Added '.$dayKey.' = '.$sent);
                } else {
                    error_log('[Usage][getDailySeries][ROW'.$rowIndex.'] Date '.$day->format('Y-m-d').' is outside range');
                }
            } catch (\Throwable $e) {
                error_log('[Usage][getDailySeries][ROW'.$rowIndex.'][EXC] '.$e->getMessage());
            }
        }

        ksort($out);
        error_log('[Usage][getDailySeries] Final result: days='.count($out).' with data='.count(array_filter($out)));
        return $out;
    }

    /**
     * Helper method to hydrate UsageAggregate entities from query builder results
     */
    private function hydrateUsageAggregates($results): array
    {
        $aggregates = [];
        foreach ($results as $row) {
            // Create a simple object that mimics the entity structure
            $aggregate = new \stdClass();
            $aggregate->company_id = $row->company_id ?? null;
            $aggregate->date = $row->date ?? null;
            $aggregate->sent = $row->sent ?? 0;
            $aggregates[] = $aggregate;
        }
        return $aggregates;
    }

    /* --------- RateLimitCounter (monthly authoritative) --------- */

    private function getMonthlyCount(Company $company, \DateTimeImmutable $monthAnchorUtc): int
    {
        /** @var \App\Repository\RateLimitCounterRepository $repo */
        $repo = $this->repos->getRepository(RateLimitCounter::class);

        $key = self::RL_KEY_MONTH_PREFIX . $monthAnchorUtc->format('Y-m-01');
        error_log('[Usage][getMonthlyCount] companyId='.$company->getId().' key='.$key.' repo='.get_class($repo));

        try {
            // Prefer FK + key; fall back to relation + key
            $row = $repo->findOneBy(['company_id' => (int)$company->getId(), 'key' => $key])
                ?: $repo->findOneBy(['company' => $company, 'key' => $key]);
        } catch (\Throwable $e) {
            error_log('[Usage][getMonthlyCount][EXC] '.$e->getMessage());
            $row = null;
        }

        $count = $row ? (int)($row->getCount() ?? 0) : 0;
        error_log('[Usage][getMonthlyCount] count='.$count);
        return $count;
    }

    /* --------- Plan limits --------- */

    private function resolveQuotas(Company $company): array
    {
        error_log('[Usage][resolveQuotas] companyId='.$company->getId());
        try {
            $plan     = method_exists($company, 'getPlan') ? $company->getPlan() : null;
            $features = $plan && method_exists($plan, 'getFeatures') ? ($plan->getFeatures() ?? []) : [];

            $emailsPerDayFeature   = (int)($features['quotas']['emailsPerDay']   ?? 0);
            $emailsPerMonthFeature = (int)($features['quotas']['emailsPerMonth'] ?? 0);
            $includedMessages      = $plan && method_exists($plan, 'getIncludedMessages')
                ? (int)($plan->getIncludedMessages() ?? 0)
                : 0;

            $monthlyFromPlan = $emailsPerMonthFeature > 0 ? $emailsPerMonthFeature : $includedMessages;

            $dailyOverride   = method_exists($company, 'getDaily_quota')   ? (int)($company->getDaily_quota()   ?? 0) : 0;
            $monthlyOverride = method_exists($company, 'getMonthly_quota') ? (int)($company->getMonthly_quota() ?? 0) : 0;

            $dailyLimit   = $dailyOverride   > 0 ? $dailyOverride   : $emailsPerDayFeature;
            $monthlyLimit = $monthlyOverride > 0 ? $monthlyOverride : $monthlyFromPlan;

            error_log('[Usage][resolveQuotas] daily='.$dailyLimit.' monthly='.$monthlyLimit);
            return [$dailyLimit, $monthlyLimit];
        } catch (\Throwable $e) {
            error_log('[Usage][resolveQuotas][EXC] '.$e->getMessage());
            return [0, 0];
        }
    }
}
