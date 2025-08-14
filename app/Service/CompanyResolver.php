<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Company;
use MonkeysLegion\Repository\RepositoryFactory;
use ReflectionException;

class CompanyResolver
{
    public function __construct(
        private RepositoryFactory $repos
    ) {}

    /**
     * @param string $hash   64-char SHA-256 company hash
     * @param int    $userId authenticated user ID
     * @return Company|null  the Company if found and user belongs, or null
     * @throws ReflectionException
     */
    public function resolveCompanyForUser(string $hash, int $userId): ?Company
    {
        $companyRepo = $this->repos->getRepository(Company::class);

        // only fetch companies that this user belongs to
        $companies = $companyRepo->findByRelation('users', $userId);

        foreach ($companies as $company) {
            if ($company->getHash() === $hash) {
                return $company;
            }
        }

        return null;
    }
}