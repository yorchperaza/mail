<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Company;
use App\Entity\SmtpCredential;
use MonkeysLegion\Repository\RepositoryFactory;

final class SmtpCredentialProvisioner
{
    public function __construct(private RepositoryFactory $repos) {}

    public function provisionForCompany(Company $company, string $domain, string $prefix='smtpuser'): array
    {
        /** @var \MonkeysLegion\Repository\EntityRepository $repo */
        $repo = $this->repos->getRepository(SmtpCredential::class);

        // Try to find existing by relation + prefix
        $existing = $repo->findOneBy([
            'company_id'         => $company->getId(),
            'username_prefix' => $prefix,
        ]);

        if ($existing instanceof SmtpCredential) {
            return [
                'username' => "{$prefix}@{$domain}",
                'password' => null, // do not re-expose existing secret
            ];
        }

        // Create new credential
        $password = $this->randomPassword(16);
        $hash     = $this->dovecotSha512Crypt($password);

        $cred = new SmtpCredential()
            ->setCompany($company)
            ->setUsername_prefix($prefix)
            ->setPassword_hash($hash)
            ->setScopes(['submit'])
            ->setMax_msgs_min(0)
            ->setMax_rcpt_msg(100)
            ->setCreated_at(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        $repo->save($cred);

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