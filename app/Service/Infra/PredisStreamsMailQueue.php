<?php
declare(strict_types=1);

namespace App\Service\Infra;

use App\Service\Ports\MailQueue;
use Predis\Client as Predis;

final class PredisStreamsMailQueue implements MailQueue
{
    private Predis $r;
    private string $stream;
    private string $group;

    public function __construct(?Predis $client = null)
    {
        // Prefer a prebuilt client (DI), else build from env
        if ($client) {
            $this->r = $client;
        } else {
            $url = getenv('REDIS_URL');
            if (!$url || trim($url) === '') {
                $host = getenv('REDIS_HOST') ?: '127.0.0.1';
                $port = (int)(getenv('REDIS_PORT') ?: 6379);
                $db   = (int)(getenv('REDIS_DB')   ?: 0);
                $pass = getenv('REDIS_AUTH') ?: null;
                $user = getenv('REDIS_USERNAME') ?: null;

                $params = [
                    'scheme'   => 'tcp',
                    'host'     => $host,
                    'port'     => $port,
                    'database' => $db,
                ];
                if ($pass !== null && $pass !== '') {
                    // Predis accepts either 'password' or 'username'+'password' (for ACL)
                    $params['password'] = $pass;
                    if ($user !== null && $user !== '') {
                        $params['username'] = $user;
                    }
                }
                $this->r = new Predis($params);
            } else {
                // DSN has :password@host:port/db already
                $this->r = new Predis($url);
            }
        }

        $this->stream = getenv('MAIL_STREAM') ?: 'mail:outbound';
        $this->group  = getenv('MAIL_GROUP')  ?: 'senders';

        // Idempotent group bootstrap at "0" so backlog is visible
        try {
            $this->r->executeRaw(['XGROUP','CREATE',$this->stream,$this->group,'0','MKSTREAM']);
        } catch (\Throwable $e) {
            // BUSYGROUP is fine; optionally set ID=0 to read old entries
            try { $this->r->executeRaw(['XGROUP','SETID',$this->stream,$this->group,'0']); } catch (\Throwable $ignore) {}
        }
    }

    public function enqueue(array $job): bool
    {
        $payload = json_encode($job, JSON_UNESCAPED_SLASHES);
        return $this->r->executeRaw(['XADD', $this->stream, '*', 'payload', $payload]);
    }

    public function getStream(): string { return $this->stream; }
    public function getGroup():  string { return $this->group; }
}
