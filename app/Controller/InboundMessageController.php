<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Company;
use App\Entity\InboundMessage;
use App\Service\CompanyResolver;
use MonkeysLegion\Http\Message\JsonResponse;
use MonkeysLegion\Repository\RepositoryFactory;
use MonkeysLegion\Router\Attributes\Route;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

final class InboundMessageController
{
    public function __construct(
        private RepositoryFactory $repos,
        private CompanyResolver   $companyResolver,
    )
    {
    }

    /* ---------------------------- helpers ---------------------------- */

    private function auth(ServerRequestInterface $r): int
    {
        $uid = (int)$r->getAttribute('user_id', 0);
        if ($uid <= 0) throw new RuntimeException('Unauthorized', 401);
        return $uid;
    }

    /**
     * @throws \ReflectionException
     */
    private function company(string $hash, int $uid): Company
    {
        $c = $this->companyResolver->resolveCompanyForUser($hash, $uid);
        if (!$c) throw new RuntimeException('Company not found or access denied', 404);
        return $c;
    }

    private function toIntOrNull(mixed $v): ?int
    {
        if ($v === null || $v === '') return null;
        return is_numeric($v) ? (int)$v : null;
    }

    private function toFloatOrNull(mixed $v): ?float
    {
        if ($v === null || $v === '') return null;
        return is_numeric($v) ? (float)$v : null;
    }

    private function toDateOrNull(mixed $v): ?\DateTimeImmutable
    {
        if ($v === null) return null;
        if (is_string($v)) {
            $s = trim($v);
            if ($s === '' || strtolower($s) === 'null') return null;
            try {
                return new \DateTimeImmutable($s);
            } catch (\Throwable) {
                return null;
            }
        }
        return null;
    }

    private function shape(InboundMessage $m): array
    {
        $d = $m->getDomain();
        return [
            'id' => $m->getId(),
            'from_email' => $m->getFrom_email(),
            'subject' => $m->getSubject(),
            'raw_mime_ref' => $m->getRaw_mime_ref(),
            'spam_score' => $m->getSpam_score(),
            'dkim_result' => $m->getDkim_result(),
            'dmarc_result' => $m->getDmarc_result(),
            'arc_result' => $m->getArc_result(),
            'received_at' => $m->getReceived_at()?->format(\DateTimeInterface::ATOM),
            'domain' => $d ? ['id' => $d->getId(), 'domain' => $d->getDomain()] : null,
        ];
    }

    /* ------------------------------ list ------------------------------ */
    /**
     * GET /companies/{hash}/inbound-messages
     *
     * Query params (all optional):
     *   search?            — matches subject/from_email/raw_mime_ref substrings
     *   domainId?          — int
     *   minSpam?           — float
     *   maxSpam?           — float
     *   receivedFrom?      — ISO8601 datetime
     *   receivedTo?        — ISO8601 datetime
     *   dkim?              — exact string match of dkim_result (e.g., pass/fail/none)
     *   dmarc?             — exact string match of dmarc_result
     *   arc?               — exact string match of arc_result
     *   page?=1, perPage?=25 (max 200)
     */
    #[Route(methods: 'GET', path: '/companies/{hash}/inbound-messages')]
    public function list(ServerRequestInterface $r): JsonResponse
    {
        $uid = $this->auth($r);
        $co = $this->company((string)$r->getAttribute('hash'), $uid);

        /** @var \App\Repository\InboundMessageRepository $repo */
        $repo = $this->repos->getRepository(InboundMessage::class);

        $q = $r->getQueryParams();
        $search = trim((string)($q['search'] ?? ''));
        $domainId = $this->toIntOrNull($q['domainId'] ?? null);

        $minSpam = $this->toFloatOrNull($q['minSpam'] ?? null);
        $maxSpam = $this->toFloatOrNull($q['maxSpam'] ?? null);

        $from = $this->toDateOrNull($q['receivedFrom'] ?? null);
        $to = $this->toDateOrNull($q['receivedTo'] ?? null);

        $dkim = isset($q['dkim']) ? trim((string)$q['dkim']) : '';
        $dmarc = isset($q['dmarc']) ? trim((string)$q['dmarc']) : '';
        $arc = isset($q['arc']) ? trim((string)$q['arc']) : '';

        $page = max(1, (int)($q['page'] ?? 1));
        $perPage = max(1, min(200, (int)($q['perPage'] ?? 25)));

        // Fetch all messages for this company (filter by company id if repo doesn't do it).
        $rows = array_values(array_filter(
            $repo->findBy([]),
            static fn(InboundMessage $m) => $m->getCompany()?->getId() === $co->getId()
        ));

        // Filter by domain
        if ($domainId !== null) {
            $rows = array_values(array_filter($rows, static function (InboundMessage $m) use ($domainId) {
                return $m->getDomain()?->getId() === $domainId;
            }));
        }

        // Search (subject / from_email / raw_mime_ref)
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $rows = array_values(array_filter($rows, static function (InboundMessage $m) use ($needle) {
                $subject = mb_strtolower((string)($m->getSubject() ?? ''));
                $from = mb_strtolower((string)($m->getFrom_email() ?? ''));
                $raw = mb_strtolower((string)($m->getRaw_mime_ref() ?? ''));
                return str_contains($subject, $needle)
                    || str_contains($from, $needle)
                    || str_contains($raw, $needle);
            }));
        }

        // Spam score range
        if ($minSpam !== null || $maxSpam !== null) {
            $rows = array_values(array_filter($rows, static function (InboundMessage $m) use ($minSpam, $maxSpam) {
                $s = $m->getSpam_score();
                if ($s === null) return false;
                if ($minSpam !== null && $s < $minSpam) return false;
                if ($maxSpam !== null && $s > $maxSpam) return false;
                return true;
            }));
        }

        // Received_at window
        if ($from !== null || $to !== null) {
            $rows = array_values(array_filter($rows, static function (InboundMessage $m) use ($from, $to) {
                $ts = $m->getReceived_at();
                if (!$ts) return false;
                if ($from && $ts < $from) return false;
                if ($to && $ts > $to) return false;
                return true;
            }));
        }

        // Auth results exact match (if provided)
        if ($dkim !== '') {
            $rows = array_values(array_filter($rows, static fn(InboundMessage $m) => (string)($m->getDkim_result() ?? '') === $dkim));
        }
        if ($dmarc !== '') {
            $rows = array_values(array_filter($rows, static fn(InboundMessage $m) => (string)($m->getDmarc_result() ?? '') === $dmarc));
        }
        if ($arc !== '') {
            $rows = array_values(array_filter($rows, static fn(InboundMessage $m) => (string)($m->getArc_result() ?? '') === $arc));
        }

        // Sort newest first by received_at, then id desc
        usort($rows, static function (InboundMessage $a, InboundMessage $b) {
            $ta = $a->getReceived_at()?->getTimestamp() ?? PHP_INT_MIN;
            $tb = $b->getReceived_at()?->getTimestamp() ?? PHP_INT_MIN;
            if ($ta === $tb) return $b->getId() <=> $a->getId();
            return $tb <=> $ta;
        });

        $total = count($rows);
        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return new JsonResponse([
            'meta' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
                'totalPages' => (int)ceil($total / $perPage),
            ],
            'items' => array_map(fn(InboundMessage $m) => $this->shape($m), $slice),
        ]);
    }
}
