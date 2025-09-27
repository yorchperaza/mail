<?php
declare(strict_types=1);

namespace App\Service;

final class OpenDkimRegistrar
{
    public function __construct(
        private string $keyTable     = '/var/lib/monkeysmail/opendkim/keytable',
        private string $signingTable = '/var/lib/monkeysmail/opendkim/signingtable',
        private ?string $pidFile     = '/run/opendkim/opendkim.pid', // adjust if yours differs
        private ?string $sudoKillCmd = 'sudo /bin/kill -HUP',        // see sudoers note below
    ) {}

    public function register(string $domain, string $selector, string $privateKeyPath): void
    {
        $domain   = strtolower($domain);
        $selector = strtolower($selector);

        $ktLine = sprintf(
            "%s._domainkey.%s %s:%s:%s",
            $selector, $domain, $domain, $selector, $privateKeyPath
        );
        $stLine = sprintf(
            "*@%s %s._domainkey.%s",
            $domain, $selector, $domain
        );

        $this->appendOnce($this->keyTable, $ktLine);
        $this->appendOnce($this->signingTable, $stLine);

        $this->hupOpenDkim();
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
            $current = stream_get_contents($fh);
            if (!str_contains($current ?: '', $line)) {
                // ensure newline before appending
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
        // make sure opendkim can read
        @chmod($file, 0644);
    }

    private function hupOpenDkim(): void
    {
        // Best-effort: if we can read a PID and have sudo configured, send HUP
        if (!$this->pidFile || !is_file($this->pidFile)) return;
        $pid = trim((string)@file_get_contents($this->pidFile));
        if ($pid === '' || !ctype_digit($pid)) return;

        // Requires a sudoers rule for the app user, e.g.:
        //   www-data ALL=(root) NOPASSWD: /bin/kill -HUP *
        // or stricter: point to the exact PID read command via a wrapper.
        $cmd = sprintf('%s %s', $this->sudoKillCmd, escapeshellarg($pid));
        @exec($cmd, $_, $rc); // ignore failures; milter will pick up on next restart anyway
    }
}
