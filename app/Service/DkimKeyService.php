<?php
declare(strict_types=1);

namespace App\Service;

final class DkimKeyService
{
    private string $baseDir;
    private string $opendkimDir = '/etc/opendkim/keys';

    public function __construct(?string $baseDir = null)
    {
        // Primary storage location
        $env     = getenv('DKIM_DIR') ?: $baseDir;
        $default = '/var/lib/monkeysmail/dkim';
        $local   = __DIR__ . '/../../var/dkim';

        $candidate = $env ?: $default;
        if (!self::isWritableDir($candidate)) {
            // fall back to local project var dir (dev environments)
            $candidate = $local;
        }

        $this->baseDir = rtrim($candidate, '/');
        $this->ensureDir($this->baseDir);

        // Do NOT force-create /etc/opendkim/keys here; that is usually root-only.
        // We will only touch it later if it is writable.
    }

    public function ensureKeyForDomain(string $domain, string $selector): array
    {
        $domain   = strtolower(trim($domain));
        $selector = strtolower(trim($selector));

        if ($domain === '' || !preg_match('~^[a-z0-9.-]+\.[a-z]{2,}$~i', $domain)) {
            throw new \InvalidArgumentException("Invalid domain: {$domain}");
        }
        if ($selector === '' || !preg_match('~^[a-z0-9._-]+$~i', $selector)) {
            throw new \InvalidArgumentException("Invalid DKIM selector: {$selector}");
        }

        $safeDomain = preg_replace('~[^a-z0-9.-]~i', '_', $domain);
        $keyPath    = "{$this->baseDir}/{$safeDomain}.{$selector}.key";

        // Preferred OpenDKIM view (only if writable)
        $opendkimKeyPath = "{$this->opendkimDir}/{$domain}/{$selector}.private";

        if (!is_file($keyPath)) {
            $res = \openssl_pkey_new([
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]);
            if ($res === false) {
                throw new \RuntimeException('OpenSSL failed to create a keypair');
            }
            if (!\openssl_pkey_export($res, $privPem)) {
                throw new \RuntimeException('OpenSSL failed to export private key');
            }

            $tmp = $keyPath . '.tmp.' . bin2hex(random_bytes(6));
            if (@file_put_contents($tmp, $privPem, LOCK_EX) === false) {
                throw new \RuntimeException("Unable to write DKIM key temp file: {$tmp}");
            }

            // 0640 so OpenDKIM (group) can read; setgid dir will make group=opendkim
            @chmod($tmp, 0640);

            if (!@rename($tmp, $keyPath)) {
                @unlink($tmp);
                throw new \RuntimeException("Unable to move DKIM key into place: {$keyPath}");
            }
        } else {
            // Ensure readable perms if an old file exists
            @chmod($keyPath, 0640);
        }

        // Best-effort: create /etc/opendkim/keys symlink ONLY if writable (root prepared it)
        $this->maybeLinkIntoOpendkim($keyPath, $opendkimKeyPath);

        $privPem = @file_get_contents($keyPath);
        if ($privPem === false) {
            throw new \RuntimeException("Unable to read DKIM key: {$keyPath}");
        }

        $priv = \openssl_pkey_get_private($privPem);
        if ($priv === false) {
            throw new \RuntimeException('OpenSSL failed to parse private key');
        }
        $details = \openssl_pkey_get_details($priv);
        $pubPem  = $details['key'] ?? null;
        if (!$pubPem) {
            throw new \RuntimeException('OpenSSL failed to derive public key');
        }

        $pubB64  = preg_replace('~-----.*?-----|\s+~', '', $pubPem);
        $txtName = "{$selector}._domainkey.{$domain}";
        $txtVal  = "v=DKIM1; k=rsa; p={$pubB64}";

        return [
            'txt_name'      => $txtName,
            'txt_value'     => $txtVal,
            'public_pem'    => $pubPem,
            'private_path'  => $keyPath,         // <-- store this in DB (dkim_key.private_key_ref)
            'opendkim_path' => $opendkimKeyPath, // best-effort link location
        ];
    }

    private function maybeLinkIntoOpendkim(string $source, string $target): void
    {
        $targetDir = dirname($target);

        // Only proceed if /etc/opendkim/keys is writable by the app user/group
        if (!self::isWritableDir($targetDir)) {
            // Not an error; OpenDKIM can read $source directly via KeyTable path.
            error_log("[DKIM] /etc/opendkim not writable by app; skipping symlink. Using source={$source}");
            return;
        }

        if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            // If we couldn't create it, just skip (same as above)
            error_log("[DKIM] Unable to create {$targetDir}; skipping symlink. Using source={$source}");
            return;
        }

        // Remove existing wrong link
        if (is_link($target) && readlink($target) !== $source) {
            @unlink($target);
        }

        if (!is_link($target)) {
            if (!@symlink($source, $target)) {
                // fallback: copy (if perms allow)
                if (@copy($source, $target)) {
                    @chmod($target, 0640);
                } else {
                    error_log("[DKIM] Failed to create symlink/copy into {$target}; continuing without it.");
                }
            }
        }
    }

    private static function isWritableDir(string $path): bool
    {
        if (is_dir($path)) return is_writable($path);
        $parent = dirname($path);
        return is_dir($parent) && is_writable($parent);
    }

    private function ensureDir(string $dir): void
    {
        if (is_dir($dir)) return;
        if (!@mkdir($dir, 02770, true) && !is_dir($dir)) { // 02770 to keep setgid when created by app
            throw new \RuntimeException("Cannot create DKIM directory: {$dir}");
        }
        // If the app user is in 'opendkim' group, new files inherit that group due to setgid on parent (/var/lib/monkeysmail/dkim)
    }
}
