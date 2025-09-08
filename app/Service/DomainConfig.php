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
    private const string DKIM_KEY_DIR  = '/var/lib/rspamd/dkim';

    public function __construct(
        private RepositoryFactory $repos,
        private SmtpCredentialProvisioner $smtpProvisioner,
        private DkimKeyService $dkim,
        private OpenDkimConfigurator $openDkimConfigurator
    ) {}

    /**
     * Initialize DNS expectations + DKIM + SMTP and persist back to Domain.
     *
     * @return array{
     *   dns: array{
     *     txt: array{name:string, value:string},
     *     spf: string,
     *     dmarc: string,
     *     mx: array<array{host:string, value:string, priority:int}>,
     *     dkim: array<string, array{selector:string, value:string}>
     *   },
     *   smtp: array{
     *     host:string, ip:string, ports:array<int>, tls: array{starttls:bool, implicit:bool}
     *   },
     *   opendkim_error?: string|null
     * }
     * @throws RandomException
     * @throws \Throwable
     */
    public function initializeAndSave(Domain $domain): array
    {
        $name = strtolower(trim((string)$domain->getDomain()));

        // 1) TXT / SPF / DMARC / MX expectations
        $txtName  = "_monkeys-verify.$name";
        $txtValue = 'monkeys-site-verification=' . bin2hex(random_bytes(16));

        $spfExpected   = sprintf('v=spf1 ip4:%s include:monkeysmail.com -all', self::SMTP_IP);
        $dmarcExpected = sprintf(
            'v=DMARC1; p=none; rua=mailto:dmarc@%1$s; ruf=mailto:dmarc@%1$s; fo=1; adkim=s; aspf=s',
            $name
        );
        $mxExpected = [[
            'host'     => $name,
            'priority' => 10,
            // Some UIs want the target separately; we also keep "value" for compatibility.
            'value'    => self::SMTP_HOST . '.',
        ]];

        // 2) DKIM: generate/ensure key (independent of OpenDKIM tables)
        $dk = $this->dkim->ensureKeyForDomain($name, self::DKIM_SELECTOR);
        $dkimTxtName  = $dk['txt_name'];   // e.g. "monkey._domainkey.monkeys.cloud"
        $dkimTxtValue = $dk['txt_value'];  // "v=DKIM1; k=rsa; p=..."

        // 2.1) Try to register in OpenDKIM (non-fatal; capture error)
        $opendkimError = null;
        try {
            $this->openDkimConfigurator->ensureDomain($name, self::DKIM_SELECTOR, $dk['private_path']);
        } catch (\Throwable $e) {
            $opendkimError = $e->getMessage();
        }

        /** @var \App\Repository\DkimKeyRepository|\MonkeysLegion\Repository\EntityRepository $dkRepo */
        $dkRepo = $this->repos->getRepository(DkimKey::class);

        // Find existing active row for this domain+selector (idempotent)
        $existing = $dkRepo->findOneBy([
            'domain'   => $domain,
            'selector' => self::DKIM_SELECTOR,
        ]);

        if (! $existing) {
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

        // 4) Return bootstrap payload for UI (DKIM as mapping by selector)
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
