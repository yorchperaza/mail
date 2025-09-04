<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Domain;
use App\Entity\IpPool;
use MonkeysLegion\Query\QueryBuilder;
use RuntimeException;

final class ReputationStore
{
    public function __construct(private QueryBuilder $qb) {}

    /**
     * Insert or update a domain-level sample for a given day+provider.
     * Returns inserted/updated row id (best-effort).
     */
    public function upsertDomainSample(
        Domain $domain,
        string $provider,
        int $score,
        ?string $notes,
        ?\DateTimeImmutable $sampledAt = null
    ): int {
        $when = $sampledAt ?: new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $payload = [
            'provider'   => $provider,
            'score'      => $score,
            'sampled_at' => $when->format('Y-m-d H:i:s'),
            'notes'      => $notes,
            'domain_id'  => $domain->getId(),
            'ip_pool_id' => null,
        ];

        // Use native upsert. QueryBuilder doesn’t have ON DUPLICATE KEY helper,
        // so we run a custom SQL.
        $sql = "
INSERT INTO reputationsample (provider, score, sampled_at, notes, domain_id, ip_pool_id)
VALUES (:provider, :score, :sampled_at, :notes, :domain_id, NULL)
ON DUPLICATE KEY UPDATE
  score = VALUES(score),
  notes = VALUES(notes),
  sampled_at = VALUES(sampled_at)
";
        $this->qb->custom($sql, [
            ':provider'   => $payload['provider'],
            ':score'      => $payload['score'],
            ':sampled_at' => $payload['sampled_at'],
            ':notes'      => $payload['notes'],
            ':domain_id'  => $payload['domain_id'],
        ])->execute();

        // Try to fetch id (optional)
        $row = $this->qb->fetchOne(
            'SELECT id FROM reputationsample
             WHERE domain_id = :d AND provider = :p AND sample_day = :day
             ORDER BY id DESC LIMIT 1',
            [
                ':d'   => $payload['domain_id'],
                ':p'   => $payload['provider'],
                ':day' => $when->format('Y-m-d'),
            ]
        );

        return (int)($row['id'] ?? 0);
    }

    public function upsertIpPoolSample(
        IpPool $pool,
        string $provider,
        int $score,
        ?string $notes,
        ?\DateTimeImmutable $sampledAt = null
    ): int {
        $when = $sampledAt ?: new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $sql = "
INSERT INTO reputationsample (provider, score, sampled_at, notes, ip_pool_id, domain_id)
VALUES (:provider, :score, :sampled_at, :notes, :ip_pool_id, NULL)
ON DUPLICATE KEY UPDATE
  score = VALUES(score),
  notes = VALUES(notes),
  sampled_at = VALUES(sampled_at)
";
        $this->qb->custom($sql, [
            ':provider'   => $provider,
            ':score'      => $score,
            ':sampled_at' => $when->format('Y-m-d H:i:s'),
            ':notes'      => $notes,
            ':ip_pool_id' => $pool->getId(),
        ])->execute();

        $row = $this->qb->fetchOne(
            'SELECT id FROM reputationsample
             WHERE ip_pool_id = :p AND provider = :prov AND sample_day = :day
             ORDER BY id DESC LIMIT 1',
            [
                ':p'    => $pool->getId(),
                ':prov' => $provider,
                ':day'  => $when->format('Y-m-d'),
            ]
        );

        return (int)($row['id'] ?? 0);
    }
}
