<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Domain;
use App\Entity\IpPool;
use App\Entity\ReputationSample;
use MonkeysLegion\Repository\RepositoryFactory;
use MonkeysLegion\Query\QueryBuilder;
use RuntimeException;

final class ReputationService
{
    public function __construct(
        private RepositoryFactory $repos,
        private QueryBuilder $qb,
    ) {}

    /** @var \Redis|\Predis\Client|null */
    private $redis = null;

    private function redis()
    {
        if ($this->redis) return $this->redis;

        $url = $_ENV['REDIS_URL'] ?? '';
        if ($url === '') throw new RuntimeException('REDIS_URL is not configured', 500);

        $parts = parse_url($url);
        if ($parts === false) throw new RuntimeException('Invalid REDIS_URL', 500);

        $scheme = $parts['scheme'] ?? 'redis';
        $host = $parts['host'] ?? '127.0.0.1';
        $port = (int)($parts['port'] ?? 6379);
        $pass = $parts['pass'] ?? null;
        $db   = 0;
        if (isset($parts['path']) && $parts['path'] !== '') {
            $db = (int)ltrim($parts['path'], '/');
        }

        if (class_exists(\Redis::class)) {
            $r = new \Redis();
            $r->pconnect($host, $port, 1.5);
            if ($pass) $r->auth($pass);
            if ($db)   $r->select($db);
            $this->redis = $r;
            return $this->redis;
        }

        if (class_exists(\Predis\Client::class)) {
            $this->redis = new \Predis\Client($url, ['timeout'=>1.5,'read_write_timeout'=>1.5]);
            $this->redis->ping();
            return $this->redis;
        }

        throw new RuntimeException('Neither phpredis nor Predis is available', 500);
    }

    private function eachDate(string $from, string $to): array
    {
        $out = [];
        $s = new \DateTimeImmutable($from, new \DateTimeZone('UTC'));
        $e = new \DateTimeImmutable($to, new \DateTimeZone('UTC'));
        for ($d = $s; $d <= $e; $d = $d->modify('+1 day')) {
            $out[] = $d->format('Y-m-d');
        }
        return $out;
    }

    private function rMGetInts(array $keys): array
    {
        if ($keys === []) return [];
        try {
            $vals = $this->redis()->mGet($keys);
            if (!is_array($vals)) return array_fill(0, count($keys), 0);
            return array_map(static fn($v) => (int)($v ?? 0), $vals);
        } catch (\Throwable) {
            return array_fill(0, count($keys), 0);
        }
    }

    /** Sum daily ints for a pattern like 'mm:stats:domain:%d:sent:%s' */
    private function sumSeries(string $pattern, int $id, string $from, string $to): int
    {
        $days = $this->eachDate($from, $to);
        $keys = array_map(fn($day) => sprintf($pattern, $id, $day), $days);
        $vals = $this->rMGetInts($keys);
        return array_sum($vals);
    }

    /**
     * Compute reputation for one Domain.
     * Returns [score:int (0-100), notes:string, snapshot:array]
     */
    public function computeDomainScore(Domain $domain, string $from, string $to): array
    {
        $id = $domain->getId();

        $sent      = $this->sumSeries('mm:stats:domain:%d:sent:%s',      $id, $from, $to);
        $delivered = $this->sumSeries('mm:stats:domain:%d:delivered:%s', $id, $from, $to);
        $bounced   = $this->sumSeries('mm:stats:domain:%d:bounced:%s',   $id, $from, $to);
        $rejected  = $this->sumSeries('mm:stats:domain:%d:rejected:%s',  $id, $from, $to);
        $spam      = $this->sumSeries('mm:stats:domain:%d:spam:%s',      $id, $from, $to);

        // TLS counters optional
        $tlsOk     = $this->sumSeries('mm:stats:domain:%d:tls_ok:%s',    $id, $from, $to);
        $tlsTotal  = $this->sumSeries('mm:stats:domain:%d:tls_total:%s', $id, $from, $to);

        $sentSafe = max(1, $sent);
        $bounceRate = $bounced / $sentSafe;        // 0..1
        $rejectRate = $rejected / $sentSafe;       // 0..1
        $spamRate   = $spam / $sentSafe;           // 0..1
        $tlsRatio   = $tlsTotal > 0 ? ($tlsOk / max(1, $tlsTotal)) : null;

        $score = 100.0;
        $notes = [];

        if ($bounceRate > 0) {
            $pen = 150.0 * $bounceRate;
            $score -= $pen;
            $notes[] = sprintf('Bounce rate %.2f%% → −%.1f', $bounceRate*100, $pen);
        }
        if ($rejectRate > 0) {
            $pen = 200.0 * $rejectRate;
            $score -= $pen;
            $notes[] = sprintf('Reject rate %.2f%% → −%.1f', $rejectRate*100, $pen);
        }
        if ($spamRate > 0) {
            $pen = 300.0 * $spamRate;
            $score -= $pen;
            $notes[] = sprintf('Spam rate %.2f%% → −%.1f', $spamRate*100, $pen);
        }
        if ($tlsRatio !== null) {
            $bonus = 10.0 * max(0.0, $tlsRatio - 0.90); // only if ≥90%
            if ($bonus > 0) {
                $score += $bonus;
                $notes[] = sprintf('TLS success %.2f%% → +%.1f', $tlsRatio*100, $bonus);
            } else {
                $notes[] = sprintf('TLS success %.2f%%', $tlsRatio*100);
            }
        } else {
            $notes[] = 'TLS metrics unavailable';
        }

        $score = max(0.0, min(100.0, $score));

        $snapshot = [
            'range'      => compact('from','to'),
            'sent'       => $sent,
            'delivered'  => $delivered,
            'bounced'    => $bounced,
            'rejected'   => $rejected,
            'spam'       => $spam,
            'tls_ok'     => $tlsOk,
            'tls_total'  => $tlsTotal,
            'bounceRate' => round($bounceRate*100, 2),
            'rejectRate' => round($rejectRate*100, 2),
            'spamRate'   => round($spamRate*100, 2),
            'tlsRatio'   => $tlsRatio !== null ? round($tlsRatio*100, 2) : null,
        ];

        return [
            'score'    => (int)round($score),
            'notes'    => implode('; ', $notes),
            'snapshot' => $snapshot,
        ];
    }

    /**
     * Persist a ReputationSample row for a Domain.
     * provider can be "local-mta" or any external (talos, spamhaus, google-postmaster, etc.)
     */
    public function storeDomainSample(Domain $domain, string $provider, int $score, string $notes, ?\DateTimeImmutable $when = null): int
    {
        $when ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $data = [
            'provider'   => $provider,
            'score'      => $score,
            'sampled_at' => $when->format('Y-m-d H:i:s'),
            'notes'      => $notes,
            'domain_id'  => $domain->getId(),
            'ip_pool_id' => null,
        ];
        return $this->qb->insert('reputation_sample', $data);
    }
}
