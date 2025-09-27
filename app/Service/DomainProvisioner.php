<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\DkimKey;
use App\Entity\Domain;
use MonkeysLegion\Repository\RepositoryFactory;
use MonkeysLegion\Query\QueryBuilder;
use Random\RandomException;
use RuntimeException;

final class DomainProvisioner
{
    private const SMTP_HOST     = 'smtp.monkeysmail.com';
    private const SMTP_IP       = '34.30.122.164';            // adjust if needed
    private const DKIM_SELECTOR = 'monkey';                   // <— your selector

    public function __construct(
        private RepositoryFactory $repos,
        private QueryBuilder      $qb,
        private DkimKeyService    $dkim,
        private OpenDkimTableSync $tableSync,
        private ?OpenDkimConfigurator $openDkimConfigurator = null,
    ) {}

    /**
     * Generates DKIM, persists Domain & DkimKey, then syncs OpenDKIM tables.
     *
     * @throws \DateMalformedStringException
     * @throws RandomException
     */
    public function initializeAndSave(Domain $domain): array
    {
        $name = strtolower(trim((string)$domain->getDomain()));
        if ($name === '') throw new RuntimeException('Domain name required', 422);

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

        // 2) DKIM: generate/ensure key (selector = 'monkey')
        $dk = $this->dkim->ensureKeyForDomain($name, self::DKIM_SELECTOR);
        $dkimTxtName  = $dk['txt_name'];   // "monkey._domainkey.<domain>"
        $dkimTxtValue = $dk['txt_value'];  // "v=DKIM1; k=rsa; p=..."

        // (Optional) legacy configurator hook
        $opendkimError = null;
        if ($this->openDkimConfigurator) {
            try {
                $this->openDkimConfigurator->ensureDomain($name, self::DKIM_SELECTOR, $dk['private_path']);
            } catch (\Throwable $e) {
                $opendkimError = $e->getMessage();
            }
        }

        /** @var \App\Repository\DkimKeyRepository $dkRepo */
        $dkRepo = $this->repos->getRepository(DkimKey::class);

        // 2.1) Upsert DkimKey row for this domain+selector
        /** @var ?DkimKey $existing */
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

        $existing
            ->setPublic_key_pem($dk['public_pem'])
            ->setPrivate_key_ref($dk['private_path']);

        if (method_exists($existing, 'setTxt_value')) {
            $existing->setTxt_value($dkimTxtValue);
        }

        $dkRepo->save($existing);

        // 3) Persist DNS expectations back to Domain
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

        // 4) Sync OpenDKIM tables (and reload)
        $sync = $this->tableSync->syncTables();      // returns array
        if (!($sync['success'] ?? false)) {
            $opendkimError = $opendkimError ?? implode('; ', (array)($sync['errors'] ?? []));
            error_log('[DKIM] Table sync failed: ' . json_encode($sync));
        } else {
            error_log("[DKIM] Table sync OK, domains_synced=" . (int)$sync['domains_synced']);
        }

        // 5) Return bootstrap payload for UI/DNS
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
                        'name'     => $dkimTxtName,
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
