<?php
declare(strict_types=1);

namespace App\Service;

use MonkeysLegion\Repository\RepositoryFactory;
use MonkeysLegion\Query\QueryBuilder;
use Predis\Client;

/**
 * Orchestrates segment builds using Redis Streams.
 * Works with either phpredis (\Redis) or Predis\Client.
 */
final class SegmentBuildOrchestrator
{
    /** @var \Redis|Client */
    private $redis;

    public function __construct(
        private RepositoryFactory $repos,
        private QueryBuilder      $qb,
        Client|\Redis             $redis,   // <-- accept Predis OR phpredis
        private string            $stream = 'seg:builds',
        private string            $group  = 'seg_builders',
    ) {
        $this->redis = $redis;
        $this->bootstrapGroup();
    }

    /* ==================== Public API ==================== */

    /** Enqueue a build job (company_id, segment_id, materialize?)
     * @throws \DateMalformedStringException
     */
    public function enqueueBuild(int $companyId, int $segmentId, bool $materialize = true): string
    {
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
            'entryId'  => null,            // you can fill with XADD id if you want
            'progress' => 0,
        ]);

        return $this->xadd($this->stream, ['data' => $payload]);
    }

    /** Blocking loop (call from a CLI worker). */
    public function runForever(): void
    {
        $consumer = gethostname() . '-' . getmypid();
        while (true) {
            $batch = $this->xreadgroup($this->group, $consumer, [$this->stream => '>'], 10, 30000);
            if (!$batch || empty($batch[$this->stream])) {
                continue;
            }
            foreach ($batch[$this->stream] as $id => $fields) {
                try {
                    $payload = json_decode((string)($fields['data'] ?? '{}'), true) ?: [];
                    $this->runBuildJob($payload);
                    $this->xack($this->stream, $this->group, [$id]);
                } catch (\Throwable $e) {
                    // log and leave as pending (or move to DLQ as needed)
                    error_log('[SEG][ERR] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
                }
            }
        }
    }

    /** One-shot poller (useful for cron/k8s liveness) */
    public function runOnce(int $count = 10, int $blockMs = 3000): void
    {
        $consumer = gethostname() . '-' . getmypid();
        $batch = $this->xreadgroup($this->group, $consumer, [$this->stream => '>'], $count, $blockMs);
        if (!$batch || empty($batch[$this->stream])) {
            return;
        }
        foreach ($batch[$this->stream] as $id => $fields) {
            try {
                $payload = json_decode((string)($fields['data'] ?? '{}'), true) ?: [];
                $this->runBuildJob($payload);
                $this->xack($this->stream, $this->group, [$id]);
            } catch (\Throwable $e) {
                error_log('[SEG][ERR] '.$e->getMessage());
            }
        }
    }

    /** What a single job does. */
    public function runBuildJob(array $payload): array
    {
        $companyId   = (int)($payload['company_id'] ?? 0);
        $segmentId   = (int)($payload['segment_id'] ?? 0);
        $materialize = (bool)($payload['materialize'] ?? true);

        if ($companyId <= 0 || $segmentId <= 0) {
            $this->setStatus($companyId, $segmentId, ['status'=>'error','message'=>'Invalid payload']);
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
        if (!$company || !$segment) {
            $this->setStatus($companyId, $segmentId, ['status'=>'error','message'=>'Company or segment not found']);
            throw new \RuntimeException('Company or Segment not found');
        }

        try {
            /** @var SegmentBuildService $svc */
            $svc = new SegmentBuildService($this->repos, $this->qb);

            // optional intermediate status
            $this->setStatus($companyId, $segmentId, [
                'status'   => 'running',
                'message'  => 'Materializing…',
                'progress' => 60,
            ]);

            $res = $svc->buildSegment($company, $segment, $materialize);

            // done
            $this->setStatus($companyId, $segmentId, [
                'status'    => 'ok',
                'message'   => 'Segment built',
                'progress'  => 100,
                'matches'   => (int)($res['matches'] ?? 0),
                'added'     => (int)($res['stats']['added'] ?? 0),
                'removed'   => (int)($res['stats']['removed'] ?? 0),
                'kept'      => (int)($res['stats']['kept'] ?? 0),
                'builtAt'   => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DATE_ATOM),
            ]);

            return ['status' => 'ok', 'result' => $res];
        } catch (\Throwable $e) {
            $this->setStatus($companyId, $segmentId, [
                'status'  => 'error',
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }


    /* ==================== Bootstrap group ==================== */

    private function bootstrapGroup(): void
    {
        try {
            // MKSTREAM to auto-create stream if missing
            $this->xgroupCreate($this->stream, $this->group, '0', true);
        } catch (\Throwable $e) {
            // group may already exist – ignore
        }
    }

    /* ==================== Redis helpers (Predis + phpredis) ==================== */

    /** @param array<string,string> $fields */
    private function xadd(string $stream, array $fields): string
    {
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
        if ($this->redis instanceof \Redis) {
            return (int)$this->redis->xAck($stream, $group, $ids);
        }
        $res = $this->redis->executeRaw(array_merge(['XACK', $stream, $group], $ids));
        return (int)$res;
    }

    private function xgroupCreate(string $stream, string $group, string $id = '0', bool $mkstream = true): void
    {
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
        // Predis returns: [[stream, [[id, [k1,v1,k2,v2...]], ...]]]
        if (!is_array($raw) || empty($raw)) return [];
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
        if ($this->redis instanceof \Redis) {
            $this->redis->setex($this->statusKey($companyId, $segmentId), 3600, $json);
        } else {
            $this->redis->executeRaw(['SETEX', $this->statusKey($companyId, $segmentId), '3600', $json]);
        }
    }

    /** Public: used by controller. */
    public function lastStatus(int $companyId, int $segmentId): ?array {
        if ($this->redis instanceof \Redis) {
            $raw = $this->redis->get($this->statusKey($companyId, $segmentId));
        } else {
            $raw = $this->redis->executeRaw(['GET', $this->statusKey($companyId, $segmentId)]);
        }
        if (!is_string($raw) || $raw === '') return null;
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }


}
