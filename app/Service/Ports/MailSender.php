<?php
declare(strict_types=1);

namespace App\Service\Ports;

use App\Entity\Message;

interface MailSender
{
    /**
     * Sends the message via SMTP (or provider).
     * Return array like: ['ok'=>bool, 'message_id'=>string|null, 'error'=>string|null]
     */
    public function send(Message $msg, array $envelope): array;
}
