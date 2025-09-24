<?php
// src/Service/Ports/SystemMailSender.php
declare(strict_types=1);

namespace App\Service\Ports;

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

final class SystemMailSender implements MailSender
{
    public function __construct(private ?PHPMailer $mailer = null) {}

    /** Send *raw* payload as built by InternalMailService::sendSystem()
     * @throws Exception
     */
    public function sendRaw(array $payload): void
    {
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host       = 'smtp.monkeysmail.com';
            $mail->Port       = 587;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Username   = 'smtpuser';
            $mail->Password   = 'S3cureP@ssw0rd';
            $mail->Timeout    = 15;

            // From / Reply-To
            $mail->setFrom($payload['from_email'], $payload['from_name']);
            $mail->addReplyTo($payload['reply_to']);

            // To
            foreach ($payload['to'] as $rcpt) {
                $mail->addAddress($rcpt);
            }

            // Headers
            if (!empty($payload['headers'])) {
                foreach ($payload['headers'] as $k => $v) {
                    $mail->addCustomHeader($k, $v);
                }
            }

            $mail->Subject = $payload['subject'];
            $mail->Body    = $payload['html_body'];
            $mail->AltBody = $payload['text_body'];

            $mail->isHTML(true);

            $mail->send();
        } catch (Exception $e) {
            throw new \RuntimeException("System mail send failed: {$e->getMessage()}", 500, $e);
        }
    }

    /** Kept for compatibility if something calls send(Message $msg, array $envelope)
     * @throws Exception
     */
    public function send($message, array $envelope = []): array
    {
        $payload = [
            'from_email' => $message->getFrom_email() ?? $envelope['fromEmail'] ?? '',
            'from_name'  => $message->getFrom_name()  ?? $envelope['fromName']  ?? null,
            'reply_to'   => $message->getReply_to()   ?? $envelope['replyTo']   ?? null,
            'to'         => $envelope['to'] ?? [],
            'subject'    => $message->getSubject(),
            'html_body'  => $message->getHtml_body(),
            'text_body'  => $message->getText_body(),
            'headers'    => $envelope['headers'] ?? [],
        ];
        return $this->sendRaw($payload);
    }
}
