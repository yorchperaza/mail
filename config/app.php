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

return [

    MySqlConnection::class => function () {
        $cfg = [
            'host'    => $_ENV['DB_HOST']     ?? '127.0.0.1',
            'port'    => (int)($_ENV['DB_PORT'] ?? 3306),
            'dbname'  => $_ENV['DB_DATABASE'] ?? 'ml_mail',
            'user'    => $_ENV['DB_USER']     ?? 'root',
            'pass'    => $_ENV['DB_PASS']     ?? '',
            'charset' => $_ENV['DB_CHARSET']  ?? 'utf8mb4',
            'options' => [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES ".($_ENV['DB_CHARSET'] ?? 'utf8mb4'),
            ],
        ];
        return new MySqlConnection($cfg);
    },

    /* -------------------------- Redis (Predis) -------------------------- */
    PredisClient::class => function () {
        // Discrete vars only (no REDIS_URL)
        $host   = getenv('REDIS_HOST')   ?: '127.0.0.1';
        $port   = (int)(getenv('REDIS_PORT') ?: 6379);
        $db     = (int)(getenv('REDIS_DB')   ?: 0);
        $user   = getenv('REDIS_USERNAME') ?: '';
        $pass   = getenv('REDIS_AUTH')     ?: (getenv('REDIS_PASSWORD') ?: '');
        $scheme = getenv('REDIS_SCHEME')   ?: 'tcp';
        $tls    = ($scheme === 'tls' || $scheme === 'rediss' || getenv('REDIS_TLS') === '1');

        $params = [
            'scheme'   => $tls ? 'tls' : 'tcp',
            'host'     => $host,
            'port'     => $port,
            'database' => $db,
        ];
        if ($user !== '') $params['username'] = $user;  // ACL user (optional)
        if ($pass !== '') $params['password'] = $pass;  // AUTH (required if Redis has a password)

        $options = ['read_write_timeout' => 0];
        if ($tls) {
            $options['ssl'] = [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ];
        }

        $client = new PredisClient($params, $options);

        // Fail fast (this will only succeed if AUTH was included)
        try {
            $client->executeRaw(['PING']);
        } catch (\Throwable $e) {
            error_log('[Redis] Predis connect/auth failed: '.$e->getMessage());
            throw $e;
        }

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
        null,  // Let the service create its own Redis connection if needed
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