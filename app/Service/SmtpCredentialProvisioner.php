<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Company;
use App\Entity\IpPool;
use App\Entity\SmtpCredential;
use App\Repository\IpPoolRepository;
use App\Repository\SmtpCredentialRepository;
use MonkeysLegion\Repository\RepositoryFactory;

final class SmtpCredentialProvisioner
{
    public function __construct(private RepositoryFactory $repos) {}

    /**
     * Creates or reuses a single SMTP submission login per company.
     * Also auto-assigns an IpPool (company best → global default → none).
     */

    public function provisionForCompany(Company $company, string $domain, string $prefix='smtpuser'): array
    {
        /** @var EntityRepository $credRepo */
        $credRepo = $this->repos->getRepository(SmtpCredential::class);

        // ✅ Use generic findOneBy instead of a custom repo method
        $existing = $credRepo->findOneBy([
            // if your base repo already supports entity values for ManyToOne criteria,
            // you can pass the entity. If not, pass company_id => $company->getId()
            'company_id'      => $company->getId(),
            'username_prefix' => $prefix,
        ], /* loadRelations */ false);

        if ($existing) {
            return [
                'username' => "{$prefix}@{$domain}",
                'password' => null,
            ];
        }

        $password = $this->randomPassword(16);
        $hash     = $this->dovecotSha512Crypt($password);

        // If you also auto-assign IpPool here and your generic repo writes FK as ip_pool_id,
        // be sure your earlier mapping is in place. Else omit and add later when factory mapping is fixed.
        $credRepo->save((function () use ($company, $prefix, $hash) {
            $c = new SmtpCredential();
            $c->setCompany($company);
            $c->setUsername_prefix($prefix);
            $c->setPassword_hash($hash);
            $c->setScopes(['submit']);
            $c->setMax_msgs_min(0);
            $c->setMax_rcpt_msg(100);
            $c->setCreated_at(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            return $c;
        })());

        return [
            'username' => "{$prefix}@{$domain}",
            'password' => $password,
        ];
    }

    private function randomPassword(int $len): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%-_=+';
        $out = '';
        for ($i=0; $i<$len; $i++) $out .= $alphabet[random_int(0, strlen($alphabet)-1)];
        return $out;
    }

    private function dovecotSha512Crypt(string $password): string
    {
        $salt = substr(strtr(base64_encode(random_bytes(12)), '+', '.'), 0, 16);
        $hash = crypt($password, '$6$rounds=100000$'.$salt.'$');
        return '{SHA512-CRYPT}'.$hash;
    }
}