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
     *   headers?:array<string,string>
     * } $payload
     */
    public function sendRaw(array $payload): array
    {
        $mail = $this->mailer ?? new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host          = 'smtp.monkeysmail.com';
            $mail->Port          = 587;
            $mail->SMTPSecure    = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->SMTPAuth      = true;
            $mail->AuthType      = 'LOGIN';
            $mail->Username      = 'smtpuser';
            $mail->Password      = 'S3cureP@ssw0rd';
            $mail->Timeout       = 15;
            $mail->CharSet       = 'UTF-8';
            $mail->SMTPKeepAlive = false;

            // ---- Compliance with server/DNS ----
            // EHLO/HELO should be your SMTP hostname
            $mail->Hostname = 'smtp.monkeysmail.com';
            $mail->Helo     = 'smtp.monkeysmail.com';

            // Envelope-From / Return-Path (MAIL FROM)
            $rcpts = (array)($payload['to'] ?? []);
            $anyInternal = false;
            foreach ($rcpts as $r) {
                if (is_string($r) && str_ends_with(strtolower($r), '@monkeysmail.com')) {
                    $anyInternal = true; break;
                }
            }
            $mail->Sender = $anyInternal ? 'system@monkeysmail.com' : 'bounce@notify.monkeysmail.com';

            // From / Reply-To
            $fromEmail = (string)($payload['from_email'] ?? '');
            $fromName  = (string)($payload['from_name']  ?? '');
            $replyTo   = (string)($payload['reply_to']   ?? '');

            // Force From to the notify subdomain unless already compliant
            $fromIsNotify = filter_var($fromEmail, FILTER_VALIDATE_EMAIL)
                && str_ends_with(strtolower($fromEmail), '@notify.monkeysmail.com');

            if (!$fromIsNotify) {
                $fromEmail = 'no-reply@notify.monkeysmail.com';
            }

            $mail->setFrom($fromEmail, $fromName);
            if ($replyTo !== '') {
                $mail->addReplyTo($replyTo);
            }

            // --- DKIM signing (notify.monkeysmail.com; selector s1) ---
            // Make sure the private key path is readable by the app user.
            $mail->DKIM_domain   = 'notify.monkeysmail.com';
            $mail->DKIM_selector = 's1';
            $mail->DKIM_private  = '/etc/opendkim/keys/notify.monkeysmail.com/s1.private'; // adjust if needed
            $mail->DKIM_identity = $fromEmail;
            // $mail->DKIM_passphrase = ''; // uncomment if your key has a passphrase

            // To
            foreach ((array)$payload['to'] as $rcpt) {
                if ($rcpt !== '') {
                    $mail->addAddress((string)$rcpt);
                }
            }

            // Custom headers (Return-Path is controlled by $mail->Sender)
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

        return $this->sendRaw($payload);
    }
}
