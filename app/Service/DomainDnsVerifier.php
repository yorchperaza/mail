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
         * MTA-STS DNS verification (TXT only)
         * ========================= */
        $stsTxts = $this->txtValues($stsTxtName);
        $policyIdOk = false;
        foreach ($stsTxts as $txt) {
            if (preg_match('~^v=STSv1;\s*id=\S+~i', trim($txt))) { $policyIdOk = true; break; }
        }
        $records['mta_sts_dns'] = [
            'status'           => $policyIdOk ? 'pass' : 'fail',
            'policy_txt_name'  => $stsTxtName,
            'policy_txt_found' => $stsTxts,
            'policy_txt_ok'    => $policyIdOk,
            'errors'           => $policyIdOk ? [] : ['mta_sts_dns_invalid'],
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
         * MTA-STS HTTPS policy fetch (skipped)
         * ========================= */
        $policyUrl = 'https://mta-sts.monkeysmail.com/.well-known/mta-sts.txt'; // keep your managed URL
        $records['mta_sts_policy'] = [
            'status' => 'skipped',
            'url'    => $policyUrl,
            'http'   => null,
            'parsed' => null,
            'errors' => [],
        ];
        // keep exposing for UI if you want:
        $records['mta_sts_policy_url'] = $policyUrl;

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

        $stsAllOk = (
            (($records['mta_sts_dns']['status'] ?? '') === 'pass') &&
            (($records['mta_sts_acme']['status'] ?? '') === 'pass')
            // NOTE: policy is skipped, not required
        );

        $records['mta_sts'] = [
            'status'   => $stsAllOk ? 'pass' : 'fail',
            'host'     => $stsHost,
            'expected' => [
                'policy_txt' => [
                    'type'  => 'TXT',
                    'name'  => $stsTxtName,
                    'value' => $expectedPolicyVal,
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
                'policy_txt_found' => $records['mta_sts_dns']['policy_txt_found'] ?? [],
                'acme_cname_found' => $records['mta_sts_acme']['cname_found'] ?? [],
                'policy_url'       => $policyUrl,
            ],
            'errors'   => array_values(array_merge(
                (($records['mta_sts_dns']['status'] ?? '') === 'pass')  ? [] : (array)($records['mta_sts_dns']['errors']  ?? []),
                (($records['mta_sts_acme']['status'] ?? '') === 'pass') ? [] : (array)($records['mta_sts_acme']['errors'] ?? []),
            )),
        ];

        /* --------------------------- Required rules (policy) --------------------------- */
        $requireDkim = true;  // adjust if DKIM should be advisory only
        $required = [
            'verification_txt' => true,
            'spf'              => true,
            'dmarc'            => true,
            'mx'               => false,
            'dkim'             => $requireDkim,
            'tlsrpt'           => true,
            'mta_sts_dns'      => true,   // TXT only
            'mta_sts_acme'     => true,   // keep ACME CNAME required (or set false if optional)
            'mta_sts_policy'   => false,  // not required
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
                    'value'    => $expectedPolicyVal,
                    'ttl'      => 3600,
                    'priority' => null,
                ],
                [
                    'type'     => 'TXT',
                    'name'     => $stsTxtName,
                    'value'    => $expectedPolicyVal,
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

        $foundTxts = $this->txtValues($apex);
        $errors    = [];

        // Parse expected once
        $exp = $this->parseSpf($expected);
        if (!$exp['valid']) {
            return [
                'status' => 'fail',
                'host'   => $apex,
                'expected' => $expected,
                'found'  => $foundTxts,
                'errors' => ['expected_spf_invalid_syntax'],
            ];
        }

        // Evaluate each found SPF; pass if any matches the expected (subset check)
        foreach ($foundTxts as $txt) {
            $spf = $this->parseSpf($txt);
            if (!$spf['valid']) {
                $errors[] = 'found_spf_invalid_syntax';
                continue;
            }
            if ($this->spfSupersetMatches($spf, $exp)) {
                return [
                    'status'   => 'pass',
                    'host'     => $apex,
                    'expected' => $expected,
                    'found'    => $foundTxts,
                    'errors'   => [],
                ];
            }
        }

        // No found SPF satisfied expected
        return [
            'status'   => 'fail',
            'host'     => $apex,
            'expected' => $expected,
            'found'    => $foundTxts,
            'errors'   => empty($foundTxts) ? ['spf_missing'] : array_values(array_unique($errors + ['spf_not_matching'])),
        ];
    }

    /**
     * Parse an SPF string into mechanisms and final-all qualifier.
     * Returns ['valid'=>bool, 'mechs'=>array<string,true>, 'includes'=>array<string,true>, 'ip4'=>array<string,true>, 'ip6'=>array<string,true>, 'all'=>string|null]
     */
    private function parseSpf(string $s): array
    {
        $out = [
            'valid'    => false,
            'mechs'    => [],           // e.g. ['a'=>true,'mx'=>true]
            'includes' => [],           // e.g. ['monkeysmail.com'=>true]
            'ip4'      => [],           // e.g. ['34.30.122.164'=>true, '203.0.113.0/24'=>true]
            'ip6'      => [],
            'all'      => null,         // one of '-', '~', '?', '+' or null if no all
        ];

        $s = strtolower(trim($s, " \t\r\n\"'"));
        if ($s === '' || !str_starts_with($s, 'v=spf1')) {
            return $out;
        }

        $tokens = preg_split('~\s+~', $s) ?: [];
        array_shift($tokens); // drop v=spf1

        foreach ($tokens as $tok) {
            $tok = trim($tok);
            if ($tok === '') continue;

            // qualifier (optional) then mechanism
            // examples: -all, ~all, include:example.com, ip4:1.2.3.4/24, a, mx
            if (preg_match('~^([+\-~?])?all$~', $tok, $m)) {
                $out['all'] = $m[1] !== '' ? $m[1] : '+';
                continue;
            }
            if (preg_match('~^([+\-~?])?include:(.+)$~', $tok, $m)) {
                $host = trim($m[2], '.');
                if ($host !== '') $out['includes'][$host] = true;
                $out['mechs']['include'] = true;
                continue;
            }
            if (preg_match('~^([+\-~?])?ip4:([0-9./]+)$~', $tok, $m)) {
                $out['ip4'][$m[2]] = true;
                $out['mechs']['ip4'] = true;
                continue;
            }
            if (preg_match('~^([+\-~?])?ip6:([0-9a-f:/]+)$~', $tok, $m)) {
                $out['ip6'][$m[2]] = true;
                $out['mechs']['ip6'] = true;
                continue;
            }
            if (preg_match('~^([+\-~?])?(a|mx|ptr|exists|redirect=.+)$~', $tok, $m)) {
                // Coarse record of presence; subset logic below cares mainly about ip4/ip6/include and all
                $key = $m[2];
                $out['mechs'][$key] = true;
                continue;
            }
            // Unknown token: ignore for tolerance
        }

        $out['valid'] = true;
        return $out;
    }

    /**
     * Returns true if $found is a superset of $expected, with tolerant ALL:
     * - If expected has "-all", found may have "-all" OR "~all"
     * - If expected has "~all" or no "all", found may have any "all" (or none)
     * - All expected ip4/ip6/include entries must appear in found (exact text match)
     */
    private function spfSupersetMatches(array $found, array $expected): bool
    {
        if (!$found['valid'] || !$expected['valid']) return false;

        // 1) ip4
        foreach (array_keys($expected['ip4']) as $ip4) {
            if (!isset($found['ip4'][$ip4])) return false;
        }
        // 2) ip6
        foreach (array_keys($expected['ip6']) as $ip6) {
            if (!isset($found['ip6'][$ip6])) return false;
        }
        // 3) includes
        foreach (array_keys($expected['includes']) as $host) {
            if (!isset($found['includes'][$host])) return false;
        }

        // 4) ALL qualifier tolerance
        $expAll = $expected['all']; // '-', '~', '?', '+', or null
        $fndAll = $found['all'];    // same

        if ($expAll === '-') {
            // expected hardfail; accept found hardfail or softfail
            if (!in_array($fndAll, ['-', '~'], true)) return false;
        } elseif ($expAll === '~') {
            // expected softfail; accept any all (or none)
            // (no check)
        } elseif ($expAll === '+' || $expAll === '?' || $expAll === null) {
            // no restriction
        }

        return true;
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
        // normalize expected
        $exp = array_map(function ($r) {
            return [
                'host'     => strtolower(trim((string)($r['host'] ?? ''))),
                'value'    => rtrim(strtolower(trim((string)($r['value'] ?? ''))), '.') . '.', // canonical fqdn
                'priority' => (int)($r['priority'] ?? 10),
            ];
        }, $expected);

        // fetch actual MX
        $recs  = dns_get_record($apex, DNS_MX) ?: [];
        $found = [];
        foreach ($recs as $r) {
            $target = rtrim(strtolower((string)($r['target'] ?? '')), '.') . '.';
            $found[] = [
                'host'     => strtolower($apex),
                'value'    => $target,
                'priority' => (int)($r['pri'] ?? 10),
            ];
        }

        // helper: follow CNAME chain up to 5 hops to canonical fqdn
        $resolveCanonical = function (string $fqdn): string {
            $seen = [];
            $cur  = $fqdn;
            for ($i = 0; $i < 5; $i++) {
                $key = rtrim(strtolower($cur), '.') . '.';
                if (isset($seen[$key])) break;
                $seen[$key] = true;
                $cn = dns_get_record($key, DNS_CNAME) ?: [];
                if (empty($cn)) break;
                $cur = rtrim(strtolower((string)($cn[0]['target'] ?? $cur)), '.') . '.';
            }
            return rtrim(strtolower($cur), '.') . '.';
        };

        // helper: resolve A/AAAA set (for last-resort equivalence)
        $resolveIps = function (string $fqdn): array {
            $fqdn = rtrim(strtolower($fqdn), '.') . '.';
            $ips  = [];
            foreach (dns_get_record($fqdn, DNS_A) ?: [] as $a) {
                if (!empty($a['ip'])) $ips[] = 'A:' . $a['ip'];
            }
            foreach (dns_get_record($fqdn, DNS_AAAA) ?: [] as $aaaa) {
                if (!empty($aaaa['ipv6'])) $ips[] = 'AAAA:' . $aaaa['ipv6'];
            }
            sort($ips);
            return $ips;
        };

        // try to match expected with found (allowing CNAME aliasing or IP equivalence)
        $missing = [];
        foreach ($exp as $er) {
            $wantVal = rtrim(strtolower($er['value']), '.') . '.';
            $wantCanon = $resolveCanonical($wantVal);
            $wantIps   = $resolveIps($wantCanon);

            $match = false;
            foreach ($found as $fr) {
                if ((int)$fr['priority'] !== (int)$er['priority']) continue;

                $gotVal   = rtrim(strtolower($fr['value']), '.') . '.';
                $gotCanon = $resolveCanonical($gotVal);

                if ($gotVal === $wantVal || $gotCanon === $wantCanon) { $match = true; break; }

                // last-resort: same IP set
                if (!empty($wantIps)) {
                    $gotIps = $resolveIps($gotCanon);
                    if ($gotIps === $wantIps) { $match = true; break; }
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
