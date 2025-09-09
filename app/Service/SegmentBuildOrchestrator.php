<?php
declare(strict_types=1);

namespace App\Service;

use MonkeysLegion\Repository\RepositoryFactory;
use MonkeysLegion\Query\QueryBuilder;
use Predis\Client as Predis;

/**
 * Orchestrates segment builds using Redis Streams.
 * Works with either phpredis (\Redis) or Predis\Client.
 */
final class SegmentBuildOrchestrator
{
    /** @var \Redis|Predis */
    private $redis;

    private string $consumer;

    public function __construct(
        private RepositoryFactory $repos,
        private QueryBuilder      $qb,
        Predis|\Redis|null        $redis = null,   // optional + typed (no "mixed")
        private string            $stream = 'seg:builds',
        private string            $group  = 'seg_builders',
    ) {
        error_log('[SEG][BOOT] ctor start');
        $this->redis = $redis ?: self::makeRedisFromEnv();
        error_log('[SEG][BOOT] redis ready via '.(is_object($this->redis) ? get_class($this->redis) : gettype($this->redis)));
        $this->bootstrapGroup();
        error_log('[SEG][BOOT] ctor done; stream='.$this->stream.' group='.$this->group);
    }

    /** Read env from $_ENV or getenv(), with default. */
    private static function env(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $_ENV)) return $_ENV[$key];
        $v = getenv($key);
        return ($v === false || $v === null) ? $default : $v;
    }

    private static function makeRedisFromEnv(): \Redis|Predis
    {
        $scheme = getenv('REDIS_SCHEME') ?: 'tcp';
        $host   = getenv('REDIS_HOST')   ?: '127.0.0.1';
        $port   = (int)(getenv('REDIS_PORT') ?: 6379);
        $db     = (int)(getenv('REDIS_DB')   ?: 0);
        $user   = getenv('REDIS_USERNAME') ?: '';
        $pass   = getenv('REDIS_AUTH')     ?: (getenv('REDIS_PASSWORD') ?: '');
        $tls    = ($scheme === 'tls' || $scheme === 'rediss' || getenv('REDIS_TLS') === '1');

        if (class_exists(Predis::class)) {
            $params = [
                'scheme'   => $tls ? 'tls' : 'tcp',
                'host'     => $host,
                'port'     => $port,
                'database' => $db,
            ];
            if ($user !== '') $params['username'] = $user;
            if ($pass !== '') $params['password'] = $pass;

            $options = ['read_write_timeout' => 0];
            if ($tls) {
                $options['ssl'] = [
                    'verify_peer'      => false,
                    'verify_peer_name' => false,
                ];
            }

            $client = new Predis($params, $options);
            $client->executeRaw(['PING']); // surfaces missing AUTH immediately
            return $client;
        }

        if (!class_exists(\Redis::class)) {
            throw new \RuntimeException('No Redis client available (Predis/phpredis not installed).');
        }

        $ctx = $tls ? stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]) : null;

        $r = new \Redis();
        if (!$r->connect($host, $port, 2.5, null, 0, 0, $ctx)) {
            throw new \RuntimeException("Redis connect failed to {$host}:{$port}");
        }
        if ($pass !== '') {
            $ok = $user !== '' ? $r->auth([$user, $pass]) : $r->auth($pass);
            if (!$ok) throw new \RuntimeException('Redis AUTH failed');
        }
        if ($db > 0 && !$r->select($db)) {
            throw new \RuntimeException("Redis SELECT {$db} failed");
        }
        $r->setOption(\Redis::OPT_READ_TIMEOUT, -1);
        return $r;
    }


    /* ==================== Public API ==================== */

    /** Enqueue a build job (company_id, segment_id, materialize?) */
    public function enqueueBuild(int $companyId, int $segmentId, bool $materialize = true): string
    {
        error_log(sprintf('[SEG][ENQ] company_id=%d segment_id=%d materialize=%s', $companyId, $segmentId, $materialize ? '1' : '0'));

        $payload = json_encode([
            'company_id'  => $companyId,
            'segment_id'  => $segmentId,
            'materialize' => $materialize,
            'enqueued_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DATE_ATOM),
        ], JSON_UNESCAPED_SLASHES);

        // set queued status right away
        $this->setStatus($companyId, $segmentId, [
            'status'   => 'queued',
            'message'  => 'Waiting for a worker',
            'entryId'  => null,
            'progress' => 0,
        ]);

        $id = $this->xadd($this->stream, ['data' => $payload]);
        error_log('[SEG][ENQ] xadd id='.$id);
        return $id;
    }

    /** Blocking loop (call from a CLI worker). */
    public function runForever(): void
    {
        $this->consumer = gethostname() . '-' . getmypid();
        error_log("[SEG][WORKER] start consumer={$this->consumer}");
        while (true) {
            $batch = $this->xreadgroup($this->group, $this->consumer, [$this->stream => '>'], 10, 30000);
            if (!$batch || empty($batch[$this->stream])) {
                error_log("[SEG][WORKER] idle…");
                // also try to reclaim stale messages (idle > 60s)
                $this->claimStale();
                continue;
            }
            foreach ($batch[$this->stream] as $id => $fields) {
                error_log("[SEG][WORKER] got {$id}");
                try {
                    $payload = json_decode((string)($fields['data'] ?? '{}'), true) ?: [];
                    $this->runBuildJobWithHeartbeat($payload);
                    $this->xack($this->stream, $this->group, [$id]);
                    error_log("[SEG][WORKER] ack {$id}");
                } catch (\Throwable $e) {
                    error_log("[SEG][ERR] {$e->getMessage()} @ {$e->getFile()}:{$e->getLine()}");
                    // leave pending (or push to DLQ if you want)
                }
            }
        }
    }

    // heartbeat wrapper (updates status every ~5s while build runs)
    public function runBuildJobWithHeartbeat(array $payload): array
    {
        $companyId   = (int)($payload['company_id'] ?? 0);
        $segmentId   = (int)($payload['segment_id'] ?? 0);

        $lastBeat = 0;
        $beat = function(int $progress, string $msg) use ($companyId, $segmentId, &$lastBeat) {
            $now = time();
            if ($now - $lastBeat >= 5) { // every 5s
                $this->setStatus($companyId, $segmentId, [
                    'status'   => 'running',
                    'message'  => $msg,
                    'progress' => $progress,
                    'heartbeatAt' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DATE_ATOM),
                ]);
                $lastBeat = $now;
            }
        };

        // initial beat
        $this->setStatus($companyId, $segmentId, [
            'status'   => 'running',
            'message'  => 'Computing matches…',
            'progress' => 10,
        ]);

        // call the original job, but keep sending beats
        $t0 = microtime(true);
        $beat(15, 'Preparing SQL…');

        $res = $this->runBuildJob($payload);

        $dt = (int)round((microtime(true) - $t0));
        $this->setStatus($companyId, $segmentId, [
            'status'    => 'ok',
            'message'   => 'Segment built',
            'progress'  => 100,
            'durationSec' => $dt,
            'builtAt'   => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DATE_ATOM),
        ]);

        return $res;
    }

    // reclaim stale pending messages so you don't get “stuck running” forever
    private function claimStale(int $minIdleMs = 60000, int $count = 10): void
    {
        if (!isset($this->consumer)) {
            $this->consumer = gethostname() . '-' . getmypid();
        }

        if ($this->redis instanceof \Redis && method_exists($this->redis, 'xAutoClaim')) {
            try {
                $res = $this->redis->xAutoClaim($this->stream, $this->group, $this->consumer, $minIdleMs, '0-0', $count);
                if (!empty($res[1])) {
                    error_log('[SEG][WORKER] autoclaimed '.count($res[1]).' stale message(s)');
                }
            } catch (\Throwable $e) {
                // ignore
            }
            return;
        }

        // Predis: use XAUTOCLAIM raw
        try {
            $raw = $this->redis->executeRaw(['XAUTOCLAIM', $this->stream, $this->group, $this->consumer, (string)$minIdleMs, '0-0', 'COUNT', (string)$count]);
            if (is_array($raw) && !empty($raw[1])) {
                error_log('[SEG][WORKER] autoclaimed '.count($raw[1]).' stale message(s)');
            }
        } catch (\Throwable $e) {
            // ignore
        }
    }

    /** One-shot poller (useful for cron/k8s liveness) */
    public function runOnce(int $count = 10, int $blockMs = 3000): void
    {
        $consumer = gethostname() . '-' . getmypid();
        error_log('[SEG][RUNONCE] start count='.$count.' blockMs='.$blockMs.' consumer='.$consumer);
        $batch = $this->xreadgroup($this->group, $consumer, [$this->stream => '>'], $count, $blockMs);
        $n = is_array($batch[$this->stream] ?? null) ? count($batch[$this->stream]) : 0;
        error_log('[SEG][RUNONCE] fetched n='.$n);
        if ($n === 0) return;

        foreach ($batch[$this->stream] as $id => $fields) {
            try {
                $payload = json_decode((string)($fields['data'] ?? '{}'), true) ?: [];
                error_log('[SEG][RUNONCE] processing id='.$id);
                $this->runBuildJob($payload);
                $acked = $this->xack($this->stream, $this->group, [$id]);
                error_log('[SEG][RUNONCE] acked id='.$id.' ack='.$acked);
            } catch (\Throwable $e) {
                error_log('[SEG][ERR] runOnce job: '.$e->getMessage());
            }
        }
    }

    /** What a single job does. */
    public function runBuildJob(array $payload): array
    {
        $companyId   = (int)($payload['company_id'] ?? 0);
        $segmentId   = (int)($payload['segment_id'] ?? 0);
        $materialize = (bool)($payload['materialize'] ?? true);

        error_log(sprintf('[SEG][JOB] start company_id=%d segment_id=%d materialize=%s', $companyId, $segmentId, $materialize ? '1' : '0'));

        if ($companyId <= 0 || $segmentId <= 0) {
            $this->setStatus($companyId, $segmentId, ['status'=>'error','message'=>'Invalid payload']);
            error_log('[SEG][JOB][ERR] invalid payload');
            throw new \RuntimeException('Invalid build payload');
        }

        // mark running
        $this->setStatus($companyId, $segmentId, [
            'status'   => 'running',
            'message'  => 'Computing matches…',
            'progress' => 10,
        ]);

        /** @var \App\Repository\CompanyRepository $coRepo */
        $coRepo  = $this->repos->getRepository(\App\Entity\Company::class);
        /** @var \App\Repository\SegmentRepository $segRepo */
        $segRepo = $this->repos->getRepository(\App\Entity\Segment::class);

        $company = $coRepo->find($companyId);
        $segment = $segRepo->find($segmentId);
        error_log('[SEG][JOB] repos fetched company='.($company ? '1' : '0').' segment='.($segment ? '1' : '0'));
        if (!$company || !$segment) {
            $this->setStatus($companyId, $segmentId, ['status'=>'error','message'=>'Company or segment not found']);
            error_log('[SEG][JOB][ERR] company or segment not found');
            throw new \RuntimeException('Company or Segment not found');
        }

        try {
            /** @var SegmentBuildService $svc */
            $svc = new SegmentBuildService($this->repos, $this->qb);
            error_log('[SEG][JOB] SegmentBuildService created');

            // optional intermediate status
            $this->setStatus($companyId, $segmentId, [
                'status'   => 'running',
                'message'  => 'Materializing…',
                'progress' => 60,
            ]);

            $res = $svc->buildSegment($company, $segment, $materialize);
            $matches = (int)($res['matches'] ?? 0);
            $added   = (int)($res['stats']['added'] ?? 0);
            $removed = (int)($res['stats']['removed'] ?? 0);
            $kept    = (int)($res['stats']['kept'] ?? 0);
            error_log(sprintf('[SEG][JOB] build done matches=%d added=%d removed=%d kept=%d', $matches, $added, $removed, $kept));

            // done
            $this->setStatus($companyId, $segmentId, [
                'status'    => 'ok',
                'message'   => 'Segment built',
                'progress'  => 100,
                'matches'   => $matches,
                'added'     => $added,
                'removed'   => $removed,
                'kept'      => $kept,
                'builtAt'   => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DATE_ATOM),
            ]);

            return ['status' => 'ok', 'result' => $res];
        } catch (\Throwable $e) {
            $this->setStatus($companyId, $segmentId, [
                'status'  => 'error',
                'message' => $e->getMessage(),
            ]);
            error_log('[SEG][JOB][ERR] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
            throw $e;
        }
    }

    /* ==================== Bootstrap group ==================== */

    private function bootstrapGroup(): void
    {
        error_log('[SEG][BOOT] bootstrapGroup start stream='.$this->stream.' group='.$this->group);
        try {
            // MKSTREAM to auto-create stream if missing
            $this->xgroupCreate($this->stream, $this->group, '0', true);
            error_log('[SEG][BOOT] group ensured OK');
        } catch (\Throwable $e) {
            // group may already exist – ignore, but log
            error_log('[SEG][BOOT] group ensure skipped: '.$e->getMessage());
        }
    }

    /* ==================== Redis helpers (Predis + phpredis) ==================== */

    /** @param array<string,string> $fields */
    private function xadd(string $stream, array $fields): string
    {
        error_log('[SEG][REDIS] XADD stream='.$stream);
        if ($this->redis instanceof \Redis) {
            // phpredis
            return (string)$this->redis->xAdd($stream, '*', $fields);
        }
        // Predis
        return (string)$this->redis->executeRaw(array_merge(['XADD', $stream, '*'], $this->kvToList($fields)));
    }

    /** @param array<string,string> $streams */
    private function xreadgroup(string $group, string $consumer, array $streams, int $count, int $blockMs): array
    {
        $keys = implode(',', array_keys($streams));
        error_log("[SEG][REDIS] XREADGROUP group={$group} consumer={$consumer} streams={$keys} count={$count} blockMs={$blockMs}");
        if ($this->redis instanceof \Redis) {
            // phpredis
            return $this->redis->xReadGroup($group, $consumer, $streams, $count, $blockMs);
        }

        // Predis
        $cmd = ['XREADGROUP', 'GROUP', $group, $consumer, 'COUNT', (string)$count, 'BLOCK', (string)$blockMs, 'STREAMS'];

        // Append stream keys then ">" (or ids) in the exact order
        foreach (array_keys($streams) as $stream) {
            $cmd[] = $stream;
        }
        foreach (array_values($streams) as $id) {
            $cmd[] = $id;
        }

        $raw = $this->redis->executeRaw($cmd);
        return $this->decodeXreadReply($raw);
    }

    private function xack(string $stream, string $group, array $ids): int
    {
        error_log('[SEG][REDIS] XACK stream='.$stream.' ids='.implode(',', $ids));
        if ($this->redis instanceof \Redis) {
            return (int)$this->redis->xAck($stream, $group, $ids);
        }
        $res = $this->redis->executeRaw(array_merge(['XACK', $stream, $group], $ids));
        return (int)$res;
    }

    private function xgroupCreate(string $stream, string $group, string $id = '0', bool $mkstream = true): void
    {
        error_log('[SEG][REDIS] XGROUP CREATE stream='.$stream.' group='.$group.' mkstream='.($mkstream?'1':'0'));
        if ($this->redis instanceof \Redis) {
            // phpredis supports MKSTREAM boolean param
            $this->redis->xGroup('CREATE', $stream, $group, $id, $mkstream);
            return;
        }
        $args = ['XGROUP', 'CREATE', $stream, $group, $id];
        if ($mkstream) { $args[] = 'MKSTREAM'; }
        $this->redis->executeRaw($args);
    }

    /* ------------------ small Predis utilities ------------------ */

    /** @param array<string,string> $fields */
    private function kvToList(array $fields): array
    {
        $out = [];
        foreach ($fields as $k => $v) { $out[] = (string)$k; $out[] = (string)$v; }
        return $out;
    }

    /**
     * Convert Predis XREADGROUP raw reply to the shape phpredis returns:
     * [ stream => [ id => [field=>value,...], ... ] ]
     */
    private function decodeXreadReply($raw): array
    {
        if (!is_array($raw) || empty($raw)) {
            error_log('[SEG][REDIS] decodeXreadReply empty');
            return [];
        }
        $out = [];
        foreach ($raw as $streamEntry) {
            if (!is_array($streamEntry) || count($streamEntry) < 2) continue;
            [$stream, $messages] = $streamEntry;
            $out[$stream] = [];
            foreach ($messages as $msg) {
                [$id, $flat] = $msg;
                $fields = [];
                for ($i = 0; $i < count($flat); $i += 2) {
                    $fields[$flat[$i]] = $flat[$i+1];
                }
                $out[$stream][$id] = $fields;
            }
        }
        error_log('[SEG][REDIS] decodeXreadReply parsed streams='.implode(',', array_keys($out)));
        return $out;
    }

    /** Small status record we cache in Redis. */
    private function statusKey(int $companyId, int $segmentId): string {
        return sprintf('seg:status:%d:%d', $companyId, $segmentId);
    }

    /** @param array<string,mixed> $payload */
    private function setStatus(int $companyId, int $segmentId, array $payload): void {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DATE_ATOM);
        $data = array_merge(['updatedAt' => $now], $payload);
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        $key = $this->statusKey($companyId, $segmentId);
        if ($this->redis instanceof \Redis) {
            $ok = $this->redis->setex($key, 3600, $json);
            error_log('[SEG][STATUS] SETEX key='.$key.' ok='.($ok ? '1' : '0'));
        } else {
            $this->redis->executeRaw(['SETEX', $key, '3600', $json]);
            error_log('[SEG][STATUS] SETEX (Predis) key='.$key);
        }
    }

    /** Public: used by controller. */
    public function lastStatus(int $companyId, int $segmentId): ?array {
        $key = $this->statusKey($companyId, $segmentId);
        if ($this->redis instanceof \Redis) {
            $raw = $this->redis->get($key);
        } else {
            $raw = $this->redis->executeRaw(['GET', $key]);
        }
        $isHit = is_string($raw) && $raw !== '';
        error_log('[SEG][STATUS] GET key='.$key.' hit='.($isHit?'1':'0'));
        if (!$isHit) return null;
        $data = json_decode((string)$raw, true);
        return is_array($data) ? $data : null;
    }
}
