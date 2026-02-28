<?php
declare(strict_types=1);

namespace App\Service;

final class DkimKeyService
{
    private string $baseDir;
    private string $opendkimDir = '/etc/opendkim/keys';

    /**
     * Ed25519 SubjectPublicKeyInfo DER prefix (12 bytes).
     * The full SPKI is this prefix + 32-byte raw public key.
     * OID 1.3.101.112 (id-EdDSA / Ed25519).
     */
    private const ED25519_SPKI_PREFIX = "\x30\x2a\x30\x05\x06\x03\x2b\x65\x70\x03\x21\x00";

    public function __construct(?string $baseDir = null)
    {
        $env = getenv('DKIM_DIR') ?: $baseDir;
        $default = '/var/lib/monkeysmail/dkim';
        $local = __DIR__ . '/../../var/dkim';

        $candidate = $env ?: $default;
        if (!self::isWritableDir($candidate)) {
            $candidate = $local;
        }

        $this->baseDir = rtrim($candidate, '/');
        $this->ensureDir($this->baseDir);
    }

    // ──────────────────────────────────────────────────────────────
    //  Public API
    // ──────────────────────────────────────────────────────────────

    /**
     * Generate (or reuse) a DKIM key pair for a domain+selector.
     *
     * @param string $domain    Fully-qualified domain name
     * @param string $selector  DKIM selector (e.g. "monkey")
     * @param string $algorithm "rsa" | "ed25519"  (ed25519 falls back to rsa if unsupported)
     *
     * @return array{
     *     txt_name: string,
     *     txt_value: string,
     *     txt_chunks: string[],
     *     public_pem: string,
     *     private_path: string,
     *     opendkim_path: string,
     *     selector: string,
     *     domain: string,
     *     algorithm: string,
     * }
     */
    public function ensureKeyForDomain(
        string $domain,
        string $selector,
        string $algorithm = 'ed25519',
    ): array {
        $domain = strtolower(trim($domain));
        $selector = strtolower(trim($selector));

        if ($domain === '' || !preg_match('~^[a-z0-9.-]+\.[a-z]{2,}$~i', $domain)) {
            throw new \InvalidArgumentException("Invalid domain: {$domain}");
        }
        if ($selector === '' || !preg_match('~^[a-z0-9._-]+$~i', $selector)) {
            throw new \InvalidArgumentException("Invalid DKIM selector: {$selector}");
        }

        // Resolve algorithm (fallback ed25519 → rsa when unsupported)
        $algorithm = strtolower(trim($algorithm));
        if ($algorithm === 'ed25519' && !self::ed25519Supported()) {
            $algorithm = 'rsa';
        }
        if (!in_array($algorithm, ['rsa', 'ed25519'], true)) {
            $algorithm = 'rsa';
        }

        $safeDomain = preg_replace('~[^a-z0-9.-]~i', '_', $domain);
        $keyPath = "{$this->baseDir}/{$safeDomain}.{$selector}.key";
        $opendkimKeyPath = "{$this->opendkimDir}/{$domain}/{$selector}.private";

        // ── Generate private key if it doesn't exist ────────────
        if (!is_file($keyPath)) {
            $privPem = $this->generatePrivateKey($algorithm);
            $this->atomicWrite($keyPath, $privPem);
        } else {
            @chmod($keyPath, 0640);
        }

        // Best-effort link into /etc/opendkim/keys
        $this->maybeLinkIntoOpendkim($keyPath, $opendkimKeyPath);

        // ── Derive public key & build TXT record ────────────────
        $privPem = @file_get_contents($keyPath);
        if ($privPem === false) {
            throw new \RuntimeException("Unable to read DKIM key: {$keyPath}");
        }

        $priv = \openssl_pkey_get_private($privPem);
        if ($priv === false) {
            throw new \RuntimeException('OpenSSL failed to parse private key');
        }
        $details = \openssl_pkey_get_details($priv);
        $pubPem = $details['key'] ?? null;
        if (!$pubPem) {
            throw new \RuntimeException('OpenSSL failed to derive public key');
        }

        // Correct p= derivation via DER
        if ($algorithm === 'ed25519') {
            $pubB64 = self::ed25519PemToRawBase64($pubPem);
            $kTag = 'ed25519';
        } else {
            $pubB64 = self::pemPublicKeyToDkimBase64($pubPem);
            $kTag = 'rsa';
        }

        $txtName = "{$selector}._domainkey.{$domain}";
        $txtValue = "v=DKIM1; k={$kTag}; p={$pubB64}";
        $txtChunks = self::chunkTxtForCloudDns($txtValue);

        return [
            'txt_name' => $txtName,
            'txt_value' => $txtValue,
            'txt_chunks' => $txtChunks,
            'public_pem' => $pubPem,
            'private_path' => $keyPath,
            'opendkim_path' => $opendkimKeyPath,
            'selector' => $selector,
            'domain' => $domain,
            'algorithm' => $algorithm,
        ];
    }

    // ──────────────────────────────────────────────────────────────
    //  Public helpers (static, testable)
    // ──────────────────────────────────────────────────────────────

