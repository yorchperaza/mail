<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\Company;
use App\Entity\SmtpCredential;
use MonkeysLegion\Repository\EntityRepository;

final class SmtpCredentialRepository extends EntityRepository
{
    protected string $entityClass = SmtpCredential::class;

    /**
     * @throws \Throwable
     */
    public function findByCompanyAndPrefix(Company $company, string $prefix): object
    {
        return $this->findOneBy([
            'company'         => $company,
            'username_prefix' => $prefix,
        ]);
    }

    /** Upsert by (company_id, username_prefix)
     * @throws \Throwable
     */
    public function upsert(array $data): object
    {
        /** @var Company $company */
        $company = $data['company'];
        $prefix  = $data['username_prefix'];

        $existing = $this->findByCompanyAndPrefix($company, $prefix);
        if ($existing) {
            foreach ($data as $k => $v) {
                if ($k === 'company') continue;
                $existing->$k = $v;
            }
            $this->save($existing);
            return $existing;
        }

        $cred = new SmtpCredential();
        $cred->setCompany($company);
        foreach ($data as $k => $v) {
            if ($k === 'company') continue;
            $cred->$k = $v;
        }
        if (! $cred->getCreated_at()) {
            $cred->setCreated_at(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        }
        $this->save($cred);
        return $cred;
    }
}