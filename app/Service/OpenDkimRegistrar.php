<?php
declare(strict_types=1);

namespace App\Service;

final class OpenDkimRegistrar
{
    private string $trustedHosts = '/var/lib/monkeysmail/opendkim/trustedhosts';

    public function __construct(
        private string $keyTable     = '/var/lib/monkeysmail/opendkim/keytable',
        private string $signingTable = '/var/lib/monkeysmail/opendkim/signingtable',
        private ?string $pidFile     = '/run/opendkim/opendkim.pid',
        private ?string $sudoKillCmd = 'sudo /bin/kill -HUP',
    ) {}

    /**
     * If $privateKeyPath is provided, ensure KeyTable/SigningTable contain it.
     * If it's empty, we'll try to resolve an existing key from KeyTable.
     */
    public function register(string $domain, string $selector, string $privateKeyPath = ''): void
    {
        $domain   = strtolower(trim($domain));
        $selector = strtolower(trim($selector));

        // If caller didn't pass a path, try to discover one from KeyTable.
        if ($privateKeyPath === '' || $privateKeyPath === null) {
            $resolved = $this->resolvePrivateKeyPath($domain, $selector);
            if ($resolved) {
                $privateKeyPath = $resolved;
            }
        }

        // Candidate paths (in order of preference)
        $securePath  = sprintf('/etc/opendkim/keys/%s/%s.private', $domain, $selector);
        $varLibPath  = sprintf('/var/lib/monkeysmail/dkim/%s.%s.key', $domain, $selector);

        // Helper: check if a key file is "safe" for RequireSafeKeys yes
        $isSafeKey = static function (string $path): bool {
            if (!is_file($path)) return false;
            $perms = fileperms($path) & 0o777;
            if ($perms > 0o600) return false; // must be 0600 or more restrictive

            // If POSIX is available, also verify owner is 'opendkim'
            if (function_exists('posix_getpwuid')) {
                $st = @stat($path);
                if ($st !== false) {
                    $owner = @posix_getpwuid($st['uid']);
                    if (is_array($owner) && isset($owner['name']) && $owner['name'] !== 'opendkim') {
                        return false;
                    }
                }
            }
            return true;
        };

        // Decide which path to use
        $usePath = null;

        // 1) Prefer secure /etc/opendkim/keys copy if present and safe
        if ($isSafeKey($securePath)) {
            $usePath = $securePath;
        }

        // 2) Else use provided path if it exists; prefer it if already safe
        if ($usePath === null && is_string($privateKeyPath) && $privateKeyPath !== '' && is_file($privateKeyPath)) {
            $usePath = $privateKeyPath;
        }

        // 3) Else fall back to var/lib path if it exists
        if ($usePath === null && is_file($varLibPath)) {
            $usePath = $varLibPath;
        }

        // Final guard: don't write an empty/invalid KeyTable entry
        if ($usePath === null) {
            throw new \RuntimeException(sprintf(
                'No DKIM private key file found for %s selector %s (looked for %s, %s, and provided path).',
                $domain, $selector, $securePath, $varLibPath
            ));
        }

        // Normalize path if possible (avoid duplicate entries differing only by .. or symlinks)
        $real = @realpath($usePath);
        if (is_string($real) && $real !== '') {
            $usePath = $real;
        }

        // Compose lines (idempotent append)
        $ktLine = sprintf(
            '%s._domainkey.%s %s:%s:%s',
            $selector, $domain, $domain, $selector, $usePath
        );
        $stLine = sprintf(
            '*@%s %s._domainkey.%s',
            $domain, $selector, $domain
        );

        $this->appendOnce($this->keyTable, $ktLine);
        $this->appendOnce($this->signingTable, $stLine);

        // Ensure trusted hosts contains our basics (idempotent)
        $this->ensureTrustedHosts([
            '127.0.0.1',
            '::1',
            'localhost',
            gethostname() ?: 'mta-1.monkeysmail.com',
            getenv('PUBLIC_IP') ?: '34.30.122.164',
        ]);

        $this->hupOpenDkim();
    }

    private function ensureTrustedHosts(array $hosts): void
    {
        $dir = \dirname($this->trustedHosts);
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }

