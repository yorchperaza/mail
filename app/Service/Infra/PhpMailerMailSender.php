<?php

declare(strict_types=1);

namespace App\Service\Infra;

use App\Entity\Message;
use App\Service\Ports\MailSender;
use PHPMailer\PHPMailer\PHPMailer;

final class PhpMailerMailSender implements MailSender
{
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
            $mail->setFrom($msg->getFrom_email(), $msg->getFrom_name() ?? '');
            if ($msg->getReply_to()) {
                $mail->addReplyTo($msg->getReply_to());
            }

            // Recipients
            foreach (($envelope['to'] ?? []) as $rcpt) $mail->addAddress($rcpt);
            foreach (($envelope['cc'] ?? []) as $rcpt) $mail->addCC($rcpt);
            foreach (($envelope['bcc'] ?? []) as $rcpt) $mail->addBCC($rcpt);

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

            $ok = $mail->send();
            $mid = $mail->getLastMessageID() ?: null;

            return ['ok' => (bool)$ok, 'message_id' => $mid, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message_id' => null, 'error' => $e->getMessage()];
        }
    }
}
