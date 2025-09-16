<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Domain;
use MonkeysLegion\Http\Message\JsonResponse;
use MonkeysLegion\Query\QueryBuilder;
use MonkeysLegion\Repository\RepositoryFactory;
use MonkeysLegion\Router\Attributes\Route;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use App\Service\DmarcEmailParser;

final class DmarcController
{
    public function __construct(
        private RepositoryFactory $repos,
        private QueryBuilder      $qb,
    )
    {
    }

    private function authUser(ServerRequestInterface $r): int
    {
        $uid = (int)$r->getAttribute('user_id', 0);
        if ($uid <= 0) throw new RuntimeException('Unauthorized', 401);
        return $uid;
    }

    /** Save ONE normalized DMARC report (from parser or JSON payload) */
    private function saveAggregate(array $norm, string $receivedAtIso): array
    {
        $policy = (array)($norm['policy'] ?? []);
        $domain = (string)($policy['domain'] ?? '');
        $orgName = (string)($norm['org_name'] ?? '');
        $reportId = (string)($norm['report_id'] ?? '');

        if ($domain === '' || $reportId === '') {
            return ['ok' => false, 'error' => 'missing domain or report_id'];
        }

        /** @var \App\Repository\DomainRepository $domainRepo */
        $domainRepo = $this->repos->getRepository(Domain::class);
        /** @var Domain|null $d */
        $d = $domainRepo->findOneBy(['domain' => $domain]);
        if (!$d) return ['ok' => false, 'error' => "Domain not found: $domain"];
        $startIso = $norm['date_range']['start'] ?? null;
        $endIso   = $norm['date_range']['end']   ?? null;

        $fields = [
            'domain_id' => $d->getId(),
            'org_name' => $orgName,
            'report_id' => $reportId,
            'date_start' => $startIso ? (new \DateTimeImmutable($startIso))->format('Y-m-d H:i:s') : null,
            'date_end'   => $endIso   ? (new \DateTimeImmutable($endIso))  ->format('Y-m-d H:i:s') : null,
            'adkim' => $policy['adkim'] ?? null,
            'aspf' => $policy['aspf'] ?? null,
            'p' => $policy['p'] ?? null,
            'sp' => $policy['sp'] ?? null,
            'pct' => isset($policy['pct']) ? (int)$policy['pct'] : null,
            'rows' => json_encode($norm['rows'] ?? [], JSON_UNESCAPED_UNICODE),
            'received_at' => (new \DateTimeImmutable($receivedAtIso))->format('Y-m-d H:i:s'),
        ];

        // Upsert on (domain_id, report_id)
        $pdo = $this->qb->pdo();
        $cols = array_keys($fields);
        $insCols = implode(', ', array_map(fn($c) => "`$c`", $cols));
        $ph = implode(', ', array_map(fn($c) => ":$c", $cols));
        $updates = implode(', ', array_map(fn($c) => "`$c` = VALUES(`$c`)", array_diff($cols, ['domain_id', 'report_id'])));

        $sql = "INSERT INTO `dmarcaggregate` ($insCols) VALUES ($ph)
                ON DUPLICATE KEY UPDATE $updates";
        $stmt = $pdo->prepare($sql);
        $params = [];
        foreach ($fields as $k => $v) $params[":$k"] = $v;

        if (!$stmt->execute($params)) {
            [$state, $code, $msg] = $stmt->errorInfo();
            return ['ok' => false, 'error' => "DMARC upsert failed: $state/$code – $msg"];
        }

        return ['ok' => true];
    }

    /**
     * POST /ingest/dmarc
     * Accepts:
     *  - Content-Type: application/xml (raw DMARC XML)
     *  - Content-Type: application/json  { payload: <normalized>, receivedAt?: ISO }
     *  - application/zip or gzip also accepted in raw body
     */
    #[Route(methods: 'POST', path: '/ingest/dmarc')]
    public function ingest(ServerRequestInterface $r): JsonResponse
    {
        $this->authUser($r);

        $ct = strtolower((string)($r->getHeaderLine('Content-Type') ?: 'application/octet-stream'));
        $raw = (string)$r->getBody();
        $parser = new DmarcEmailParser();

        $receivedAtIso = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
        $norms = [];

        if (str_starts_with($ct, 'application/json')) {
            $json = json_decode($raw, true);
            if (!is_array($json)) return new JsonResponse(['ok' => false, 'error' => 'Invalid JSON'], 400);
            $payload = (isset($json['payload']) && is_array($json['payload'])) ? $json['payload'] : $json;
            $receivedAtIso = (string)($json['receivedAt'] ?? $receivedAtIso);
            $norms[] = $payload;
        } elseif (str_starts_with($ct, 'application/xml') || str_starts_with($ct, 'text/xml')) {
            $one = $parser->xmlToArray($raw);
            if (!$one) return new JsonResponse(['ok' => false, 'error' => 'Bad XML'], 400);
            $norms[] = $one;
        } elseif (str_contains($ct, 'zip') || str_contains($ct, 'gzip')) {
            // Try both
            $xmls = [];
            if (str_contains($ct, 'zip')) $xmls = array_merge($xmls, $parser->fromZip($raw));
            if (str_contains($ct, 'gzip')) {
                $x = $parser->fromGzip($raw);
                if ($x) $xmls[] = $x;
            }
            foreach ($xmls as $xml) {
                $one = $parser->xmlToArray($xml);
                if ($one) $norms[] = $one;
            }
        } else {
            // treat as XML by default
            $one = $parser->xmlToArray($raw);
            if ($one) $norms[] = $one;
        }

        if (!$norms) return new JsonResponse(['ok' => false, 'error' => 'No DMARC payload found'], 422);

        $ok = 0;
        $errors = [];
        foreach ($norms as $n) {
            $res = $this->saveAggregate($n, $receivedAtIso);
            if (($res['ok'] ?? false) === true) $ok++; else $errors[] = $res['error'] ?? 'unknown error';
        }

        return new JsonResponse(['ok' => $ok > 0, 'count' => $ok, 'errors' => $errors], $ok > 0 ? 200 : 400);
    }

    /**
     * POST /hooks/dmarc/email
     * Raw inbound MIME from Postfix (pipe) or provider. Extract XML(s) and store.
     */
    #[Route(methods: 'POST', path: '/hooks/dmarc/email')]
    public function emailHook(ServerRequestInterface $r): JsonResponse
    {
        // Prefer raw body first (text/plain posts), then provider fields
        $raw = (string)$r->getBody();
        if ($raw === '') {
            $body = $r->getParsedBody();
            $raw  = is_array($body) ? (string)($body['email'] ?? $body['body-mime'] ?? '') : (string)$body;
        }
        if ($raw === '') return new JsonResponse(['ok'=>false,'error'=>'No MIME found'], 400);

        $parser  = new DmarcEmailParser();
        $pieces  = $parser->parse($raw);
        if (!$pieces) return new JsonResponse(['ok'=>false,'error'=>'No DMARC XML found'], 422);

        $ok = 0; $errors = [];
        foreach ($pieces as $p) {
            $res = $this->saveAggregate($p['json'], $p['receivedAt']);
            if (($res['ok'] ?? false) === true) $ok++; else $errors[] = $res['error'] ?? 'unknown error';
        }

        return new JsonResponse(['ok'=> true, 'count'=> $ok, 'errors'=> $errors]);
    }

}
