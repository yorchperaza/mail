<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\DkimKey;
use App\Entity\Domain;
use MonkeysLegion\Repository\RepositoryFactory;
use Random\RandomException;

final class DomainConfig
{
    private const string SMTP_HOST     = 'smtp.monkeysmail.com';
    private const string SMTP_IP       = '34.30.122.164';
    private const string DKIM_SELECTOR = 'monkey';

    public function __construct(
        private RepositoryFactory $repos,
        private SmtpCredentialProvisioner $smtpProvisioner,
        private DkimKeyService $dkim,
        private OpenDkimConfigurator $openDkimConfigurator,
        private ?OpenDkimTableSync $tableSync = null  // Add this
    ) {
        if ($this->tableSync === null) {
            $this->tableSync = new OpenDkimTableSync($repos);
        }
    }

    /**
     * @throws \DateMalformedStringException
     * @throws RandomException
     */
    public function initializeAndSave(Domain $domain): array
    {
        $name = strtolower(trim((string)$domain->getDomain()));

        // 1) TXT / SPF / DMARC / MX expectations
        $txtName  = "_monkeys-verify.$name";
        $txtValue = 'monkeys-site-verification=' . bin2hex(random_bytes(16));

        $spfExpected   = sprintf('v=spf1 ip4:%s include:monkeysmail.com -all', self::SMTP_IP);
        $managedDmarcMailbox = 'dmarc@monkeysmail.com';
        $dmarcExpected = sprintf(
            'v=DMARC1; p=none; rua=mailto:%1$s; ruf=mailto:%1$s; fo=1; adkim=s; aspf=s',
            $managedDmarcMailbox
        );
        $mxExpected = [[
            'host'     => $name,
            'priority' => 10,
            'value'    => self::SMTP_HOST . '.',
        ]];

        // 2) DKIM: generate/ensure key
        $dk = $this->dkim->ensureKeyForDomain($name, self::DKIM_SELECTOR);
        $dkimTxtName  = $dk['txt_name'];
        $dkimTxtValue = $dk['txt_value'];

        // 2.1) Try to register in OpenDKIM (legacy - can be removed if not needed)
        $opendkimError = null;
        try {
            $this->openDkimConfigurator->ensureDomain($name, self::DKIM_SELECTOR, $dk['private_path']);
        } catch (\Throwable $e) {
            $opendkimError = $e->getMessage();
        }

        /** @var \App\Repository\DkimKeyRepository $dkRepo */
        $dkRepo = $this->repos->getRepository(DkimKey::class);

        // Find or create DKIM key record
        $existing = $dkRepo->findOneBy([
            'domain'   => $domain,
            'selector' => self::DKIM_SELECTOR,
        ]);

        if (!$existing) {
            $existing = (new DkimKey())
                ->setDomain($domain)
                ->setSelector(self::DKIM_SELECTOR)
                ->setCreated_at(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->setActive(true);
        }

        // Update with latest key info
        $existing
            ->setPublic_key_pem($dk['public_pem'])
            ->setPrivate_key_ref($dk['private_path']);

        // Add the txt_value if the setter exists
        if (method_exists($existing, 'setTxt_value')) {
            $existing->setTxt_value($dkimTxtValue);
        }

        $dkRepo->save($existing);

        // 3) Persist expectations back to Domain
        /** @var \App\Repository\DomainRepository $domainRepo */
        $domainRepo = $this->repos->getRepository(Domain::class);
        $domain
            ->setTxt_name($txtName)
            ->setTxt_value($txtValue)
            ->setSpf_expected($spfExpected)
            ->setDmarc_expected($dmarcExpected)
            ->setMx_expected($mxExpected)
            ->setRequire_tls(true)
            ->setArc_sign(true)
            ->setBimi_enabled(false);
        $domainRepo->save($domain);

        // 4) Sync OpenDKIM tables after saving
        try {
            if ($this->tableSync->syncTables()) {
                error_log("OpenDKIM tables synced successfully for domain: {$name}");
            }
        } catch (\Throwable $e) {
            $opendkimError = $opendkimError ?? $e->getMessage();
            error_log("OpenDKIM sync error: " . $e->getMessage());
        }

        // 5) Return bootstrap payload
        $payload = [
            'dns' => [
                'txt'   => ['name' => $txtName,  'value' => $txtValue],
                'spf'   => $spfExpected,
                'dmarc' => $dmarcExpected,
                'mx'    => $mxExpected,
                'dkim'  => [
                    self::DKIM_SELECTOR => [
                        'selector' => self::DKIM_SELECTOR,
                        'value'    => $dkimTxtValue,
                    ],
                ],
            ],
            'smtp' => [
                'host'  => self::SMTP_HOST,
                'ip'    => self::SMTP_IP,
                'ports' => [587, 465],
                'tls'   => ['starttls' => true, 'implicit' => true],
            ],
        ];

        if ($opendkimError !== null) {
            $payload['opendkim_error'] = $opendkimError;
        }

        return $payload;
    }
}