<?php

declare(strict_types=1);

namespace App\Service\Infra;

use App\Entity\Message;
use App\Service\Ports\MailSender;
use PHPMailer\PHPMailer\PHPMailer;
use App\Service\DkimKeyService;
use App\Service\OpenDkimRegistrar;

final class PhpMailerMailSender implements MailSender
{
    private array $internalDomains = [
        'monkeysmail.com',
        'notify.monkeysmail.com',
    ];

    public function __construct(
        private string  $host = 'smtp.monkeysmail.com',
        private int     $port = 587,
        private string  $secure = 'tls',
        private ?string $username = null,
        private ?string $password = null,
        private int     $timeout = 15,
    )
    {
    }

    public function sendRaw(array $data): array
    {
        $mail = new PHPMailer(true);

        try {
            // SMTP settings - using the class properties
            $mail->isSMTP();
            $mail->Host = $this->host;
            $mail->Port = $this->port;
            $mail->SMTPAuth = !empty($this->username);
            $mail->Username = $this->username;
            $mail->Password = $this->password;
            $mail->SMTPSecure = $this->secure === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
            $mail->Timeout = $this->timeout;
            $mail->CharSet = 'UTF-8';

            // From
            $fromEmail = (string)($data['from_email'] ?? '');
            $mail->setFrom($fromEmail, $data['from_name'] ?? '');

            // Reply-to
            if (!empty($data['reply_to'])) {
                $mail->addReplyTo($data['reply_to']);
            }

            // Recipients
            foreach ($data['to'] ?? [] as $email) {
                $mail->addAddress($email);
            }
            foreach ($data['cc'] ?? [] as $email) {
                $mail->addCC($email);
            }
            foreach ($data['bcc'] ?? [] as $email) {
                $mail->addBCC($email);
            }

            // Content
            $mail->Subject = $data['subject'] ?? '';
            $mail->Body    = $data['html_body'] ?? '';
            $mail->AltBody = $data['text_body'] ?? '';
            $mail->isHTML(!empty($data['html_body']));

            // --- DKIM: provision/register for From: domain (milter-first; local fallback opt-in)
            $this->prepareDkimForFrom($fromEmail, $mail);

            $mail->send();

            return ['ok' => true, 'message_id' => $mail->getLastMessageID()];

        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function send(Message $msg, array $envelope): array
    {
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $this->host;
            $mail->Port = $this->port;
            $mail->SMTPAuth = $this->username !== null;
            if ($mail->SMTPAuth) {
                $mail->Username = (string)$this->username;
                $mail->Password = (string)$this->password;
            }
            $mail->Timeout = $this->timeout;
            $mail->SMTPSecure = $this->secure; // 'tls' | 'ssl'
            $mail->CharSet = 'UTF-8';

            // From + Reply-To
            $fromEmail = (string)$msg->getFrom_email();
            $mail->setFrom($fromEmail, $msg->getFrom_name() ?? '');
            if ($msg->getReply_to()) {
                $mail->addReplyTo($msg->getReply_to());
            }

            // Recipients
            foreach (($envelope['to'] ?? []) as $rcpt)  { $mail->addAddress($rcpt); }
            foreach (($envelope['cc'] ?? []) as $rcpt)  { $mail->addCC($rcpt); }
            foreach (($envelope['bcc'] ?? []) as $rcpt) { $mail->addBCC($rcpt); }

            // Headers
            foreach (($envelope['headers'] ?? []) as $k => $v) {
                if (is_string($k) && is_string($v)) $mail->addCustomHeader($k, $v);
            }

            // Subject + body
            $mail->Subject = (string)($msg->getSubject() ?? '');
            $html = (string)($msg->getHtml_body() ?? '');
            $text = (string)($msg->getText_body() ?? '');
            if ($html !== '') {
                $mail->isHTML(true);
                $mail->Body = $html;
                $mail->AltBody = $text;
            } else {
                $mail->Body = $text;
            }

            // Attachments (base64 in DB)
            foreach ((array)($msg->getAttachments() ?? []) as $a) {
                if (!isset($a['filename'], $a['content'])) continue;
                $bin = base64_decode((string)$a['content'], true);
                if ($bin === false) continue;
                $mail->addStringAttachment(
                    $bin,
                    (string)$a['filename'],
                    PHPMailer::ENCODING_BASE64,
                    isset($a['contentType']) ? (string)$a['contentType'] : 'application/octet-stream'
                );
            }

            // --- DKIM: provision/register for From: domain (milter-first; local fallback opt-in)
            $this->prepareDkimForFrom($fromEmail, $mail);

            $ok  = $mail->send();
            $mid = $mail->getLastMessageID() ?: null;

            return ['ok' => (bool)$ok, 'message_id' => $mid, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message_id' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Generates a DKIM key for the From: domain if needed, registers it in OpenDKIM
     * (KeyTable/SigningTable + HUP), and optionally enables PHPMailer local DKIM
     * signing when DKIM_FALLBACK_LOCAL=1.
     */
    /**
     * Generates/registers DKIM for client domains.
     * For internal domains, use the fixed notify.monkeysmail.com key (no generation).
     */
    private function prepareDkimForFrom(string $fromEmail, PHPMailer $mail): void
    {
        try {
            $fromEmail = trim($fromEmail);
            if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
                return;
            }
            $fromDomain = strtolower(substr($fromEmail, strrpos($fromEmail, '@') + 1));
            if ($fromDomain === '') return;

            // ---- INTERNAL PATH: use the known DKIM key (no OpenDKIM table writes here) ----
            if ($this->isInternalDomain($fromDomain)) {
                // Allow overrides via env, but default to your known-good values
                $dkimDomain   = getenv('NOTIFY_DKIM_DOMAIN')   ?: 'notify.monkeysmail.com';
                $dkimSelector = getenv('NOTIFY_DKIM_SELECTOR') ?: 's1';
                $dkimPrivate  = getenv('NOTIFY_DKIM_PRIVATE')  ?: '/etc/opendkim/keys/notify.monkeysmail.com/s1.private';

                // If the private key file is readable, enable PHPMailer local signing.
                // (If not, OpenDKIM milter will still sign using its tables.)
                if (is_readable($dkimPrivate)) {
                    $mail->DKIM_domain   = $dkimDomain;
                    $mail->DKIM_selector = $dkimSelector;
                    $mail->DKIM_private  = $dkimPrivate;
                    $mail->DKIM_identity = $fromEmail;
                }
                // Done: do NOT attempt to generate keys for internal domains.
                return;
            }

            // ---- CLIENT PATH: existing behavior (ensure/generate + register with OpenDKIM) ----

            // Try discover an existing key: /var/lib/monkeysmail/dkim/<domain>.<selector>.key
            $dkimDir  = getenv('DKIM_DIR') ?: '/var/lib/monkeysmail/dkim';
            $existing = glob(rtrim($dkimDir, '/')."/{$fromDomain}.*.key") ?: [];
            $selector = null;
            $privPath = null;

            foreach ($existing as $path) {
                if (preg_match('~^'.preg_quote($fromDomain, '~').'\.([A-Za-z0-9._-]+)\.key$~', basename($path), $m)) {
                    $selector = strtolower($m[1]);
                    $privPath = $path;
                    break;
                }
            }

            if ($selector === null || $privPath === null) {
                // No existing key => generate using your DkimKeyService
                $selector = (string)(getenv('DKIM_SELECTOR') ?: 'monkey');
                $dk       = new DkimKeyService();
                $info     = $dk->ensureKeyForDomain($fromDomain, $selector);
                $privPath = (string)$info['private_path'];
            }

            // Register with OpenDKIM tables (idempotent) and HUP
            $reg = new OpenDkimRegistrar(
                keyTable:     '/var/lib/monkeysmail/opendkim/keytable',
                signingTable: '/var/lib/monkeysmail/opendkim/signingtable',
                pidFile:      '/run/opendkim/opendkim.pid',
                sudoKillCmd:  'sudo /bin/kill -HUP',
            );
            $reg->register($fromDomain, $selector, $privPath);

            // Local-signing fallback only if explicitly enabled
            $fallback = strtolower((string)(getenv('DKIM_FALLBACK_LOCAL') ?: '0'));
            if (in_array($fallback, ['1','true','yes','on'], true) && is_readable($privPath)) {
                $mail->DKIM_domain   = $fromDomain;
                $mail->DKIM_selector = $selector;
                $mail->DKIM_private  = $privPath;
                $mail->DKIM_identity = $fromEmail;
            }
        } catch (\Throwable $e) {
            // Non-fatal: sending can continue and OpenDKIM milter may still sign
            error_log('[DKIM] prepare failed: '.$e->getMessage());
        }
    }

    private function isInternalDomain(string $domain): bool
    {
        $domain = strtolower($domain);
        // exact match only; monkeys.cloud is NOT internal
        return $domain === 'monkeysmail.com' || $domain === 'notify.monkeysmail.com';
    }

}
