#!/usr/bin/env php
<?php
declare(strict_types=1);

use App\Service\OutboundMailService;
use App\Service\Ports\MailQueue;
use Dotenv\Dotenv;
use Predis\Client as PredisClient;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Load environment variables before building the container.
 * Uses vlucas/phpdotenv if present; otherwise a tiny safe loader.
 */
$root = dirname(__DIR__);
if (class_exists(Dotenv::class)) {
    // Unsafe allows existing env to win; safeLoad won't throw if file is missing
    Dotenv::createUnsafeImmutable($root)->safeLoad();
} else {
    $envFile = $root.'/.env';
    if (is_readable($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) { continue; }
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v);
            // strip surrounding quotes if any
            if ((str_starts_with($v, '"') && str_ends_with($v, '"')) || (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
                $v = substr($v, 1, -1);
            }
            putenv("{$k}={$v}");
            $_ENV[$k] = $v;
            $_SERVER[$k] = $v;
        }
    }
}

putenv('DB_HOST=34.9.43.102');
putenv('DB_USER=mailmonkeys');
putenv('DB_PASS=t3mp0r4lAllyson#22');
putenv('DB_DATABASE=ml_mail');

// Also set in $_ENV and $_SERVER
$_ENV['DB_HOST'] = '34.9.43.102';
$_ENV['DB_USER'] = 'mailmonkeys';
$_ENV['DB_PASS'] = 't3mp0r4lAllyson#22';
$_ENV['DB_DATABASE'] = 'ml_mail';

$_ENV['SMTP_HOST'] = '127.0.0.1';  // Changed from smtp.monkeysmail.com
$_ENV['SMTP_PORT'] = '25';         // Use port 25 for local delivery
$_ENV['SMTP_USERNAME'] = '';       // No auth needed for localhost
$_ENV['SMTP_PASSWORD'] = '';       // No auth needed for localhost
$_ENV['SMTP_SECURE'] = '';         // No TLS for localhost

// Build the app/container (now sees DB_*, REDIS_*, SMTP_* from .env)
$wrap = require __DIR__ . '/../bootstrap.php';
/** @var Psr\Container\ContainerInterface $container */
$container = $wrap->getContainer();

function logerr(string $m): void { fwrite(STDERR, $m.(str_ends_with($m,"\n")?'':"\n")); }
set_error_handler(static function($s,$m,$f,$l){ logerr("[worker][PHP] {$m} @ {$f}:{$l}"); return false; });
set_exception_handler(static function(Throwable $e){ logerr("[worker][FATAL] {$e->getMessage()} @ {$e->getFile()}:{$e->getLine()}"); logerr($e->getTraceAsString()); exit(255); });

// ---- resolve services ----
/** @var MailQueue $queue */           $queue   = $container->get(MailQueue::class);
/** @var OutboundMailService $service */ $service = $container->get(OutboundMailService::class);

// ---- redis client (build ONLY from discrete env; ignore REDIS_URL) ----
$redis = null;

// Log what we’re about to use (without secrets)
$scheme   = getenv('REDIS_SCHEME') ?: ''; // 'tcp' | 'tls' | 'rediss'
$tls      = ($scheme === 'tls' || $scheme === 'rediss' || getenv('REDIS_TLS') === '1');
$host     = getenv('REDIS_HOST') ?: '127.0.0.1';
$port     = (int)(getenv('REDIS_PORT') ?: 6379);
$db       = (int)(getenv('REDIS_DB')   ?: 0);
$user     = getenv('REDIS_USERNAME') ?: '';
$pass     = getenv('REDIS_AUTH') ?: (getenv('REDIS_PASSWORD') ?: '');
$hasAuth  = $pass !== '' ? 'yes' : 'no';
fwrite(STDERR, "[worker][redis] host={$host} port={$port} db={$db} tls=".($tls?'yes':'no')." auth={$hasAuth}\n");

