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

            // ✅ ENHANCED Attachments handling with debugging
            $attachments = $payload['attachments'] ?? [];

            error_log(sprintf('[SystemMailSender][sendRaw] Attachments check: isset=%s, is_array=%s, count=%d',
                isset($payload['attachments']) ? 'YES' : 'NO',
                is_array($attachments) ? 'YES' : 'NO',
                is_array($attachments) ? count($attachments) : 0
            ));

            if (!empty($attachments) && is_array($attachments)) {
                error_log('[SystemMailSender][sendRaw] Processing ' . count($attachments) . ' attachments...');

                foreach ($attachments as $idx => $att) {
                    if (!is_array($att)) {
                        error_log('[SystemMailSender][sendRaw] Attachment index ' . $idx . ' is not array (type=' . gettype($att) . '), skipping');
                        continue;
                    }

                    $filename = trim((string)($att['filename'] ?? ''));
                    $b64      = (string)($att['content'] ?? '');
                    $ctype    = trim((string)(
                        $att['contentType']
                        ?? $att['content_type']
                        ?? $att['type']
                        ?? 'application/octet-stream'
                    ));

                    error_log(sprintf('[SystemMailSender][sendRaw] Attachment %d: filename=%s, contentType=%s, base64Length=%d',
                        $idx, $filename, $ctype, strlen($b64)
                    ));

                    if ($filename === '') {
                        error_log('[SystemMailSender][sendRaw] Attachment index ' . $idx . ' has empty filename, skipping');
                        continue;
                    }

                    if ($b64 === '') {
                        error_log('[SystemMailSender][sendRaw] Attachment index ' . $idx . ' has empty content, skipping');
                        continue;
                    }

                    // Decode base64
                    $binary = base64_decode($b64, true);
                    if ($binary === false) {
                        error_log('[SystemMailSender][sendRaw] Attachment index ' . $idx . ' has invalid base64 content, skipping');
                        continue;
                    }

                    $binarySize = strlen($binary);
                    error_log(sprintf('[SystemMailSender][sendRaw] Decoded attachment %d: %d bytes', $idx, $binarySize));

                    // Add to PHPMailer
                    try {
                        $mail->addStringAttachment($binary, $filename, PHPMailer::ENCODING_BASE64, $ctype);
                        error_log(sprintf('[SystemMailSender][sendRaw] ✅ Successfully added attachment: %s (%s, %d bytes)',
                            $filename, $ctype, $binarySize
                        ));
                    } catch (\Exception $e) {
                        error_log(sprintf('[SystemMailSender][sendRaw] ❌ Failed to add attachment %d: %s',
                            $idx, $e->getMessage()
                        ));
                    }
                }

                error_log(sprintf('[SystemMailSender][sendRaw] Finished processing attachments. PHPMailer attachment count: %d',
                    count($mail->getAttachments())
                ));
            } else {
                error_log('[SystemMailSender][sendRaw] No attachments in payload to process');
            }

            // Send the email
            error_log('[SystemMailSender][sendRaw] Attempting to send email...');
            $ok = $mail->send();
            error_log(sprintf('[SystemMailSender][sendRaw] Send result: %s', $ok ? 'SUCCESS' : 'FAILED'));

            return [
                'ok'         => (bool)$ok,
                'message_id' => $mail->getLastMessageID() ?: null,
            ];
        } catch (Exception $e) {
            error_log('[SystemMailSender][sendRaw] PHPMailer exception: ' . $e->getMessage());
            throw new \RuntimeException("System mail send failed: {$e->getMessage()}", 500, $e);
        } catch (\Throwable $e) {
            error_log('[SystemMailSender][sendRaw] Unexpected exception: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
            throw new \RuntimeException("System mail send failed: {$e->getMessage()}", 500, $e);
        }
    }

    public function send($message, array $envelope = []): array
    {
        error_log('[SystemMailSender][send] Called with message type=' . get_class($message));

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

        // ✅ Enhanced attachments extraction with debugging
        $atts = $get($message, 'getAttachments', null);

        error_log(sprintf('[SystemMailSender][send] Attachments from message: type=%s, empty=%s',
            gettype($atts),
            empty($atts) ? 'YES' : 'NO'
        ));

        if (!empty($envelope['attachments']) && is_array($envelope['attachments'])) {
            error_log('[SystemMailSender][send] Using attachments from envelope: ' . count($envelope['attachments']));
            $payload['attachments'] = $envelope['attachments'];
        } elseif (!empty($atts)) {
            // Handle string (JSON) or array
            if (is_string($atts)) {
                error_log('[SystemMailSender][send] Attachments is string, decoding JSON...');
                $decoded = json_decode($atts, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    error_log('[SystemMailSender][send] JSON decoded successfully: ' . count($decoded) . ' attachments');
                    $atts = $decoded;
                } else {
                    error_log('[SystemMailSender][send] JSON decode failed: ' . json_last_error_msg());
                }
            }

            if (is_array($atts)) {
                error_log('[SystemMailSender][send] Setting attachments array: ' . count($atts) . ' items');
                $payload['attachments'] = $atts;
            }
        } else {
            error_log('[SystemMailSender][send] No attachments found in message or envelope');
        }

        error_log(sprintf('[SystemMailSender][send] Final payload has attachments: %s',
            isset($payload['attachments']) ? 'YES (' . count($payload['attachments']) . ')' : 'NO'
        ));

        return $this->sendRaw($payload);
    }
}