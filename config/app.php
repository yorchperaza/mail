<?php
declare(strict_types=1);

use App\Service\Infra\PhpMailerMailSender;
use App\Service\Infra\PredisStreamsMailQueue;
use App\Service\Infra\DevInlineMailQueue;
use App\Service\OutboundMailService;
use App\Service\Ports\MailQueue;
use App\Service\Ports\MailSender;
use App\Service\CampaignDispatchService;
use App\Service\WebhookDispatcher;
use App\Service\SegmentBuildService;
use App\Service\SegmentBuildOrchestrator;
use MonkeysLegion\Repository\RepositoryFactory;
use MonkeysLegion\Query\QueryBuilder;
use Predis\Client as PredisClient;
use MonkeysLegion\Database\MySQL\Connection as MySqlConnection;

$env = function(string $k, $default = null) {
    if (array_key_exists($k, $_ENV) && $_ENV[$k] !== '') return $_ENV[$k];
    $v = getenv($k);
    return ($v !== false && $v !== '') ? $v : $default;
};

return [

    MySqlConnection::class => function () use ($env) {
        // 👇 prefer discrete env, falling back to sensible defaults
        $cfg = [
            'host'    => (string) $env('DB_HOST', '127.0.0.1'),
            'port'    => (int)    $env('DB_PORT', 3306),
            'dbname'  => (string) $env('DB_DATABASE', 'ml_mail'),
            'user'    => (string) $env('DB_USER', 'root'),
            'pass'    => (string) $env('DB_PASS', ''),
            'charset' => (string) $env('DB_CHARSET', 'utf8mb4'),
            'options' => [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES ' . (string) $env('DB_CHARSET', 'utf8mb4'),
            ],
        ];

        // quick one-time log to catch surprises (no secrets)
        error_log(sprintf(
            '[DB] host=%s port=%d db=%s user=%s pass_set=%s',
            $cfg['host'], $cfg['port'], $cfg['dbname'],
            $cfg['user'] !== '' ? $cfg['user'] : '<empty>',
            $cfg['pass'] !== '' ? 'yes' : 'no'
        ));

        return new MySqlConnection($cfg);
    },

    /* -------------------------- Redis (Predis) -------------------------- */
    /* -------------------------- Redis (Predis) -------------------------- */
    PredisClient::class => function () use ($env) {
        // Discrete vars only (ignore REDIS_URL)
        $scheme = (string) $env('REDIS_SCHEME', 'tcp');
        $host   = (string) $env('REDIS_HOST',   '127.0.0.1');
        $port   = (int)    $env('REDIS_PORT',   6379);
        $db     = (int)    $env('REDIS_DB',     0);
        $user   = (string) $env('REDIS_USERNAME', '');
        $pass   = (string) $env('REDIS_AUTH',     (string) $env('REDIS_PASSWORD', ''));
        $tls    = ($scheme === 'tls' || $scheme === 'rediss' || $env('REDIS_TLS', '') === '1');

        $params = [
            'scheme'   => $tls ? 'tls' : 'tcp',
            'host'     => $host,
            'port'     => $port,
            'database' => $db,
        ];
        if ($user !== '') $params['username'] = $user;  // Redis 6+ ACL
        if ($pass !== '') $params['password'] = $pass;  // AUTH

        $options = ['read_write_timeout' => 0];
        if ($tls) {
            $options['ssl'] = ['verify_peer' => false, 'verify_peer_name' => false];
        }

        $client = new PredisClient($params, $options);

        // Surface bad auth immediately instead of later during XADD
        $client->executeRaw(['PING']);

        return $client;
    },

    /* ----------------------------- Queue -------------------------------- */
    MailQueue::class => function ($c) {
        // Allow turning on inline mode only if explicitly requested
        $inline = filter_var(getenv('DEV_INLINE_QUEUE') ?: '', FILTER_VALIDATE_BOOLEAN);
        if ($inline) {
            return new DevInlineMailQueue($c->get(MailSender::class));
        }

        $stream = getenv('MAIL_STREAM') ?: 'mail:outbound';
        $group  = getenv('MAIL_GROUP')  ?: 'senders';

        return new PredisStreamsMailQueue(
            $c->get(PredisClient::class),
            stream: $stream,
            group:  $group,
        );
    },

    /* ----------------------------- SMTP --------------------------------- */
    MailSender::class => function () {
        return new PhpMailerMailSender(
            host: $_ENV['SMTP_HOST'] ?? 'smtp.monkeysmail.com',
            port: (int)($_ENV['SMTP_PORT'] ?? 587),
            secure: $_ENV['SMTP_SECURE'] ?? 'tls',
            username: $_ENV['SMTP_USERNAME'] ?? null,
            password: $_ENV['SMTP_PASSWORD'] ?? null,
            timeout: (int)($_ENV['SMTP_TIMEOUT'] ?? 15),
        );
    },

    /* --------------------------- Services ------------------------------- */
    OutboundMailService::class => fn($c) => new OutboundMailService(
        $c->get(RepositoryFactory::class),
        $c->get(QueryBuilder::class),
        $c->get(MailQueue::class),
        $c->get(MailSender::class),
        // ⬇️ pass the DI Predis client here (NOT null)
        $c->get(PredisClient::class),
        3600
    ),

    CampaignDispatchService::class => fn($c) => new CampaignDispatchService(
        $c->get(RepositoryFactory::class),
        $c->get(MailQueue::class),
    ),

    /* ---------------------- Webhook Dispatcher -------------------------- */
    WebhookDispatcher::class => fn($c) => new WebhookDispatcher(
        $c->get(RepositoryFactory::class),
        $c->get(PredisClient::class),
        $_ENV['WEBHOOK_QUEUE_KEY'] ?? 'webhooks:deliveries'
    ),

    /* --------------------- Segment Build Services ----------------------- */
    SegmentBuildService::class => fn($c) => new SegmentBuildService(
        $c->get(RepositoryFactory::class),
        $c->get(QueryBuilder::class),
    ),

    SegmentBuildOrchestrator::class => fn($c) => new SegmentBuildOrchestrator(
        $c->get(RepositoryFactory::class),
        $c->get(QueryBuilder::class),
        $c->get(PredisClient::class),
        stream: $_ENV['SEGMENT_STREAM'] ?? 'seg:builds',
        group:  $_ENV['SEGMENT_GROUP']  ?? 'seg_builders',
    ),
];