// phpredis preferred if installed
if (class_exists(\Redis::class)) {
    $ctx = null;
    if ($tls) {
        $ctx = stream_context_create([
                'ssl' => [
                        'verify_peer'      => false,
                        'verify_peer_name' => false,
                ],
        ]);
    }

    $r = new \Redis();
    $ok = @$r->connect($host, $port, 2.5, null, 0, 0, $ctx);
    if (!$ok) {
        fwrite(STDERR, "[worker][redis] phpredis connect FAILED to {$host}:{$port}\n");
        exit(1);
    }

    if ($pass !== '') {
        $authOk = $user !== '' ? @$r->auth([$user, $pass]) : @$r->auth($pass);
        if (!$authOk) {
            fwrite(STDERR, "[worker][redis] phpredis AUTH failed (user=".($user!==''?'set':'<default>').")\n");
            exit(1);
        }
    }

    if ($db > 0) {
        $selOk = @$r->select($db);
        if (!$selOk) {
            fwrite(STDERR, "[worker][redis] phpredis SELECT {$db} failed\n");
            exit(1);
        }
    }

    // For blocking XREADGROUP
    $r->setOption(\Redis::OPT_READ_TIMEOUT, -1);
    $redis = $r;
} else {
    // Predis fallback (parameters only, no URL)
    /** @var PredisClient $redis */
    $params = [
            'scheme'   => $tls ? 'tls' : 'tcp',
            'host'     => $host,
            'port'     => $port,
            'database' => $db,
    ];
    if ($user !== '')   { $params['username'] = $user; } // ACL
    if ($pass !== '')   { $params['password'] = $pass; } // AUTH

    $options = ['read_write_timeout' => 0];
    if ($tls) {
        $options['ssl'] = [
                'verify_peer'      => false,
                'verify_peer_name' => false,
        ];
    }

    $redis = new PredisClient($params, $options);

    // Touch the connection once to surface auth errors immediately:
    try { $redis->ping(); } catch (\Throwable $e) {
        fwrite(STDERR, "[worker][redis] Predis connect/AUTH failed: ".$e->getMessage()."\n");
        exit(1);
    }
}

if (!$redis) { fwrite(STDERR, "[worker] No Redis client available.\n"); exit(1); }

// ---- stream/group/consumer + tuning ----
$streamEnv = getenv('MAIL_STREAM') ?: '';
$groupEnv  = getenv('MAIL_GROUP')  ?: '';

$stream   = $streamEnv !== '' ? $streamEnv : (method_exists($queue,'getStream') ? $queue->getStream() : 'mail:outbound');
$group    = $groupEnv  !== '' ? $groupEnv  : (method_exists($queue,'getGroup')  ? $queue->getGroup()  : 'senders');
$consumer = (gethostname() ?: 'consumer').'-'.getmypid();

$blockMs     = (int)(getenv('MAIL_BLOCK_MS')      ?: 5000);
$batch       = (int)(getenv('MAIL_BATCH')         ?: 20);
$claimIdleMs = (int)(getenv('MAIL_CLAIM_IDLE_MS') ?: 60000);
$maxRetries  = (int)(getenv('MAIL_MAX_RETRIES')   ?: 5);

logerr("[worker] stream={$stream} group={$group} consumer={$consumer}");

