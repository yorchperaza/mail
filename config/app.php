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
            'dbname'  => $_ENV['DB_DATABASE'] ?? 'ml_mail',   // <-- was DB_NAME
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
        $params = [
            'scheme'   => $_ENV['REDIS_SCHEME'] ?? 'tcp',
            'host'     => $_ENV['REDIS_HOST'] ?? '127.0.0.1',
            'port'     => (int)($_ENV['REDIS_PORT'] ?? 6379),
            'database' => (int)($_ENV['REDIS_DB'] ?? 0),
        ];
        if (!empty($_ENV['REDIS_AUTH'])) {
            $params['password'] = $_ENV['REDIS_AUTH'];
        }
        $options = [];
        if (($params['scheme'] ?? 'tcp') === 'tls') {
            $options['ssl'] = [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ];
        }
        return new PredisClient($params, $options);
    },

    /* ----------------------------- Queue -------------------------------- */
    MailQueue::class => function ($c) {
        // DEV: process jobs immediately (no worker, no Redis)
        if (!empty($_ENV['DEV_INLINE_QUEUE'])) {
            return new DevInlineMailQueue(
                $c->get(MailSender::class)
            );
        }

        // Default: Redis Streams queue
        return new PredisStreamsMailQueue(
            $c->get(PredisClient::class),
            stream: $_ENV['MAIL_STREAM'] ?? 'mail:outbound',
            group:  $_ENV['MAIL_GROUP']  ?? 'senders',
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
        $c->get(MailQueue::class),
        $c->get(MailSender::class),
    ),

    CampaignDispatchService::class => fn($c) => new CampaignDispatchService(
        $c->get(RepositoryFactory::class),
        $c->get(MailQueue::class),
    ),

    /* ---------------------- Webhook Dispatcher -------------------------- */
    WebhookDispatcher::class => fn($c) => new WebhookDispatcher(
        $c->get(RepositoryFactory::class),
        $c->get(PredisClient::class),                        // reuse your Predis connection
        $_ENV['WEBHOOK_QUEUE_KEY'] ?? 'webhooks:deliveries'  // list/stream key to enqueue deliveries
    ),

    /* --------------------- Segment Build Services ----------------------- */
    SegmentBuildService::class => fn($c) => new SegmentBuildService(
        $c->get(RepositoryFactory::class),
        $c->get(QueryBuilder::class),
    ),

    SegmentBuildOrchestrator::class => fn($c) => new SegmentBuildOrchestrator(
        $c->get(RepositoryFactory::class),
        $c->get(QueryBuilder::class),
        $c->get(PredisClient::class),   // Predis\Client instance
        stream: $_ENV['SEGMENT_STREAM'] ?? 'seg:builds',
        group:  $_ENV['SEGMENT_GROUP']  ?? 'seg_builders',
    ),
];
