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
     * Verify TXT (verification), SPF, DMARC, MX, DKIM,
     * PLUS TLS-RPT + MTA-STS (DNS+HTTPS policy) + ACME delegation CNAME.
     */
    public function verifyAndPersist(Domain $domain): array
    {
        $nowIso = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
        $name   = strtolower(trim((string)$domain->getDomain()));

        $expected = [
            'txt_name'  => (string)$domain->getTxt_name(),
            'txt_value' => (string)$domain->getTxt_value(),
            'spf'       => (string)$domain->getSpf_expected(),
            'dmarc'     => (string)$domain->getDmarc_expected(),
            'mx'        => (array)$domain->getMx_expected(), // [['host'=>..., 'value'=>..., 'priority'=>10]]
        ];

        /* ------------ DKIM (optional/recommended) ------------- */
        $dkimName  = null;
        $dkimValue = null; // "v=DKIM1; k=rsa; p=..."
        $activeDkim = null;
        foreach ($domain->getDkimKeys() ?? [] as $k) {
            if ($k->getActive()) { $activeDkim = $k; break; }
        }
        if ($activeDkim) {
            $selector = trim((string)$activeDkim->getSelector());
            if ($selector !== '') {
                $dkimName = sprintf('%s._domainkey.%s', $selector, $name);
                $pem = (string)$activeDkim->getPublic_key_pem();
                $p   = $this->pemToDkimP($pem);
                if ($p !== '') $dkimValue = 'v=DKIM1; k=rsa; p=' . $p;
            }
        }

        /* ------------ TLS-RPT expected (from Domain fields) ------------- */
        $tlsrptExpected = method_exists($domain, 'getTlsrpt_expected')
            ? (string)$domain->getTlsrpt_expected()
            : null;
        $tlsrptHost = '_smtp._tls.' . $name;

        /* ------------ MTA-STS expected (from Domain fields) ------------- */
        /** @var array<string,mixed> $mtaStsExpected */
        $mtaStsExpected = method_exists($domain, 'getMta_sts_expected')
            ? (array)$domain->getMta_sts_expected()
            : [];

        $stsTxtName    = '_mta-sts.' . $name;
        $stsHost       = 'mta-sts.' . $name;

        // Expected CNAME for mta-sts.<domain>
        $expectedCname = (string)($mtaStsExpected['host']['value'] ?? 'mta-sts.monkeysmail.com.');
        if ($expectedCname !== '') {
            $expectedCname = rtrim(strtolower($expectedCname), '.') . '.';
        }

        // ACME delegation CNAME
        $acmeExpected = (string)($mtaStsExpected['acme_delegate']['value'] ?? '');
        if ($acmeExpected === '') {
            $acmeExpected = '_acme-challenge.' . $name . '.auth.monkeysmail.com.';
        }
        $acmeExpected = rtrim(strtolower($acmeExpected), '.') . '.';
        $acmeHost     = '_acme-challenge.' . $stsHost; // _acme-challenge.mta-sts.<domain>

        /* ---------------------------------- Run checks ---------------------------------- */
        $records = [
            'verification_txt' => $this->checkVerificationTxt($expected['txt_name'], $expected['txt_value']),
            'spf'               => $this->checkSpf($name, $expected['spf']),
            'dmarc'             => $this->checkDmarc($name, $expected['dmarc']),
            'mx'                => $this->checkMx($name, $expected['mx']),
            'dkim'              => ($dkimName && $dkimValue)
                ? $this->checkDkim($dkimName, $dkimValue)
                : ['status' => 'skipped', 'found' => [], 'errors' => ['no_active_dkim']],
        ];

        /* =========================
         * TLS-RPT verification
         * ========================= */
        $tlsFound = $this->txtValues($tlsrptHost);
        $tlsOk = false;
        if ($tlsrptExpected) {
            $tlsOk = in_array($this->normalizeTxt($tlsrptExpected), array_map([$this,'normalizeTxt'], $tlsFound), true);
        }
        $records['tlsrpt'] = [
            'status'   => ($tlsrptExpected && $tlsOk) ? 'pass' : 'fail',
            'host'     => $tlsrptHost,
            'expected' => $tlsrptExpected,
            'found'    => $tlsFound,
            'errors'   => ($tlsrptExpected && $tlsOk) ? [] : ['tlsrpt_not_matching_or_missing'],
        ];

        /* =========================
         * MTA-STS DNS verification
         * ========================= */
        $stsTxts = $this->txtValues($stsTxtName);
        $policyIdOk = false;
        foreach ($stsTxts as $txt) {
            if (preg_match('~^v=STSv1;\s*id=\S+~i', trim($txt))) { $policyIdOk = true; break; }
        }

        $cnameTargets = $this->cnameTargets($stsHost);
        $cnameOk = false;
        foreach ($cnameTargets as $t) {
            $t = rtrim(strtolower($t), '.') . '.';
            if ($t === $expectedCname) { $cnameOk = true; break; }
        }

        $records['mta_sts_dns'] = [
            'status'          => ($policyIdOk && ($expectedCname === '' || $cnameOk)) ? 'pass' : 'fail',
            'policy_txt_name' => $stsTxtName,
            'policy_txt_found'=> $stsTxts,
            'policy_txt_ok'   => $policyIdOk,
            'host_name'       => $stsHost,
            'cname_found'     => $cnameTargets,
            'cname_expected'  => $expectedCname ?: null,
            'cname_ok'        => $expectedCname ? $cnameOk : null,
            'errors'          => ($policyIdOk && ($expectedCname === '' || $cnameOk)) ? [] : ['mta_sts_dns_invalid'],
        ];

        /* =========================
         * MTA-STS ACME delegation CNAME
         * ========================= */
        $acmeCnames = $this->cnameTargets($acmeHost);
        $acmeOk = false;
        foreach ($acmeCnames as $t) {
            $t = rtrim(strtolower($t), '.') . '.';
            if ($t === $acmeExpected) { $acmeOk = true; break; }
        }

        $records['mta_sts_acme'] = [
            'status'         => $acmeOk ? 'pass' : 'fail',
            'host_name'      => $acmeHost,
            'cname_found'    => $acmeCnames,
            'cname_expected' => $acmeExpected,
            'errors'         => $acmeOk ? [] : ['mta_sts_acme_cname_invalid_or_missing'],
        ];

        /* =========================
         * MTA-STS HTTPS policy fetch
         * ========================= */
        $policyUrl = "https://{$stsHost}/.well-known/mta-sts.txt";
        $http = $this->httpGet($policyUrl, 10000);
        $parsed = null;
        $policyOk = false;
        $httpOk = ($http['status'] >= 200 && $http['status'] < 300 && $http['error'] === null);

        if ($httpOk) {
            $parsed = $this->parseStsPolicy($http['body']);
            $policyOk = !isset($parsed['error']) && ($parsed['version'] ?? null) === 'STSv1';
        }

        $records['mta_sts_policy'] = [
            'status' => ($httpOk && $policyOk) ? 'pass' : 'fail',
            'url'    => $policyUrl,
            'http'   => ['status' => $http['status'], 'error' => $http['error']],
            'parsed' => $parsed,
            'errors' => ($httpOk && $policyOk) ? [] : ['mta_sts_policy_unreachable_or_invalid'],
        ];

        // NEW: expose the policy URL directly for the UI (RecordsTab reads recObj?.mta_sts_policy_url)
        $records['mta_sts_policy_url'] = $policyUrl; // NEW

        // NEW: aggregate, UI-friendly mta_sts block to match RecordsTab.tsx (recs?.mta_sts)
        $stsAllOk = (
            (($records['mta_sts_dns']['status'] ?? '') === 'pass') &&
            (($records['mta_sts_acme']['status'] ?? '') === 'pass') &&
            (($records['mta_sts_policy']['status'] ?? '') === 'pass')
        );

        $policyVal = (string)($mtaStsExpected['policy_txt']['value'] ?? '');
        $policyVal = trim($policyVal);
        $expectedPolicyVal = str_starts_with(strtolower($policyVal), 'v=stsv1')
            ? $policyVal
            : ('v=STSv1; id=' . $policyVal);

        $records['mta_sts'] = [ // NEW
            'status'   => $stsAllOk ? 'pass' : 'fail',
            'host'     => $stsHost,
            'expected' => [
                'policy_txt' => [
                    'type'  => 'TXT',
                    'name'  => $stsTxtName,
                    'value' => $expectedPolicyVal,
                    'ttl'   => 3600,
                ],
                'host' => [
                    'type'  => 'CNAME',
                    'name'  => $stsHost,
                    'value' => $expectedCname,
                    'ttl'   => 3600,
                ],
                'acme_delegate' => [
                    'type'  => 'CNAME',
                    'name'  => $acmeHost,
                    'value' => $acmeExpected,
                    'ttl'   => 3600,
                ],
            ],
            'found'    => [
                'policy_txt_found' => $stsTxts,
                'cname_found'      => $cnameTargets,
                'acme_cname_found' => $acmeCnames,
                'policy_url'       => $policyUrl,
            ],
            'errors'   => array_values(array_merge(
                (($records['mta_sts_dns']['status'] ?? '') === 'pass')   ? [] : (array)($records['mta_sts_dns']['errors']   ?? []),
                (($records['mta_sts_acme']['status'] ?? '') === 'pass')  ? [] : (array)($records['mta_sts_acme']['errors']  ?? []),
                (($records['mta_sts_policy']['status'] ?? '') === 'pass')? [] : (array)($records['mta_sts_policy']['errors']?? []),
            )),
        ];

        /* --------------------------- Required rules (policy) --------------------------- */
        $requireDkim = true;  // adjust if DKIM should be advisory only
        $required = [
            'verification_txt' => true,
            'spf'              => true,
            'dmarc'            => true,
            'mx'               => false, // inbound optional
            'dkim'             => $requireDkim,
            'tlsrpt'           => true,
            'mta_sts_dns'      => true,
            'mta_sts_acme'     => true,
            'mta_sts_policy'   => true,
        ];

        $summary = [];
        $allRequiredPass = true;
        foreach ($records as $kind => $res) {
            if (!is_array($res)) continue;
            $pass = ($res['status'] ?? '') === 'pass';
            $summary[$kind] = $pass ? 'pass' : ($res['status'] ?? 'fail');
            if (!empty($required[$kind]) && !$pass) {
                $allRequiredPass = false;
            }
        }

        /* --------------------------- Persist the report + status flip --------------------------- */
        $report = [
            'checked_at' => $nowIso,
            'domain'     => $name,
            'records'    => $records,
            'summary'    => $summary,
            // Optional: expectations for UI table
            'expectations' => [
                [
                    'type'     => 'TXT',
                    'name'     => $stsTxtName,
                    'value'    => 'v=STSv1; id=' . ((string)($mtaStsExpected['policy_txt']['value'] ?? '')),
                    'ttl'      => 3600,
                    'priority' => null,
                ],
                [
                    'type'     => 'CNAME',
                    'name'     => $stsHost,
                    'value'    => $expectedCname,
                    'ttl'      => 3600,
                    'priority' => null,
                ],
                [
                    'type'     => 'CNAME',
                    'name'     => $acmeHost,
                    'value'    => $acmeExpected,
                    'ttl'      => 3600,
                    'priority' => null,
                ],
            ],
        ];

        $domain->setStatus($allRequiredPass ? 'active' : 'pending');
        if ($allRequiredPass && !$domain->getVerified_at()) {
            $domain->setVerified_at(new \DateTimeImmutable($nowIso));
        }
        if (method_exists($domain, 'setVerification_report')) {
            $domain->setVerification_report($report);
        }
        if (method_exists($domain, 'setLast_checked_at')) {
            $domain->setLast_checked_at(new \DateTimeImmutable($nowIso));
        }

        /** @var \App\Repository\DomainRepository $repo */
        $repo = $this->repos->getRepository(Domain::class);
        $repo->save($domain);

        return $report;
    }


    /* ------------ Individual checks you already had ------------- */

    private function checkVerificationTxt(string $name, string $expected): array
    {
        if ($name === '' || $expected === '') {
            return ['status' => 'fail', 'found' => [], 'errors' => ['missing_expected']];
        }
        $found = $this->txtValues($name);
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
                if ($this->normalizeSpf($txt) === $normExp) { $ok = true; break; }
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
                'value'    => rtrim(strtolower(trim((string)($r['value'] ?? ''))), '.') . '.', // trailing dot
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

        $missing = [];
        foreach ($exp as $er) {
            $match = false;
            foreach ($found as $fr) {
                if ($er['value'] === $fr['value'] && $er['priority'] === $fr['priority']) { $match = true; break; }
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
        $normExp = $this->normalizeDkim($expectedValue);
        $ok = in_array($normExp, array_map([$this, 'normalizeDkim'], $found), true);

        return [
            'status'   => $ok ? 'pass' : 'fail',
            'host'     => $host,
            'expected' => $expectedValue,
            'found'    => $found,
            'errors'   => $ok ? [] : ['dkim_not_matching'],
        ];
    }

    /* ------------ DNS/HTTP helpers & normalizers ------------- */

    /** Return TXT values (best-effort normalized) */
    private function txtValues(string $host): array
    {
        $recs = @dns_get_record($host, DNS_TXT) ?: [];
        $vals = [];
        foreach ($recs as $r) {
            // PHP variants: 'txt' or 'entries' => fragments
            if (isset($r['txt']) && $r['txt'] !== '') {
                $vals[] = (string)$r['txt'];
            } elseif (!empty($r['entries']) && is_array($r['entries'])) {
                $vals[] = implode('', $r['entries']);
            }
        }
        return $vals;
    }

    /** Return CNAME targets for host (as array of strings) */
    private function cnameTargets(string $host): array
    {
        $recs = @dns_get_record($host, DNS_CNAME) ?: [];
        $out = [];
        foreach ($recs as $r) {
            $t = (string)($r['target'] ?? '');
            if ($t !== '') $out[] = $t;
        }
        return $out;
    }

    private function normalizeTxt(string $s): string
    {
        return trim(preg_replace('~\s+~', ' ', (string)$s), " \t\r\n\"'");
    }

    private function normalizeSpf(string $s): string
    {
        $s = strtolower($this->normalizeTxt($s));
        return $s;
    }

    private function normalizeDmarc(string $s): string
    {
        $s = strtolower(trim($s));
        return preg_replace('~\s+~', '', $s);
    }

    private function normalizeDkim(string $s): string
    {
        $s = trim($s);
        if (preg_match('~\bp=([a-z0-9+/=]+)~i', $s, $m)) {
            return 'p=' . $m[1];
        }
        $p = $this->pemToDkimP($s);
        return $p !== '' ? 'p=' . $p : preg_replace('~\s+|\"~', '', strtolower($s));
    }

    /** Convert RSA public PEM (or DKIM TXT) to bare base64 p= value */
    private function pemToDkimP(string $pem): string
    {
        $pem = trim($pem);
        if ($pem === '') return '';
        if (preg_match('~\bp=([a-z0-9+/=]+)~i', $pem, $m)) return $m[1];
        return preg_replace('~-----.*?-----|\s+~', '', $pem) ?: '';
    }

    /**
     * GET helper: returns ['status'=>int,'body'=>string,'error'=>?string]
     */
    private function httpGet(string $url, int $timeoutMs = 5000): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT_MS => $timeoutMs,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Monkeysmail-MTA-STS-Checker/1.0',
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch) ?: null;
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $code, 'body' => (string)$body, 'error' => $err];
    }

    /**
     * Minimal STS policy parser (RFC 8461) for STSv1.
     * Returns ['version','mode','mx'=>[],'max_age'] or ['error'=>string]
     */
    private function parseStsPolicy(string $text): array
    {
        $lines = preg_split('~\R+~', $text) ?: [];
        $kv = [];
        foreach ($lines as $ln) {
            $ln = trim($ln);
            if ($ln === '' || str_starts_with($ln, '#')) continue;
            $parts = array_map('trim', explode(':', $ln, 2));
            if (count($parts) === 2) $kv[strtolower($parts[0])] = $parts[1];
        }
        if (($kv['version'] ?? '') !== 'STSv1') {
            return ['error' => 'version invalid or missing'];
        }
        $mx = [];
        if (!empty($kv['mx'])) {
            foreach (preg_split('~\s*,\s*~', $kv['mx']) as $m) {
                $m = trim($m);
                if ($m !== '') $mx[] = $m;
            }
        }
        $maxAge = isset($kv['max_age']) ? (int)$kv['max_age'] : null;
        return [
            'version' => 'STSv1',
            'mode'    => $kv['mode'] ?? null,
            'mx'      => $mx,
            'max_age' => $maxAge,
        ];
    }
}