// ---- helpers (define BEFORE use) ----
$readGroup = function(array $streams,int $count,int $blockMs) use($redis,$group,$consumer){
    if ($redis instanceof \Redis) {
        return $redis->xReadGroup($group,$consumer,$streams,$count,$blockMs) ?: [];
    }
    /** @var PredisClient $redis */
    $cmd = ['XREADGROUP','GROUP',$group,$consumer,'COUNT',(string)$count,'BLOCK',(string)$blockMs,'STREAMS'];
    foreach ($streams as $s=>$_) $cmd[]=$s;
    foreach ($streams as $_=>$id) $cmd[]=$id;
    $raw = $redis->executeRaw($cmd);
    $out = [];
    if (!is_array($raw)) return $out;
    foreach ($raw as $arr) {
        if (!is_array($arr) || count($arr)<2) continue;
        $sName   = $arr[0];
        $entries = $arr[1] ?? [];
        foreach ($entries as $e) {
            $entryId = $e[0] ?? null;
            $kv      = $e[1] ?? [];
            $fields  = [];
            for ($i=0; $i<count($kv); $i+=2) { $fields[(string)$kv[$i]] = (string)($kv[$i+1] ?? ''); }
            if ($entryId) $out[$sName][$entryId] = $fields;
        }
    }
    return $out;
};
$xAck = function(string $s,string $g,array $ids) use($redis){
    if ($redis instanceof \Redis) return (bool)$redis->xAck($s,$g,$ids);
    /** @var PredisClient $redis */ $redis->executeRaw(array_merge(['XACK',$s,$g],$ids)); return true;
};
$xPending = function(string $s,string $g,int $count) use($redis){
    if ($redis instanceof \Redis) return $redis->xPending($s,$g,'-','+',$count) ?: [];
    /** @var PredisClient $redis */ $raw=$redis->executeRaw(['XPENDING',$s,$g,'-','+',(string)$count]); return is_array($raw)?$raw:[];
};
$xClaim = function(string $s,string $g,string $c,int $minIdle,array $ids) use($redis){
    try {
        if ($redis instanceof \Redis) return $redis->xClaim($s,$g,$c,$minIdle,$ids,['JUSTID'=>false]);
        /** @var PredisClient $redis */ return $redis->executeRaw(array_merge(['XCLAIM',$s,$g,$c,(string)$minIdle],$ids));
    } catch (\Throwable) { return null; }
};
$xAdd = function(string $s,array $fields) use($redis){
    if ($redis instanceof \Redis) return $redis->xAdd($s,'*',$fields);
    /** @var PredisClient $redis */ $cmd=['XADD',$s,'*']; foreach($fields as $k=>$v){ $cmd[]=(string)$k; $cmd[]=(string)$v; } return $redis->executeRaw($cmd);
};

// ---- ensure group at 0 & drain backlog ----
$ensureGroupAtZero = function(string $s,string $g) use($redis){
    try {
        if ($redis instanceof \Redis) { $redis->xGroup('CREATE',$s,$g,'0',true); }
        else { /** @var PredisClient $redis */ $redis->executeRaw(['XGROUP','CREATE',$s,$g,'0','MKSTREAM']); }
        logerr("[worker] XGROUP CREATE {$s} {$g} 0 (mkstream)");
    } catch (\Throwable $e) {
        try {
            if ($redis instanceof \Redis) { $redis->xGroup('SETID',$s,$g,'0'); }
            else { /** @var PredisClient $redis */ $redis->executeRaw(['XGROUP','SETID',$s,$g,'0']); }
            logerr("[worker] XGROUP SETID {$s} {$g} 0");
        } catch (\Throwable $ignored) {}
    }
};
$ensureGroupAtZero($stream,$group);

$drainOnce = function() use($readGroup,$stream,$service,$xAck,$group){
    logerr("[worker] draining backlog from id 0…");
    for ($i=0;$i<20;$i++){
        $msgs = $readGroup([$stream=>'0'],100,100);
        if (!$msgs || empty($msgs[$stream])) break;
        foreach ($msgs[$stream] as $entryId=>$fields){
            // UPDATED: Check 'json' field first (from PredisStreamsMailQueue)
            $raw = $fields['json'] ?? ($fields['payload'] ?? ($fields['data'] ?? null));
            $payload = is_string($raw) ? (json_decode($raw,true) ?: null) : null;

            if (!$payload && isset($fields['message_id'])) {
                $payload = [
                        'message_id'=>(int)$fields['message_id'],
                        'company_id'=>isset($fields['company_id'])?(int)$fields['company_id']:null,
                        'domain_id' =>isset($fields['domain_id']) ?(int)$fields['domain_id'] :null,
                        'envelope'  =>isset($fields['envelope']) && is_string($fields['envelope'])
                                ? (json_decode($fields['envelope'],true) ?: [])
                                : [],
                ];
            }
            if (is_array($payload) && isset($payload['message_id'])) {
                logerr("[worker][drain] processing message_id=".$payload['message_id']);
                $service->processJob($payload);
            } else {
                logerr("[worker][drain] skip malformed entry {$entryId} fields=".json_encode($fields));
            }
            $xAck($stream,$group,[$entryId]);
        }
    }
    logerr("[worker] backlog drain done.");
};
$drainOnce();