    /**
     * Convert a PEM public key to a clean base64 DER string for DKIM p=.
     *
     * Decodes the PEM body to raw DER (SubjectPublicKeyInfo) bytes, then
     * re-encodes as a *single* base64 string without line breaks.
     *
     * Why not just strip PEM headers?
     *   Regex-stripping is fragile — the PEM body contains line breaks
     *   every 64 chars; if any slip through they break DKIM TXT parsing.
     *   Decode→re-encode guarantees a clean, single-line base64 string.
     */
    public static function pemPublicKeyToDkimBase64(string $pubPem): string
    {
        // Remove PEM header, footer, and all whitespace to get raw base64
        $b64 = preg_replace('~-----[^-]+-----|[\r\n\s]~', '', $pubPem);
        if ($b64 === '' || $b64 === null) {
            throw new \RuntimeException('Empty PEM public key body');
        }

        // Decode to raw DER bytes
        $der = base64_decode($b64, true);
        if ($der === false || $der === '') {
            throw new \RuntimeException('Failed to decode PEM base64 to DER');
        }

        // Re-encode as single continuous base64 (no line breaks)
        return base64_encode($der);
    }

    /**
     * For Ed25519 DKIM (RFC 8463), p= is the raw 32-byte public key,
     * NOT the full SubjectPublicKeyInfo.
     *
     * The SPKI DER for Ed25519 is always 44 bytes:
     *   12-byte prefix (OID 1.3.101.112) + 32-byte raw key.
     */
    public static function ed25519PemToRawBase64(string $pubPem): string
    {
        $spkiB64 = preg_replace('~-----[^-]+-----|[\r\n\s]~', '', $pubPem);
        $spkiDer = base64_decode($spkiB64 ?? '', true);

        if ($spkiDer === false || strlen($spkiDer) < 44) {
            throw new \RuntimeException('Invalid Ed25519 SubjectPublicKeyInfo DER');
        }

        // Verify the SPKI prefix matches Ed25519
        $prefix = substr($spkiDer, 0, 12);
        if ($prefix !== self::ED25519_SPKI_PREFIX) {
            throw new \RuntimeException('DER prefix does not match Ed25519 SPKI');
        }

        // Extract raw 32-byte public key (last 32 bytes)
        $raw = substr($spkiDer, -32);
        if (strlen($raw) !== 32) {
            throw new \RuntimeException('Ed25519 raw public key must be exactly 32 bytes');
        }

        return base64_encode($raw);
    }

    /**
     * Split a DKIM TXT value into chunks safe for Cloud DNS (≤255 char-strings).
     *
     * Google Cloud DNS rrdata for TXT records is an array of quoted strings.
     * Each string must be ≤ 255 bytes (DNS character-string limit).
     * We use 250 as a safety margin.
     *
     * @return string[] Array of chunks; implode('', $chunks) === $txtValue
     */
    public static function chunkTxtForCloudDns(string $txtValue, int $maxLen = 250): array
    {
        if ($maxLen < 1 || $maxLen > 255) {
            $maxLen = 250;
        }

        $chunks = [];
        $len = strlen($txtValue);

        for ($i = 0; $i < $len; $i += $maxLen) {
            $chunks[] = substr($txtValue, $i, $maxLen);
        }

        return $chunks ?: [$txtValue];
    }

    /**
     * Check whether PHP's OpenSSL supports Ed25519.
     */
    public static function ed25519Supported(): bool
    {
        // OPENSSL_KEYTYPE_ED25519 was added experimentally; check constant exists
        // and OpenSSL >= 1.1.1 which includes Ed25519 support.
        if (!defined('OPENSSL_KEYTYPE_ED25519')) {
            return false;
        }
        // Quick smoke test: try to create an Ed25519 key
        $res = @\openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_ED25519]);
        return $res !== false;
    }

    // ──────────────────────────────────────────────────────────────
    //  Private helpers
    // ──────────────────────────────────────────────────────────────

    private function generatePrivateKey(string $algorithm): string
    {
        if ($algorithm === 'ed25519') {
            $res = \openssl_pkey_new([
                'private_key_type' => OPENSSL_KEYTYPE_ED25519,
            ]);
        } else {
            $res = \openssl_pkey_new([
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]);
        }

        if ($res === false) {
            throw new \RuntimeException("OpenSSL failed to create {$algorithm} keypair");
        }

        if (!\openssl_pkey_export($res, $privPem)) {
            throw new \RuntimeException("OpenSSL failed to export {$algorithm} private key");
        }

        return $privPem;
    }

    /**
     * Write content to a temp file then atomic rename.  0640 perms.
     */
    private function atomicWrite(string $path, string $content): void
    {
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));

        if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
            throw new \RuntimeException("Unable to write DKIM key temp file: {$tmp}");
        }

        @chmod($tmp, 0640);

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("Unable to move DKIM key into place: {$path}");
        }
    }

    private function maybeLinkIntoOpendkim(string $source, string $target): void
    {
        $targetDir = dirname($target);

        if (!self::isWritableDir($targetDir)) {
            error_log("[DKIM] /etc/opendkim not writable by app; skipping symlink. Using source={$source}");
            return;
        }

        if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            error_log("[DKIM] Unable to create {$targetDir}; skipping symlink. Using source={$source}");
            return;
        }

        // Remove existing wrong link
        if (is_link($target) && readlink($target) !== $source) {
            @unlink($target);
        }

        if (!is_link($target)) {
            if (!@symlink($source, $target)) {
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
        if (is_dir($path))
            return is_writable($path);
        $parent = dirname($path);
        return is_dir($parent) && is_writable($parent);
    }

    private function ensureDir(string $dir): void
    {
        if (is_dir($dir))
            return;
        if (!@mkdir($dir, 02770, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create DKIM directory: {$dir}");
        }
    }
}
