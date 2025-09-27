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
        $env = getenv('DKIM_DIR') ?: $baseDir;
        $default = '/var/lib/monkeysmail/dkim';
        $local   = __DIR__ . '/../../var/dkim';

        $candidate = $env ?: $default;
        if (!self::isWritableDir($candidate)) {
            $candidate = $local;
        }

        $this->baseDir = rtrim($candidate, '/');
        $this->ensureDir($this->baseDir);

        // Ensure OpenDKIM directory exists
        if (!is_dir($this->opendkimDir)) {
            @mkdir($this->opendkimDir, 0755, true);
        }
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

        // OpenDKIM expects keys in its own directory structure
        $opendkimKeyPath = "{$this->opendkimDir}/{$domain}/{$selector}.private";

        if (!is_file($keyPath)) {
            // Generate 2048-bit RSA private key
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

            // Atomic write + secure perms
            $tmp = $keyPath . '.tmp.' . bin2hex(random_bytes(6));
            if (@file_put_contents($tmp, $privPem, LOCK_EX) === false) {
                throw new \RuntimeException("Unable to write DKIM key temp file: {$tmp}");
            }
            @chmod($tmp, 0600);
            if (!@rename($tmp, $keyPath)) {
                @unlink($tmp);
                throw new \RuntimeException("Unable to move DKIM key into place: {$keyPath}");
            }
            @chmod($keyPath, 0640); // Allow opendkim group read
            @chgrp($keyPath, 'opendkim'); // Set group to opendkim
        }

        // Create symlink for OpenDKIM
        $this->ensureOpendkimSymlink($keyPath, $opendkimKeyPath);

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

        // Convert PEM public key to single-line base64 for DKIM
        $pubB64  = preg_replace('~-----.*?-----|\s+~', '', $pubPem);
        $txtName = "{$selector}._domainkey.{$domain}";
        $txtVal  = "v=DKIM1; k=rsa; p={$pubB64}";

        return [
            'txt_name'     => $txtName,
            'txt_value'    => $txtVal,
            'public_pem'   => $pubPem,
            'private_path' => $keyPath,
            'opendkim_path' => $opendkimKeyPath, // Add this for reference
        ];
    }

    private function ensureOpendkimSymlink(string $source, string $target): void
    {
        $targetDir = dirname($target);
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0755, true);
        }

        // Remove existing symlink if it exists and points elsewhere
        if (is_link($target)) {
            if (readlink($target) !== $source) {
                @unlink($target);
            } else {
                return; // Already correct
            }
        }

        // Create symlink
        if (!@symlink($source, $target)) {
            // If symlink fails, try copying instead
            @copy($source, $target);
            @chmod($target, 0640);
            @chgrp($target, 'opendkim');
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
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create DKIM directory: {$dir}");
        }
    }
}