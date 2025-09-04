<?php
declare(strict_types=1);

namespace App\Service;

final class WebhookSigner
{
    /**
     * Returns headers for HMAC-SHA256 signing:
     *  X-Monkeys-Id: <webhookId>
     *  X-Monkeys-Timestamp: <epoch>
     *  X-Monkeys-Signature: v1=<hex hmac>,alg=HMAC-SHA256
     */
    public static function signHeaders(int $webhookId, string $secret, string $body): array
    {
        $ts   = (string) time();
        $base = $ts . '.' . $body;
        $sig  = hash_hmac('sha256', $base, $secret);
        return [
            'X-Monkeys-Id'        => (string)$webhookId,
            'X-Monkeys-Timestamp' => $ts,
            'X-Monkeys-Signature' => 'v1=' . $sig . ',alg=HMAC-SHA256',
        ];
    }
}
