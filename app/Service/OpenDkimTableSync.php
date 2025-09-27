<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DkimKey;
use App\Entity\Domain;
use MonkeysLegion\Repository\RepositoryFactory;
use MonkeysLegion\Query\QueryBuilder;

final class OpenDkimTableSync
{
    private const KEY_TABLE     = '/etc/opendkim/keytable';
    private const SIGNING_TABLE = '/etc/opendkim/signingtable';
    private const TRUSTED_HOSTS = '/etc/opendkim/trustedhosts';

    public function __construct(
        private RepositoryFactory $repos,
        private QueryBuilder      $qb,
    ) {}

    /** Always get PDO from MonkeysLegion QueryBuilder (framework standard). */
    private function pdo(): \PDO
    {
        return $this->qb->pdo();
    }

    public function syncTables(): array
    {
        $t0 = microtime(true);
        $result = [
            'success' => false,
            'domains_synced' => 0,
            'errors' => [],
            'keytable_entries' => [],
            'signingtable_entries' => [],
        ];

        try {
            // Make sure repos resolve (sanity check)
            $this->repos->getRepository(DkimKey::class);
            $this->repos->getRepository(Domain::class);
        } catch (\Throwable $e) {
            $msg = '[OpenDkimTableSync] repo error: ' . $e->getMessage();
            error_log($msg);
            $result['errors'][] = $msg;
            return $result;
        }

        // --- Query active DKIM keys via PDO from QB
        $pdo = $this->pdo();
        $sql = <<<SQL
SELECT
  dk.id,
  dk.selector,
  dk.private_key_ref,
  dk.txt_value,
  d.domain   AS domain_name,
  d.id       AS domain_id
FROM dkim_key dk
INNER JOIN domain d ON dk.domain_id = d.id
WHERE dk.active = :dk_active
  AND d.is_active = :dom_active
ORDER BY d.domain ASC, dk.selector ASC
SQL;

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':dk_active', 1, \PDO::PARAM_INT);
            $stmt->bindValue(':dom_active', 1, \PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            $msg = '[OpenDkimTableSync] query error: ' . $e->getMessage();
            error_log($msg);
            $result['errors'][] = $msg;
            return $result;
        }

        if (!$rows) {
            $msg = '[OpenDkimTableSync] No active DKIM keys found';
            error_log($msg);
            $result['errors'][] = 'No active DKIM keys found';
            return $result;
        }

        $keyTableLines = [];
        $signingTableLines = [];
        $processedDomains = [];
        $seenKeyNames = [];
        $seenSigningPairs = [];

        foreach ($rows as $r) {
            $domain   = strtolower(trim((string)($r['domain_name'] ?? '')));
            $selector = trim((string)($r['selector'] ?? ''));
            $keyPath  = trim((string)($r['private_key_ref'] ?? ''));

            if ($domain === '' || $selector === '' || $keyPath === '') {
                $err = "Skipping invalid row: domain/selector/keyPath missing (domain='{$domain}', selector='{$selector}')";
                error_log("[OpenDkimTableSync] {$err}");
                $result['errors'][] = $err;
                continue;
            }

            if (!preg_match('/^[A-Za-z0-9-]{1,63}$/', $selector)) {
                $err = "Skipping invalid selector '{$selector}' for domain '{$domain}'";
                error_log("[OpenDkimTableSync] {$err}");
                $result['errors'][] = $err;
                continue;
            }

            if (!is_file($keyPath)) {
                $err = "Key not found: {$keyPath} for {$domain}";
                error_log("[OpenDkimTableSync] {$err}");
                $result['errors'][] = $err;
                continue;
            }

            $this->ensureKeyPermissions($keyPath);

            $keyName = "{$domain}.{$selector}";
            $ktLine  = "{$keyName} {$domain}:{$selector}:{$keyPath}";
            $stLine1 = "*@{$domain} {$keyName}";
            $stLine2 = "*@*.{$domain} {$keyName}";

            if (!isset($seenKeyNames[$keyName])) {
                $keyTableLines[] = $ktLine;
                $seenKeyNames[$keyName] = true;
                $result['keytable_entries'][] = $keyName;
            }
            if (!isset($seenSigningPairs[$stLine1])) {
                $signingTableLines[] = $stLine1;
                $seenSigningPairs[$stLine1] = true;
                $result['signingtable_entries'][] = "*@{$domain}";
            }
            if (!isset($seenSigningPairs[$stLine2])) {
                $signingTableLines[] = $stLine2;
                $seenSigningPairs[$stLine2] = true;
            }

            $processedDomains[$domain] = true;
        }

        if (!$keyTableLines) {
            $result['errors'][] = 'No valid keys to sync';
            return $result;
        }

        try {
            $this->writeFile(self::KEY_TABLE, implode("\n", $keyTableLines));
            $this->writeFile(self::SIGNING_TABLE, implode("\n", $signingTableLines));

            $trustedHosts = array_unique([
                '127.0.0.1',
                'localhost',
                '::1',
                '34.30.122.164',
                'smtp.monkeysmail.com',
                '*.monkeysmail.com',
            ]);
            $this->writeFile(self::TRUSTED_HOSTS, implode("\n", $trustedHosts));

            $this->reloadOpenDkim();
        } catch (\Throwable $e) {
            $msg = '[OpenDkimTableSync] write/reload error: ' . $e->getMessage();
            error_log($msg);
            $result['errors'][] = $msg;
            return $result;
        }

        $result['success'] = true;
        $result['domains_synced'] = count($processedDomains);

        $dt = (int)round((microtime(true) - $t0) * 1000);
        error_log("[OpenDkimTableSync] Successfully synced {$result['domains_synced']} domains in {$dt}ms");

        return $result;
    }

    private function ensureKeyPermissions(string $keyPath): void
    {
        @chmod($keyPath, 0640);
        if (function_exists('posix_getgrnam')) {
            $grp = @posix_getgrnam('opendkim');
            if ($grp && isset($grp['name'])) {
                @chgrp($keyPath, $grp['name']);
            }
        }
    }

    private function writeFile(string $path, string $content): void
    {
        $content = rtrim($content) . "\n";
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            throw new \RuntimeException("Cannot create directory: {$dir}");
        }

        $tmp = $path . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
            throw new \RuntimeException("Failed to write temp file: {$tmp}");
        }

        @chmod($tmp, 0644);
        @chown($tmp, 'root');
        @chgrp($tmp, 'opendkim');

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("Failed to update: {$path}");
        }
    }

    private function reloadOpenDkim(): void
    {
        exec('systemctl reload opendkim 2>&1', $out, $rc);
        if ($rc === 0) { error_log("[OpenDkimTableSync] Reloaded via systemctl"); return; }

        exec('service opendkim reload 2>&1', $out, $rc);
        if ($rc === 0) { error_log("[OpenDkimTableSync] Reloaded via service"); return; }

        exec('pkill -USR1 -x opendkim 2>&1');
        error_log("[OpenDkimTableSync] Sent SIGUSR1 to opendkim");
    }
}
