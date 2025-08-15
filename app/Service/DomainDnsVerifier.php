<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Domain;
use MonkeysLegion\Repository\RepositoryFactory;

final class DomainDnsVerifier
{
    public function __construct(private RepositoryFactory $repos)
    {
    }

    /**
     * Verify TXT (verification), SPF, DMARC, MX, and DKIM (if present) for a domain.
     * Persists a structured report + updates status/verified_at accordingly.
     * Returns the report array for the API.
     */
    public function verifyAndPersist(Domain $domain): array
    {
        $name = strtolower(trim((string)$domain->getDomain()));
        $expected = [
            'txt_name'  => (string)$domain->getTxt_name(),
            'txt_value' => (string)$domain->getTxt_value(),
            'spf'       => (string)$domain->getSpf_expected(),
            'dmarc'     => (string)$domain->getDmarc_expected(),
            'mx'        => (array)$domain->getMx_expected(), // e.g. [['host'=>..., 'value'=>..., 'priority'=>10]]
        ];

        /* --------------------------- DKIM (optional / recommended) --------------------------- */
        $dkimName  = null;
        $dkimValue = null; // full TXT value: "v=DKIM1; k=rsa; p=...."

        // Locate active DKIM key from the domain’s relation (if any)
        $activeDkim = null;
        foreach ($domain->getDkimKeys() ?? [] as $k) {
            if ($k->getActive()) { $activeDkim = $k; break; }
        }

        if ($activeDkim) {
            $selector = trim((string)$activeDkim->getSelector());
            if ($selector !== '') {
                $dkimName = sprintf('%s._domainkey.%s', $selector, $name);

                // If you stored a PEM in public_key_pem, convert to DKIM p= value
                $pem = (string)$activeDkim->getPublic_key_pem();
                $p   = $this->pemToDkimP($pem); // base64 (no headers/whitespace)
                if ($p !== '') {
                    $dkimValue = 'v=DKIM1; k=rsa; p=' . $p;
                }
            }
        }

        /* ---------------------------------- Run checks ---------------------------------- */
        $report = [
            'checked_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            'domain'     => $name,
            'records'    => [
                'verification_txt' => $this->checkVerificationTxt($expected['txt_name'], $expected['txt_value']),
                'spf'               => $this->checkSpf($name, $expected['spf']),
                'dmarc'             => $this->checkDmarc($name, $expected['dmarc']),
                'mx'                => $this->checkMx($name, $expected['mx']),
                'dkim'              => ($dkimName && $dkimValue)
                    ? $this->checkDkim($dkimName, $dkimValue)
                    : ['status' => 'skipped', 'found' => [], 'errors' => ['no_active_dkim']],
            ],
            'summary' => [],
        ];

        /* --------------------------- Required rules (policy) --------------------------- */
        // MX is optional for outbound-only. Toggle DKIM requirement here (env/config friendly).
        $requireDkim = true; // set to false to make DKIM recommended (not required)

        $required = [
            'verification_txt' => true,           // prove ownership
            'spf'              => true,           // sending authorization
            'dmarc'            => true,           // alignment/reporting
            'mx'               => false,          // inbound optional
            'dkim'             => $requireDkim,   // stricter if true
        ];

        // Compute summary & activation decision
        $allRequiredPass = true;
        foreach ($report['records'] as $kind => $res) {
            $pass = ($res['status'] ?? '') === 'pass';
            $report['summary'][$kind] = $pass ? 'pass' : ($res['status'] ?? 'fail');
            if (!empty($required[$kind]) && !$pass) {
                $allRequiredPass = false;
            }
        }

        /* --------------------------- Persist the report + status flip --------------------------- */
        $domain->setStatus($allRequiredPass ? 'active' : 'pending');
        if ($allRequiredPass && !$domain->getVerified_at()) {
            $domain->setVerified_at(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        }
        // Save report + timestamp if the entity has those columns/setters
        if (method_exists($domain, 'setVerification_report')) {
            $domain->setVerification_report($report);
        }
        if (method_exists($domain, 'setLast_checked_at')) {
            $domain->setLast_checked_at(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        }

        /** @var \App\Repository\DomainRepository $repo */
        $repo = $this->repos->getRepository(Domain::class);
        $repo->save($domain);

        return $report;
    }

    /* ------------ Individual checks ------------- */

    private function checkVerificationTxt(string $name, string $expected): array
    {
        if ($name === '' || $expected === '') {
            return ['status' => 'fail', 'found' => [], 'errors' => ['missing_expected']];
        }
        $found = $this->txtValues($name);
        // exact match (trim normalize quotes/spacing)
        $ok = in_array($this->normalizeTxt($expected), array_map([$this, 'normalizeTxt'], $found), true);
        return [
            'status'   => $ok ? 'pass' : 'fail',
            'host'     => $name,
            'expected' => $expected,
            'found'    => $found,
            'errors'   => $ok ? [] : ['value_not_found'],
        ];
    }

    private function checkSpf(string $apex, string $expected): array
    {
        if ($expected === '') {
            return ['status' => 'fail', 'host' => $apex, 'found' => [], 'errors' => ['missing_expected']];
        }
        $found   = $this->txtValues($apex);
        $normExp = $this->normalizeSpf($expected);
        $ok = false;
        foreach ($found as $txt) {
            if (str_starts_with(strtolower(trim($txt)), 'v=spf1')) {
                if ($this->normalizeSpf($txt) === $normExp) {
                    $ok = true; break;
                }
            }
        }
        return [
            'status'   => $ok ? 'pass' : 'fail',
            'host'     => $apex,
            'expected' => $expected,
            'found'    => $found,
            'errors'   => $ok ? [] : ['spf_not_matching'],
        ];
    }

    private function checkDmarc(string $apex, string $expected): array
    {
        $host = "_dmarc.$apex";
        if ($expected === '') {
            return ['status' => 'fail', 'host' => $host, 'found' => [], 'errors' => ['missing_expected']];
        }
        $found = $this->txtValues($host);
        $ok = in_array($this->normalizeDmarc($expected), array_map([$this, 'normalizeDmarc'], $found), true);
        return [
            'status'   => $ok ? 'pass' : 'fail',
            'host'     => $host,
            'expected' => $expected,
            'found'    => $found,
            'errors'   => $ok ? [] : ['dmarc_not_matching'],
        ];
    }

    private function checkMx(string $apex, array $expected): array
    {
        $exp = array_map(function ($r) {
            return [
                'host'     => strtolower(trim((string)($r['host'] ?? ''))),
                'value'    => rtrim(strtolower(trim((string)($r['value'] ?? ''))), '.') . '.', // ensure trailing dot
                'priority' => (int)($r['priority'] ?? 10),
            ];
        }, $expected);

        $recs  = dns_get_record($apex, DNS_MX) ?: [];
        $found = [];
        foreach ($recs as $r) {
            $found[] = [
                'host'     => strtolower($apex),
                'value'    => rtrim(strtolower((string)($r['target'] ?? '')), '.') . '.',
                'priority' => (int)($r['pri'] ?? 10),
            ];
        }

        // All expected must be present among found (order may vary)
        $missing = [];
        foreach ($exp as $er) {
            $match = false;
            foreach ($found as $fr) {
                if ($er['value'] === $fr['value'] && $er['priority'] === $fr['priority']) {
                    $match = true; break;
                }
            }
            if (!$match) $missing[] = $er;
        }

        return [
            'status'   => empty($missing) ? 'pass' : 'fail',
            'host'     => $apex,
            'expected' => $exp,
            'found'    => $found,
            'errors'   => empty($missing) ? [] : ['mx_missing_expected' => $missing],
        ];
    }

    private function checkDkim(string $host, string $expectedValue): array
    {
        $found   = $this->txtValues($host);
        $normExp = $this->normalizeDkim($expectedValue); // reduce to p=... if present
        $ok = in_array($normExp, array_map([$this, 'normalizeDkim'], $found), true);

        return [
            'status'   => $ok ? 'pass' : 'fail',
            'host'     => $host,
            'expected' => $expectedValue,
            'found'    => $found,
            'errors'   => $ok ? [] : ['dkim_not_matching'],
        ];
    }

    /* ------------ DNS helpers & normalizers ------------- */

    private function txtValues(string $host): array
    {
        $recs = dns_get_record($host, DNS_TXT) ?: [];
        $vals = [];
        foreach ($recs as $r) {
            $txt = $r['txt'] ?? $r['entries'][0] ?? null;
            if ($txt !== null && $txt !== '') $vals[] = (string)$txt;
        }
        return $vals;
    }

    private function normalizeTxt(string $s): string
    {
        return trim(preg_replace('~\s+~', ' ', (string)$s), " \t\r\n\"'");
    }

    private function normalizeSpf(string $s): string
    {
        $s = strtolower($this->normalizeTxt($s));
        // simple, order-sensitive compare (good enough for strict setup)
        return $s;
    }

    private function normalizeDmarc(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('~\s+~', '', $s);
        return $s;
    }

    private function normalizeDkim(string $s): string
    {
        $s = trim($s);
        // If it already contains p=..., normalize to just "p=BASE64"
        if (preg_match('~\bp=([a-z0-9+/=]+)~i', $s, $m)) {
            return 'p=' . $m[1];
        }
        // Else if given a PEM, reduce it to base64 (no headers) and prepend p=
        $p = $this->pemToDkimP($s);
        return $p !== '' ? 'p=' . $p : preg_replace('~\s+|\"~', '', strtolower($s));
    }

    /** Convert an RSA public PEM (or a DKIM TXT that already includes p=) to DKIM p= (single-line base64). */
    private function pemToDkimP(string $pem): string
    {
        $pem = trim($pem);
        if ($pem === '') return '';

        // If it’s already a DKIM TXT (contains p=), just extract and return the p=
        if (preg_match('~\bp=([a-z0-9+/=]+)~i', $pem, $m)) {
            return $m[1];
        }

        // Otherwise assume PEM and strip headers/whitespace
        $p = preg_replace('~-----.*?-----|\s+~', '', $pem);
        return $p ?: '';
    }
}