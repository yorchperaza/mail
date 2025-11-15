<?php
// src/Service/Ports/SystemMailSender.php
declare(strict_types=1);

namespace App\Service\Ports;

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

final class SystemMailSender implements MailSender
{
    public function __construct(private ?PHPMailer $mailer = null) {}

    /**
     * @param array{
     *   from_email:string,
     *   from_name?:?string,
     *   reply_to?:?string,
     *   to:array<int,string>,
     *   subject:string,
     *   html_body:string,
     *   text_body?:?string,
     *   headers?:array<string,string>,
     *   attachments?:array<int,array{
     *      filename:string,
     *      content:string,           // base64
     *      contentType?:string,      // MIME
     *      content_type?:string      // fallback snake_case
     *   }>
     * } $payload
     */
    public function sendRaw(array $payload): array
    {
        $mail = $this->mailer ?? new PHPMailer(true);

        // If the same PHPMailer instance is reused, clean it first
        $mail->clearAllRecipients();
        $mail->clearAttachments();
        $mail->clearReplyTos();
        $mail->clearCustomHeaders();

        try {
            $mail->isSMTP();
            // === Notify server only (no client creds here) ===
            $mail->Host          = $_ENV['SYSTEM_SMTP_HOST'] ?? 'notify.monkeysmail.com';
            $mail->Port          = (int)($_ENV['SYSTEM_SMTP_PORT'] ?? 587);
            $mail->SMTPSecure    = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->SMTPAuth      = true;
            $mail->AuthType      = $_ENV['SYSTEM_SMTP_AUTHTYPE'] ?? 'LOGIN';
            $mail->Username      = $_ENV['SYSTEM_SMTP_USER'] ?? 'smtpuser';
            $mail->Password      = $_ENV['SYSTEM_SMTP_PASS'] ?? 'S3cureP@ssw0rd';
            $mail->Timeout       = (int)($_ENV['SYSTEM_SMTP_TIMEOUT'] ?? 15);
            $mail->CharSet       = 'UTF-8';
            $mail->SMTPKeepAlive = false;

            // EHLO/HELO, envelope sender pinned to notify
            $mail->Hostname      = $_ENV['SYSTEM_HELO'] ?? 'notify.monkeysmail.com';
            $mail->Helo          = $_ENV['SYSTEM_HELO'] ?? 'notify.monkeysmail.com';
            $mail->Sender        = $_ENV['SYSTEM_BOUNCE'] ?? 'bounce@notify.monkeysmail.com';

            // From / Reply-To (force notify subdomain if caller passed something else)
            $fromEmail = (string)($payload['from_email'] ?? '');
            $fromName  = (string)($payload['from_name']  ?? '');
            $replyTo   = (string)($payload['reply_to']   ?? '');

            $fromIsNotify = filter_var($fromEmail, FILTER_VALIDATE_EMAIL)
                && str_ends_with(strtolower($fromEmail), '@notify.monkeysmail.com');

            if (!$fromIsNotify) {
                $fromEmail = $_ENV['SYSTEM_FROM_EMAIL'] ?? 'no-reply@notify.monkeysmail.com';
            }

            $mail->setFrom($fromEmail, $fromName);
            if ($replyTo !== '') {
                $mail->addReplyTo($replyTo);
            }

            // To
            foreach ((array)$payload['to'] as $rcpt) {
                if ($rcpt !== '') {
                    $mail->addAddress((string)$rcpt);
                }
            }

            // Headers
            foreach ((array)($payload['headers'] ?? []) as $k => $v) {
                if ($k !== '' && $v !== '') {
                    $mail->addCustomHeader((string)$k, (string)$v);
                }
            }

            // Body
            $mail->Subject = (string)$payload['subject'];
            $mail->isHTML(true);
            $mail->Body    = (string)$payload['html_body'];
            $mail->AltBody = (string)($payload['text_body'] ?? '') ?: strip_tags($mail->Body);

            // ✅ Attachments
            $attachments = $payload['attachments'] ?? [];
            if (!empty($attachments) && is_array($attachments)) {
                error_log('[SystemMailSender] attachments in payload count=' . count($attachments));

                foreach ($attachments as $idx => $att) {
                    if (!is_array($att)) {
                        error_log('[SystemMailSender] attachment index '.$idx.' is not array, skipping');
                        continue;
                    }

                    $filename = trim((string)($att['filename'] ?? 'attachment.bin'));
                    $b64      = (string)($att['content']   ?? '');
                    $ctype    = trim((string)(
                        $att['contentType']
                        ?? $att['content_type']
                        ?? 'application/octet-stream'
                    ));

                    if ($filename === '' || $b64 === '') {
                        error_log('[SystemMailSender] attachment index '.$idx.' has empty filename/content, skipping');
                        continue;
                    }

                    $binary = base64_decode($b64, true);
                    if ($binary === false) {
                        error_log('[SystemMailSender] invalid base64 for attachment index '.$idx.' filename='.$filename);
                        continue;
                    }

                    $mail->addStringAttachment($binary, $filename, 'base64', $ctype);
                    error_log('[SystemMailSender] added attachment idx='.$idx.' filename='.$filename.' type='.$ctype);
                }
            } else {
                error_log('[SystemMailSender] no attachments in payload');
            }

            $ok = $mail->send();

            return [
                'ok'         => (bool)$ok,
                'message_id' => $mail->getLastMessageID() ?: null,
            ];
        } catch (Exception $e) {
            throw new \RuntimeException("System mail send failed: {$e->getMessage()}", 500, $e);
        }
    }

    public function send($message, array $envelope = []): array
    {
        $get = static function ($obj, string $method, $default = null) {
            return (is_object($obj) && method_exists($obj, $method)) ? $obj->{$method}() : $default;
        };

        $payload = [
            'from_email' => $get($message, 'getFrom_email', $envelope['fromEmail'] ?? ''),
            'from_name'  => $get($message, 'getFrom_name',  $envelope['fromName']  ?? ''),
            'reply_to'   => $get($message, 'getReply_to',   $envelope['replyTo']   ?? ''),
            'to'         => (array)($envelope['to'] ?? []),
            'subject'    => (string)$get($message, 'getSubject',   ''),
            'html_body'  => (string)$get($message, 'getHtml_body', ''),
            'text_body'  => (string)$get($message, 'getText_body', ''),
            'headers'    => (array)($envelope['headers'] ?? []),
        ];

        // ✅ Attachments from entity or envelope
        $atts = $get($message, 'getAttachments', null);

        if (!empty($envelope['attachments']) && is_array($envelope['attachments'])) {
            $payload['attachments'] = $envelope['attachments'];
        } elseif (!empty($atts)) {
            if (is_string($atts)) {
                $decoded = json_decode($atts, true);
                if (is_array($decoded)) {
                    $atts = $decoded;
                }
            }
            if (is_array($atts)) {
                $payload['attachments'] = $atts;
            }
        }

        return $this->sendRaw($payload);
    }
}
