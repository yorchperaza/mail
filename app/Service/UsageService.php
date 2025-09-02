<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Company;
use App\Entity\RateLimitCounter;
use App\Entity\UsageAggregate;
use MonkeysLegion\Repository\RepositoryFactory;

final class UsageService
{
    private const RL_KEY_DAY_PREFIX   = 'messages:day:';
    private const RL_KEY_MONTH_PREFIX = 'messages:month:';

    public function __construct(private RepositoryFactory $repos) {}

    /* ---------- Daily / Monthly counter readers (authoritative for quotas) ---------- */

    public function getDailyCount(Company $company, \DateTimeImmutable $whenUtc): int
    {
        $dayAnchor = $whenUtc->setTime(0,0,0)->setTimezone(new \DateTimeZone('UTC'));
        $key = self::RL_KEY_DAY_PREFIX.$dayAnchor->format('Y-m-d');
        return $this->readRateLimitCount($company, $key, $dayAnchor);
    }

    public function getMonthlyCount(Company $company, \DateTimeImmutable $whenUtc): int
    {
        $monthAnchor = $whenUtc->setDate((int)$whenUtc->format('Y'), (int)$whenUtc->format('m'), 1)
            ->setTime(0,0,0)->setTimezone(new \DateTimeZone('UTC'));
        $key = self::RL_KEY_MONTH_PREFIX.$monthAnchor->format('Y-m-01');
        return $this->readRateLimitCount($company, $key, $monthAnchor);
    }

    private function readRateLimitCount(Company $company, string $key, \DateTimeImmutable $windowStart): int
    {
        /** @var \App\Repository\RateLimitCounterRepository $repo */
        $repo = $this->repos->getRepository(RateLimitCounter::class);
        $windowStr = $windowStart->format('Y-m-d H:i:s');

        $row = $repo->findOneBy(['company' => $company, 'key' => $key, 'window_start' => $windowStart])
            ?: $repo->findOneBy(['company' => $company, 'key' => $key, 'window_start' => $windowStr])
                ?: $repo->findOneBy(['company_id' => $company->getId(), 'key' => $key, 'window_start' => $windowStr]);

        return $row ? (int)($row->getCount() ?? 0) : 0;
    }

    /* ------------------------ Timeseries from UsageAggregate ------------------------ */

    /**
     * Return per-day UsageAggregate "sent" totals for [from, to).
     * Dates must be UTC-midnight; if not, they are normalized.
     */
    public function getDailyTimeseries(int $companyId, \DateTimeImmutable $fromUtc, \DateTimeImmutable $toUtc): array
    {
        /** @var \App\Repository\UsageAggregateRepository $repo */
        $repo = $this->repos->getRepository(UsageAggregate::class);

        $from = $fromUtc->setTime(0,0,0);
        $to   = $toUtc->setTime(0,0,0);

        $rows = method_exists($repo, 'findBy')
            ? $repo->findBy(['company_id' => $companyId])
            : (method_exists($repo, 'findAll') ? $repo->findAll() : []);

        $out = [];
        foreach ((array)$rows as $ua) {
            $d = $ua->getDate();
            if (!$d instanceof \DateTimeInterface) continue;
            $day = ($d instanceof \DateTimeImmutable) ? $d : \DateTimeImmutable::createFromInterface($d);
            $day = $day->setTime(0,0,0)->setTimezone(new \DateTimeZone('UTC'));
            if ($day >= $from && $day < $to) {
                $out[$day->format('Y-m-d')] = (int)($ua->getSent() ?? 0);
            }
        }

        // Fill gaps with 0s for nicer charts/consumers
        $cursor = $from;
        while ($cursor < $to) {
            $k = $cursor->format('Y-m-d');
            if (!array_key_exists($k, $out)) $out[$k] = 0;
            $cursor = $cursor->modify('+1 day');
        }

        ksort($out);
        return $out; // ['2025-09-01'=>12, ...]
    }

    /** Sum of daily "sent" between [from, to). */
    public function sumDailySent(int $companyId, \DateTimeImmutable $fromUtc, \DateTimeImmutable $toUtc): int
    {
        return array_sum($this->getDailyTimeseries($companyId, $fromUtc, $toUtc));
    }
}
