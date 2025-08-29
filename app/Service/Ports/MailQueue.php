<?php
declare(strict_types=1);

namespace App\Service\Ports;

interface MailQueue
{
    public function enqueue(array $payload): bool;
    public function getGroup(): string;
    public function getStream(): string;
}
