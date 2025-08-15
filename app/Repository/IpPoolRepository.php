<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\Company;
use App\Entity\IpPool;
use MonkeysLegion\Repository\EntityRepository;

final class IpPoolRepository extends EntityRepository
{
    protected string $entityClass = IpPool::class;
    // If your table name isn’t the inferred `ippool`, set it:
    protected string $table = 'ippool';

    /**
     * Best candidate for this company:
     *  - company-owned pool(s) first
     *  - prefer warmup_state = 'ready', then 'none'
     *  - highest reputation_score
     */
    public function findBestForCompany(Company $company): ?IpPool
    {
        $qb = clone $this->qb;
        $row = $qb->select()
            ->from($this->table)
            ->where('company_id', '=', $company->getId())
            ->orderBy('CASE WHEN warmup_state="ready" THEN 0 WHEN warmup_state="none" THEN 1 WHEN warmup_state="warming" THEN 2 ELSE 3 END', 'ASC')
            ->orderBy('reputation_score', 'DESC')
            ->limit(1)
            ->fetch($this->entityClass);

        return $row ?: null;
    }

    /**
     * A global pool (no company_id) to fall back to.
     * Name 'Default' is arbitrary—adjust to your seed.
     */
    public function findGlobalDefault(): ?IpPool
    {
        $qb = clone $this->qb;
        $row = $qb->select()
            ->from($this->table)
            ->where('company_id', 'IS', null)
            ->orderBy('reputation_score', 'DESC')
            ->limit(1)
            ->fetch($this->entityClass);

        return $row ?: null;
    }
}