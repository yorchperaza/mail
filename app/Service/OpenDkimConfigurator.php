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
        $label = sprintf('%s._domainkey.%s', $selector, $domain);

        $this->ensureLine($this->keyTable,     sprintf("%s %s:%s:%s\n", $label, $domain, $selector, $privatePath));
        $this->ensureLine($this->signingTable, sprintf("*@%s %s\n", $domain, $label));

        // Reload OpenDKIM to pick up changes
        $out = [];
        $rc  = 0;
        exec('systemctl reload ' . escapeshellarg($this->serviceName) . ' 2>&1', $out, $rc);
        if ($rc !== 0) {
            throw new \RuntimeException("Failed to reload opendkim: " . implode("\n", $out));
        }
    }

    private function ensureLine(string $file, string $line): void
    {
        $current = @file_exists($file) ? (string)@file_get_contents($file) : '';
        if (!str_contains($current, trim($line))) {
            if (!@file_put_contents($file, $current . $line)) {
                throw new \RuntimeException("Cannot write $file");
            }
        }
    }
}
