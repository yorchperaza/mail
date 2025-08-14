<?php

namespace App\Repository;

use App\Entity\ApiKey;
use MonkeysLegion\Repository\EntityRepository;


class ApiKeyRepository extends EntityRepository
{
    protected string $table       = 'apikey';
    protected string $entityClass = ApiKey::class;


    /**
     * Check if an entity belongs to a specific company by foreign key
     */
    public function belongsToCompany(int $entityId, int $companyId): bool
    {
        try {
            $qb = clone $this->qb;

            $row = $qb->select(['company_id'])
                ->from($this->table)
                ->where('id', '=', $entityId)
                ->fetch();

            if (!$row) {
                return false;
            }

            $foundCompanyId = (int)($row->company_id ?? 0);

            $belongs = $foundCompanyId === $companyId;

            return $belongs;

        } catch (\Throwable $e) {
            throw $e;
        }
    }


    /**
     * Debug method to check table structure
     */
    public function debugTableStructure(): void
    {
        try {

            $pdo = $this->qb->pdo();
            // Check if table exists and show columns
            $stmt = $pdo->prepare("DESCRIBE `{$this->table}`");
            $stmt->execute();

            // Check actual data
            $stmt = $pdo->prepare("SELECT * FROM `{$this->table}` LIMIT 5");
            $stmt->execute();

        } catch (\Throwable $e) {
            error_log("[ApiKeyRepository] Failed to debug table structure: " . $e->getMessage());
        }
    }

}