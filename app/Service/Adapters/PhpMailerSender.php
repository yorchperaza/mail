<?php
declare(strict_types=1);

namespace App\Service\Adapters;

use App\Entity\Message;
use App\Service\Ports\MailSender;
use Random\RandomException;

final class PhpMailerSender implements MailSender
{
    /**
     * @throws RandomException
     */
    public function send(Message $msg, array $envelope): array
    {
        // TODO: wrap PHPMailer or your SMTP client here
        // for now, return a fake message id
        return [
            'ok' => true,
            'message_id' => 'fake-' . bin2hex(random_bytes(8)),
        ];
    }
}
