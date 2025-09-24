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
     * Send *raw* payload as built by InternalMailService::sendSystem()
     * Returns a small result array for consistency (ok/message_id).
     *
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
     * @return array{ok:bool,message_id:?string}
     * @throws \RuntimeException
     */
    public function sendRaw(array $payload): array
    {
        $mail = $this->mailer ?? new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host       = 'smtp.monkeysmail.com';
            $mail->Port       = 587;                               // submission
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;    // STARTTLS
            $mail->SMTPAuth   = true;                              // ← REQUIRED
            $mail->AuthType   = 'LOGIN';                           // explicit, optional
            $mail->Username   = 'smtpuser';                        // burned-in creds
            $mail->Password   = 'S3cureP@ssw0rd';
            $mail->Timeout    = 15;
            $mail->CharSet    = 'UTF-8';
            $mail->SMTPKeepAlive = false;

            // From / Reply-To
            $fromEmail = (string)($payload['from_email'] ?? '');
            $fromName  = (string)($payload['from_name']  ?? '');
            $replyTo   = (string)($payload['reply_to']   ?? '');

            $mail->setFrom($fromEmail, $fromName);
            if ($replyTo !== '') {
                $mail->addReplyTo($replyTo);
            }

            // To
            foreach ((array)$payload['to'] as $rcpt) {
                if ($rcpt !== '') $mail->addAddress((string)$rcpt);
            }

            // Custom headers
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

    /**
     * Compatibility path if something calls send(Message $msg, array $envelope)
     * Builds a raw payload and delegates to sendRaw().
     *
     * @param mixed $message  Object with getters or array-like; we only read common fields.
     * @param array $envelope ['to'=>string[], 'fromEmail'?, 'fromName'?, 'replyTo'?, 'headers'?]
     * @return array{ok:bool,message_id:?string}
     */
    public function send($message, array $envelope = []): array
    {
        // Try to read via getters if available; otherwise fall back to envelope.
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
