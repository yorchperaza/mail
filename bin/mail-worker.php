#!/usr/bin/env php
<?php
declare(strict_types=1);

use App\Service\OutboundMailService;
use App\Service\Ports\MailQueue;
use Predis\Client as PredisClient;

require __DIR__ . '/../vendor/autoload.php';
$wrap = require __DIR__ . '/../bootstrap.php';
/** @var Psr\Container\ContainerInterface $container */
$container = $wrap->getContainer();

function logerr(string $m): void { fwrite(STDERR, $m.(str_ends_with($m,"\n")?'':"\n")); }
set_error_handler(static function($s,$m,$f,$l){ logerr("[worker][PHP] {$m} @ {$f}:{$l}"); return false; });
set_exception_handler(static function(Throwable $e){ logerr("[worker][FATAL] {$e->getMessage()} @ {$e->getFile()}:{$e->getLine()}"); logerr($e->getTraceAsString()); exit(255); });

// ---- resolve services ----
/** @var MailQueue $queue */   $queue   = $container->get(MailQueue::class);
/** @var OutboundMailService $service */ $service = $container->get(OutboundMailService::class);

// ---- redis client (container -> env -> build) ----
$redis = null;
if (method_exists($container,'has') && $container->has(\Redis::class)) {
    /** @var \Redis $redis */ $redis = $container->get(\Redis::class);
} elseif (method_exists($container,'has') && $container->has(PredisClient::class)) {
    /** @var PredisClient $redis */ $redis = $container->get(PredisClient::class);
} else {
    $url = getenv('REDIS_URL');
    if (!$url || trim($url)==='') {
        $host = getenv('REDIS_HOST') ?: '127.0.0.1';
        $port = (int)(getenv('REDIS_PORT') ?: 6379);
        $db   = (int)(getenv('REDIS_DB')   ?: 0);
        $auth = getenv('REDIS_AUTH') ?: '';
        if (class_exists(\Redis::class)) {
            $r = new \Redis();
            $r->connect($host,$port,1.5);
            if ($auth!=='') {
                $user = getenv('REDIS_USERNAME') ?: null;
                $user ? $r->auth([$user,$auth]) : $r->auth($auth);
            }
            if ($db>0) $r->select($db);
            $redis = $r;
        } else {
            $redis = new PredisClient(sprintf('redis://%s%s:%d/%d',
                    $auth!==''?(':'.rawurlencode($auth).'@'):'', $host,$port,$db));
        }
    } else {
        if (class_exists(\Redis::class)) {
            $p = parse_url($url) ?: [];
            $host = $p['host'] ?? '127.0.0.1';
            $port = (int)($p['port'] ?? 6379);
            $db   = isset($p['path']) ? (int)trim($p['path'],'/') : 0;
            $user = isset($p['user']) ? rawurldecode($p['user']) : (getenv('REDIS_USERNAME') ?: null);
            $pass = isset($p['pass']) ? rawurldecode($p['pass']) : (getenv('REDIS_AUTH') ?: null);
            $r = new \Redis();
            $r->connect($host,$port,1.5);
            if (is_string($pass) && $pass!=='') { $user ? $r->auth([$user,$pass]) : $r->auth($pass); }
            if ($db>0) $r->select($db);
            $redis = $r;
        } else {
            $redis = new PredisClient($url);
        }
    }
}
if (!$redis) { logerr("[worker] No Redis client available."); exit(1); }

// ---- stream/group/consumer + tuning ----
$stream   = method_exists($queue,'getStream') ? $queue->getStream() : (getenv('MAIL_STREAM') ?: 'mail:outbound');
$group    = method_exists($queue,'getGroup')  ? $queue->getGroup()  : (getenv('MAIL_GROUP')  ?: 'senders');
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
            $raw = $fields['payload'] ?? ($fields['data'] ?? null);
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
                $service->processJob($payload);
            } else {
                logerr("[worker][drain] skip malformed entry {$entryId}");
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
        foreach ($xPending($stream,$group,50) as $p) {
            $id   = is_array($p) ? ($p[0] ?? null) : null;
            $idle = (int)(is_array($p) ? ($p[2] ?? 0) : 0);
            if ($id && $idle >= $claimIdleMs) { $xClaim($stream,$group,$consumer,$claimIdleMs,[$id]); }
        }

        $msgs = $readGroup([$stream => '>'],$batch,$blockMs);
        if (!$msgs || empty($msgs[$stream])) continue;

        foreach ($msgs[$stream] as $entryId=>$fields) {
            $payload = null;
            $raw     = $fields['payload'] ?? ($fields['data'] ?? null);

            if (is_string($raw) && $raw!=='') {
                $d = json_decode($raw,true);
                if (is_array($d)) $payload = $d;
            }
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
            if (!$payload && count($fields)===1) {
                $maybe = json_decode((string)reset($fields),true);
                if (is_array($maybe)) $payload = $maybe;
            }

            if (!is_array($payload) || !isset($payload['message_id'])) {
                $xAck($stream,$group,[$entryId]); // don't clog the PEL
                continue;
            }

            try {
                $service->processJob($payload);
                $xAck($stream,$group,[$entryId]);
            } catch (\Throwable $e) {
                $retries = isset($fields['retries']) ? (int)$fields['retries'] : 0;
                $retries++;
                if ($retries > $maxRetries) {
                    $xAdd($stream.':dlq', [
                            'payload' => $raw ?: json_encode($payload, JSON_UNESCAPED_SLASHES),
                            'error'   => $e->getMessage(),
                            'at'      => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
                    ]);
                    $xAck($stream,$group,[$entryId]);
                } else {
                    $xAdd($stream, [
                            'payload' => $raw ?: json_encode($payload, JSON_UNESCAPED_SLASHES),
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
