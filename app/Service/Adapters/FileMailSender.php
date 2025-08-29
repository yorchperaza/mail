<?php

// src/Service/Adapters/FileMailSender.php
declare(strict_types=1);

namespace App\Service\Adapters;

use App\Entity\Message;
use App\Service\Ports\MailSender;

final class FileMailSender implements MailSender
{
    public function __construct(
        private string $outDir = '/tmp/dev-mails' // change if you like
    ) {
        if (!is_dir($this->outDir)) {
            @mkdir($this->outDir, 0777, true);
        }
    }

    /**
     * Envelope expected keys:
     *  - fromEmail:string
     *  - fromName:?string
     *  - replyTo:?string
     *  - to:string[]
     *  - cc:string[]
     *  - bcc:string[]
     *  - headers:array<string,string>
     *  - attachments:array<int,array{filename:string,contentType:string,content:string}>
     */
    public function send(Message $message, array $envelope): array
    {
        try {
            $basename = sprintf(
                '%s_%s_%s.eml',
                date('Ymd_His'),
                (string)$message->getId(),
                preg_replace('/[^a-z0-9]+/i', '_', (string)($message->getSubject() ?? 'no_subject'))
            );
            $path = rtrim($this->outDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $basename;

            // Build RFC822-ish .eml
            $fromEmail = (string)($envelope['fromEmail'] ?? '');
            $fromName  = (string)($envelope['fromName'] ?? '');
            $replyTo   = (string)($envelope['replyTo'] ?? '');

            $to  = array_values(array_map('strval', (array)($envelope['to']  ?? [])));
            $cc  = array_values(array_map('strval', (array)($envelope['cc']  ?? [])));
            $bcc = array_values(array_map('strval', (array)($envelope['bcc'] ?? [])));

            $headers = (array)($envelope['headers'] ?? []);

            $lines = [];
            $lines[] = 'From: ' . ($fromName !== '' ? sprintf('"%s" <%s>', $fromName, $fromEmail) : $fromEmail);
            if (!empty($to))  { $lines[] = 'To: '  . implode(', ', $to); }
            if (!empty($cc))  { $lines[] = 'Cc: '  . implode(', ', $cc); }
            if (!empty($bcc)) { $lines[] = 'Bcc: ' . implode(', ', $bcc); }
            if ($replyTo !== '') {
                $lines[] = 'Reply-To: ' . $replyTo;
            }
            foreach ($headers as $k => $v) {
                $k = trim((string)$k);
                if ($k !== '') {
                    $lines[] = sprintf('%s: %s', $k, (string)$v);
                }
            }
            $lines[] = 'Subject: ' . ($message->getSubject() ?? '(no subject)');
            $lines[] = '';
            $lines[] = $message->getText_body() ?: '(no text body)';
            $lines[] = '';
            $lines[] = '--- HTML ---';
            $lines[] = $message->getHtml_body() ?: '(no HTML body)';

            file_put_contents($path, implode("\r\n", $lines));

            // Mark message as "sent" (if your service layer doesn’t)
            $message
                ->setFinal_state('sent')
                ->setSent_at(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->setMessage_id('file:' . basename($path));

            // Return shape compatible with your other senders (array)
            return [
                'ok'         => true,
                'message_id' => $message->getMessage_id(),
                'path'       => $path,
            ];
        } catch (\Throwable $e) {
            return [
                'ok'   => false,
                'error'=> $e->getMessage(),
            ];
        }
    }
}