        $fh = @fopen($this->trustedHosts, 'c+');
        if (!$fh) return;
        try {
            flock($fh, LOCK_EX);
            $current = stream_get_contents($fh) ?: '';
            $lines = array_flip(array_map('trim', preg_split('/\R+/', $current) ?: []));
            foreach ($hosts as $h) {
                if ($h !== '' && !isset($lines[$h])) {
                    $current .= ($current && !str_ends_with($current, "\n") ? "\n" : '') . $h . "\n";
                    $lines[$h] = true;
                }
            }
            rewind($fh);
            ftruncate($fh, 0);
            fwrite($fh, $current);
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
        @chmod($this->trustedHosts, 0644);
    }

    /**
     * Try to find an existing entry for domain+selector in KeyTable,
     * returning the private key path if found; otherwise null.
     */
    public function resolvePrivateKeyPath(string $domain, string $selector): ?string
    {
        $domain   = strtolower(trim($domain));
        $selector = strtolower(trim($selector));

        if (!is_file($this->keyTable)) {
            return null;
        }
        $lines = @file($this->keyTable, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $raw) {
            $line = trim($raw);
            if ($line === '' || $line[0] === '#') continue;

            // Expected format:
            // <sel>._domainkey.<domain> <domain>:<sel>:<path>
            $parts = preg_split('/\s+/', $line);
            if (count($parts) < 2) continue;

            $lhs = strtolower($parts[0]);
            $rhs = $parts[1];

            $expectLhs = sprintf('%s._domainkey.%s', $selector, $domain);
            if ($lhs !== $expectLhs) continue;

            // Parse RHS as "domain:selector:/path"
            $rhsParts = explode(':', $rhs, 3);
            if (count($rhsParts) !== 3) continue;

            [$d2, $s2, $path] = $rhsParts;
            if (strtolower($d2) === $domain && strtolower($s2) === $selector && $path !== '') {
                return $path;
            }
        }
        return null;
    }

    /**
     * If DKIM_SELECTOR is not set, try to pick the first existing selector
     * for the domain. Returns [selector, privateKeyPath] or [null, null].
     */
    public function resolveAnySelectorForDomain(string $domain): array
    {
        $domain = strtolower(trim($domain));
        if (!is_file($this->keyTable)) {
            return [null, null];
        }
        $lines = @file($this->keyTable, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $raw) {
            $line = trim($raw);
            if ($line === '' || $line[0] === '#') continue;

            $parts = preg_split('/\s+/', $line);
            if (count($parts) < 2) continue;

            $lhs = strtolower($parts[0]); // sX._domainkey.domain
            $rhs = $parts[1];

            $suffix = '._domainkey.' . $domain;
            if (!str_ends_with($lhs, $suffix)) continue;

            // Extract selector from lhs
            $sel = substr($lhs, 0, -strlen($suffix));
            $rhsParts = explode(':', $rhs, 3);
            if (count($rhsParts) !== 3) continue;

            [$d2, $s2, $path] = $rhsParts;
            if (strtolower($d2) === $domain && $path !== '') {
                return [$sel, $path];
            }
        }
        return [null, null];
    }

    private function appendOnce(string $file, string $line): void
    {
        $dir = \dirname($file);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException("Unable to create dir: {$dir}");
            }
        }
        $fh = @fopen($file, 'c+');
        if (!$fh) {
            throw new \RuntimeException("Cannot open {$file} for updates");
        }
        try {
            if (!flock($fh, LOCK_EX)) {
                throw new \RuntimeException("Cannot lock {$file}");
            }
            $current = stream_get_contents($fh) ?: '';
            if (!str_contains($current, $line)) {
                if ($current !== '' && substr($current, -1) !== "\n") {
                    $current .= "\n";
                }
                $current .= $line . "\n";
                rewind($fh);
                if (ftruncate($fh, 0) === false || fwrite($fh, $current) === false) {
                    throw new \RuntimeException("Failed to write {$file}");
                }
            }
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
        @chmod($file, 0644);
    }

    private function hupOpenDkim(): void
    {
        if (!$this->pidFile || !is_file($this->pidFile)) return;
        $pid = trim((string)@file_get_contents($this->pidFile));
        if ($pid === '' || !ctype_digit($pid)) return;

        $cmd = sprintf('%s %s', $this->sudoKillCmd, escapeshellarg($pid));
        @exec($cmd, $_, $rc);
    }
}