// ---- main loop ----
while (true) {
    try {
        // Claim stale messages
        foreach ($xPending($stream,$group,50) as $p) {
            $id   = is_array($p) ? ($p[0] ?? null) : null;
            $idle = (int)(is_array($p) ? ($p[2] ?? 0) : 0);
            if ($id && $idle >= $claimIdleMs) {
                logerr("[worker] claiming stale message {$id} (idle={$idle}ms)");
                $xClaim($stream,$group,$consumer,$claimIdleMs,[$id]);
            }
        }

        $msgs = $readGroup([$stream => '>'],$batch,$blockMs);
        if (!$msgs || empty($msgs[$stream])) continue;

        logerr("[worker] received ".count($msgs[$stream])." messages");

        foreach ($msgs[$stream] as $entryId=>$fields) {
            $payload = null;

            // UPDATED: Check 'json' field first (from PredisStreamsMailQueue)
            if (isset($fields['json']) && is_string($fields['json'])) {
                $payload = json_decode($fields['json'], true);
                logerr("[worker] extracted payload from 'json' field");
            }
            // Fallback to other field names
            elseif (isset($fields['data']) && is_string($fields['data'])) {
                $payload = json_decode($fields['data'], true);
                logerr("[worker] extracted payload from 'data' field");
            }
            elseif (isset($fields['payload']) && is_string($fields['payload'])) {
                $payload = json_decode($fields['payload'], true);
                logerr("[worker] extracted payload from 'payload' field");
            }
            // Legacy format with direct fields
            elseif (isset($fields['message_id'])) {
                $payload = [
                        'message_id'=>(int)$fields['message_id'],
                        'company_id'=>isset($fields['company_id'])?(int)$fields['company_id']:null,
                        'domain_id' =>isset($fields['domain_id']) ?(int)$fields['domain_id'] :null,
                        'envelope'  =>isset($fields['envelope']) && is_string($fields['envelope'])
                                ? (json_decode($fields['envelope'],true) ?: [])
                                : [],
                ];
                logerr("[worker] built payload from direct fields");
            }
            // Single field entry (some Redis clients)
            elseif (count($fields)===1) {
                $maybe = json_decode((string)reset($fields),true);
                if (is_array($maybe)) {
                    $payload = $maybe;
                    logerr("[worker] extracted payload from single field");
                }
            }

            if (!is_array($payload) || !isset($payload['message_id'])) {
                logerr("[worker] Invalid payload in entry {$entryId}. Fields: " . json_encode($fields));
                $xAck($stream,$group,[$entryId]); // don't clog the PEL
                continue;
            }

            try {
                logerr("[worker] Processing message_id=" . $payload['message_id'] . " company_id=" . ($payload['company_id'] ?? 'null'));
                $service->processJob($payload);
                $xAck($stream,$group,[$entryId]);
                logerr("[worker] Successfully sent message_id=" . $payload['message_id']);
            } catch (\Throwable $e) {
                logerr("[worker][ERR] Failed to process message_id=" . $payload['message_id'] . ": " . $e->getMessage());

                $retries = isset($fields['retries']) ? (int)$fields['retries'] : 0;
                $retries++;

                if ($retries > $maxRetries) {
                    logerr("[worker] Max retries exceeded for message_id=" . $payload['message_id'] . ", moving to DLQ");
                    $xAdd($stream.':dlq', [
                            'json' => json_encode($payload, JSON_UNESCAPED_SLASHES),
                            'error'   => $e->getMessage(),
                            'at'      => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
                    ]);
                    $xAck($stream,$group,[$entryId]);
                } else {
                    logerr("[worker] Retrying message_id=" . $payload['message_id'] . " (attempt {$retries}/{$maxRetries})");
                    $xAdd($stream, [
                            'json' => json_encode($payload, JSON_UNESCAPED_SLASHES),
                            'retries' => (string)$retries,
                    ]);
                    $xAck($stream,$group,[$entryId]);
                }
                logerr("[worker][ERR] {$e->getMessage()} @ {$e->getFile()}:{$e->getLine()}");
            }
        }
    } catch (\Throwable $e) {
        logerr("[worker][loop][ERR] {$e->getMessage()}");
        usleep(500000);
    }
}
