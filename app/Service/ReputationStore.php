<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Domain;
use App\Entity\IpPool;
use MonkeysLegion\Query\QueryBuilder;

final class ReputationStore
{
    public function __construct(private QueryBuilder $qb) {}

    /** Normalize provider slugs a bit (consistent keys) */
    private function normProvider(string $p): string
    {
        $p = strtolower(trim($p));
        $p = preg_replace('~\s+~', '-', $p);
        return $p ?: 'unknown';
    }

    private function clampScore(int $s): int
    {
        return max(0, min(100, $s));
    }

    /**
     * Insert or update a domain-level sample for a given timestamp+provider.
     * Requires a UNIQUE index on (domain_id, provider, sampled_at).
     *
     * @return int Row id (inserted or existing).
     */
    public function upsertDomainSample(
        Domain $domain,
        string $provider,
        int $score,
        ?string $notes,
        ?\DateTimeImmutable $sampledAt = null
    ): int {
        $provider  = $this->normProvider($provider);
        $score     = $this->clampScore($score);
        $when      = $sampledAt ?: new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $ts        = $when->format('Y-m-d H:i:s');

        // Upsert
        $sql = "
INSERT INTO `reputationsample` (`provider`,`score`,`sampled_at`,`notes`,`domain_id`,`ip_pool_id`)
VALUES (:provider, :score, :sampled_at, :notes, :domain_id, NULL)
ON DUPLICATE KEY UPDATE
  `score` = VALUES(`score`),
  `notes` = VALUES(`notes`)
";
        $this->qb->custom($sql, [
            ':provider'   => $provider,
            ':score'      => $score,
            ':sampled_at' => $ts,
            ':notes'      => $notes,
            ':domain_id'  => $domain->getId(),
        ])->execute();

        // Prefer lastInsertId() for inserts; fall back to a precise lookup.
        $pdo = $this->qb->pdo();
        $id  = (int)$pdo->lastInsertId();
        if ($id > 0) {
            return $id;
        }

        $row = $this->qb->fetchOne(
            'SELECT `id`
               FROM `reputationsample`
              WHERE `domain_id` = :d AND `provider` = :p AND `sampled_at` = :ts
              LIMIT 1',
            [':d' => $domain->getId(), ':p' => $provider, ':ts' => $ts]
        );

        return (int)($row['id'] ?? 0);
    }

    /**
     * Insert or update an IP-pool sample (same uniqueness rule).
     * Requires a UNIQUE index on (ip_pool_id, provider, sampled_at).
     */
    public function upsertIpPoolSample(
        IpPool $pool,
        string $provider,
        int $score,
        ?string $notes,
        ?\DateTimeImmutable $sampledAt = null
    ): int {
        $provider  = $this->normProvider($provider);
        $score     = $this->clampScore($score);
        $when      = $sampledAt ?: new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $ts        = $when->format('Y-m-d H:i:s');

        $sql = "
INSERT INTO `reputationsample` (`provider`,`score`,`sampled_at`,`notes`,`ip_pool_id`,`domain_id`)
VALUES (:provider, :score, :sampled_at, :notes, :ip_pool_id, NULL)
ON DUPLICATE KEY UPDATE
  `score` = VALUES(`score`),
  `notes` = VALUES(`notes`)
";
        $this->qb->custom($sql, [
            ':provider'   => $provider,
            ':score'      => $score,
            ':sampled_at' => $ts,
            ':notes'      => $notes,
            ':ip_pool_id' => $pool->getId(),
        ])->execute();

        $pdo = $this->qb->pdo();
        $id  = (int)$pdo->lastInsertId();
        if ($id > 0) {
            return $id;
        }

        $row = $this->qb->fetchOne(
            'SELECT `id`
               FROM `reputationsample`
              WHERE `ip_pool_id` = :p AND `provider` = :prov AND `sampled_at` = :ts
              LIMIT 1',
            [':p' => $pool->getId(), ':prov' => $provider, ':ts' => $ts]
        );

        return (int)($row['id'] ?? 0);
    }
}
