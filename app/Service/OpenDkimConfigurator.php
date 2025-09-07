<?php
declare(strict_types=1);

namespace App\Service;

final class OpenDkimConfigurator
{
    public function __construct(
        private string $keyTable     = '/etc/opendkim/KeyTable',
        private string $signingTable = '/etc/opendkim/SigningTable',
        private string $serviceName  = 'opendkim',
    ) {}

    public function ensureDomain(string $domain, string $selector, string $privatePath): void
    {
        $label  = sprintf('%s._domainkey.%s', $selector, $domain);
        $kLine  = sprintf("%s %s:%s:%s\n", $label, $domain, $selector, $privatePath);
        $sLine  = sprintf("*@%s %s\n", $domain, $label);

        $this->appendUniqueAtomic($this->keyTable, $kLine);
        $this->appendUniqueAtomic($this->signingTable, $sLine);

        $this->reloadService();
    }

    private function appendUniqueAtomic(string $file, string $line): void
    {
        $dir = \dirname($file);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0750, true)) {
                throw new \RuntimeException("Cannot create directory $dir");
            }
        }

        // Fast pre-check with good error
        if (file_exists($file) && !is_writable($file)) {
            $u = function_exists('posix_geteuid') ? (string)posix_geteuid() : 'unknown';
            $user = function_exists('posix_getpwuid') ? (posix_getpwuid((int)$u)['name'] ?? $u) : $u;
            throw new \RuntimeException("Cannot write $file (user=$user). Fix ownership/permissions.");
        }
        if (!file_exists($file) && !is_writable($dir)) {
            throw new \RuntimeException("Directory $dir not writable to create file " . basename($file));
        }

        $fh = @fopen($file, 'c+'); // create if missing
        if ($fh === false) {
            throw new \RuntimeException("Failed to open $file for writing");
        }

        try {
            if (!flock($fh, LOCK_EX)) {
                throw new \RuntimeException("Failed to lock $file");
            }

            // Read current contents while locked
            $current = stream_get_contents($fh);
            if ($current === false) $current = '';
            $current = (string)$current;

            if (!str_contains($current, rtrim($line))) {
                // Move pointer to end & append
                fseek($fh, 0, SEEK_END);
                if (fwrite($fh, $line) === false) {
                    throw new \RuntimeException("Failed to write to $file");
                }
                fflush($fh); // flush to disk
            }
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    private function reloadService(): void
    {
        // Prefer systemd, but fall back to init scripts if needed
        $cmds = [
            'systemctl reload ' . escapeshellarg($this->serviceName),
            'service ' . escapeshellarg($this->serviceName) . ' reload',
        ];

        $errors = [];
        foreach ($cmds as $cmd) {
            $out = [];
            $rc  = 0;
            @exec($cmd . ' 2>&1', $out, $rc);
            if ($rc === 0) return;
            $errors[] = $cmd . ': ' . implode(' | ', $out);
        }
        throw new \RuntimeException("Failed to reload {$this->serviceName}. Tried: " . implode(' || ', $errors));
    }
}
