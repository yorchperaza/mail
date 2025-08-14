<?php
declare(strict_types=1);

namespace App\Service;

final class DkimKeyService
{
    private string $baseDir;

    public function __construct(?string $baseDir = null)
    {
        // Prefer env DKIM_DIR; fall back to rspamd path; if not writable, use local storage
        $env = getenv('DKIM_DIR') ?: $baseDir;
        $default = '/var/lib/rspamd/dkim';
        $local   = __DIR__ . '/../../var/dkim'; // project-local, safe for dev

        $candidate = $env ?: $default;
        if (!self::isWritableDir($candidate)) {
            // fallback for local/dev
            $candidate = $local;
        }
        $this->baseDir = rtrim($candidate, '/');
        $this->ensureDir($this->baseDir);
    }

    /**
     * Generate or reuse DKIM key for domain+selector.
     * Writes private key to {baseDir}/{domain}.{selector}.key (0600).
     * Returns TXT {name,value} for publishing.
     */
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
            // Set perms before rename so final file lands correct on some FS
            @chmod($tmp, 0600);
            if (!@rename($tmp, $keyPath)) {
                @unlink($tmp);
                throw new \RuntimeException("Unable to move DKIM key into place: {$keyPath}");
            }
            @chmod($keyPath, 0600);
        }

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
        ];
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