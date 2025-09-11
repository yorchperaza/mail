<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Company;
use App\Entity\Domain;
use App\Entity\Message;
use App\Entity\User;
use MonkeysLegion\Http\Message\JsonResponse;
use MonkeysLegion\Query\QueryBuilder;
use MonkeysLegion\Repository\RepositoryFactory;
use MonkeysLegion\Router\Attributes\Route;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final class CompanyEventsController
{
    public function __construct(
        private RepositoryFactory $repos,
        private QueryBuilder      $qb,
    ) {}

    /* =========================================================================
     * Public route
     * ========================================================================= */

    #[Route(methods: 'GET', path: '/companies/{hash}/events')]
    public function listCompanyEvents(ServerRequestInterface $request): JsonResponse
    {
        $userId  = $this->authenticateUser($request);
        $company = $this->resolveCompany((string)$request->getAttribute('hash'), $userId);

        $filters = $this->parseCompanyEventFilters($request->getQueryParams());
        $data    = $this->queryCompanyEvents($company->getId(), $filters);

        return new JsonResponse($this->formatCompanyEventsResponse($data, $filters));
    }

    /* =========================================================================
     * Filters
     * ========================================================================= */

    private function parseCompanyEventFilters(array $q): array
    {
        // Accept multiple event types via CSV (types=opened,clicked) OR single type=opened
        $typesCsv = $this->trimOrNull($q['types'] ?? null);
        $typeOne  = $this->trimOrNull($q['type'] ?? null);
        $types    = [];
        if ($typesCsv) {
            $types = array_values(array_filter(array_map('trim', explode(',', strtolower($typesCsv)))));
        } elseif ($typeOne) {
            $types = [strtolower($typeOne)];
        }

        return [
            // Scope
            'domain_ids' => $this->parseIntegerList((string)($q['domain_id'] ?? '')),
            'message_id' => $this->trimOrNull($q['message_id'] ?? null), // accepts with/without <>

            // Event filters
            'types'     => $types,                                   // opened|clicked|delivered|bounced|unsubscribed...
            'recipient' => $this->trimOrNull($q['recipient'] ?? ''), // email substring

            // Time window (UTC)
            'since' => $this->parseDateOrDateTime($q['since'] ?? null),
            'until' => $this->parseDateOrDateTime($q['until'] ?? null, true),

            // Sort + pagination
            'order'    => in_array(strtolower($q['order'] ?? 'desc'), ['asc','desc'], true) ? strtolower($q['order']) : 'desc',
            'page'     => max(1, (int)($q['page'] ?? 1)),
            'per_page' => min(200, max(1, (int)($q['perPage'] ?? 50))),

            // Optional: group aggregates by domain
            'agg_by_domain' => in_array(strtolower($q['aggByDomain'] ?? ''), ['1','true','yes'], true),
        ];
    }

    /* =========================================================================
     * Query
     * ========================================================================= */

    /**
     * @return array{
     *   total:int,page:int,per_page:int,order:string,
     *   items_raw:array<int,array<string,mixed>>,
     *   aggregates:array<string,int>,
     *   agg_domain:null|array<int,array{domainId:int,domainName:?string,events:array<string,int>}>
     * }
     */
    private function queryCompanyEvents(int $companyId, array $f): array
    {
        // Base with joins so we can filter by company/domain and surface message/domain info
        $base = $this->qb->duplicate()
            ->from('messageevent', 'me')
            ->leftJoin('messages', 'm', 'm.id', '=', 'me.message_id')
            ->leftJoin('domains',  'd', 'd.id', '=', 'm.domain_id')
            ->where('m.company_id', '=', $companyId);

        // Filters
        if (!empty($f['domain_ids'])) {
            $base->whereIn('m.domain_id', $f['domain_ids']);
        }
        if (!empty($f['types'])) {
            $base->whereIn('me.event', $f['types']);
        }
        if (!empty($f['recipient'])) {
            $base->whereLike('me.recipient_email', '%'.$f['recipient'].'%');
        }
        if ($f['since']) {
            $base->andWhere('me.occurred_at', '>=', $f['since']->format('Y-m-d H:i:s'));
        }
        if ($f['until']) {
            $base->andWhere('me.occurred_at', '<',  $f['until']->format('Y-m-d H:i:s'));
        }
        if (!empty($f['message_id'])) {
            $mid = $this->normalizeMessageIdFromPath($f['message_id']);
            $base->andWhereGroup(function($q) use ($mid) {
                $q->where('m.message_id', '=', $mid);
                if (str_starts_with($mid, '<') && str_ends_with($mid, '>')) {
                    $q->orWhere('m.message_id', '=', substr($mid, 1, -1));
                }
            });
        }

        // Count BEFORE pagination
        $total = (clone $base)->count();

        // Select page
        $base->select([
            'me.id',
            'me.event           AS event_type',
            'me.payload         AS meta_json',
            'me.occurred_at     AS created_at',
            'me.recipient_email AS recipient_email',
            'me.ip              AS ip',
            'me.user_agent      AS user_agent',
            'me.url             AS url',
            'me.smtp_code       AS smtp_code',
            'me.smtp_response   AS smtp_response',

            'm.id               AS msg_id',
            'm.message_id       AS msg_message_id',
            'm.subject          AS msg_subject',
            'm.domain_id        AS msg_domain_id',

            'd.domain           AS domain_name',
        ])
            ->orderBy('me.occurred_at', $f['order'])
            ->orderBy('me.id', $f['order'])
            ->paginate($f['page'], $f['per_page']);

        $rows = $base->fetchAll();

        // Aggregates by event type
        $aggQ = $this->qb->duplicate()
            ->select(['me.event AS event_type', 'COUNT(*) AS cnt'])
            ->from('messageevent', 'me')
            ->leftJoin('messages', 'm', 'm.id', '=', 'me.message_id')
            ->where('m.company_id', '=', $companyId);

        if (!empty($f['domain_ids'])) $aggQ->whereIn('m.domain_id', $f['domain_ids']);
        if (!empty($f['types']))      $aggQ->whereIn('me.event', $f['types']);
        if (!empty($f['recipient']))  $aggQ->andWhere('me.recipient_email', 'LIKE', '%'.$f['recipient'].'%');
        if ($f['since'])              $aggQ->andWhere('me.occurred_at', '>=', $f['since']->format('Y-m-d H:i:s'));
        if ($f['until'])              $aggQ->andWhere('me.occurred_at', '<',  $f['until']->format('Y-m-d H:i:s'));

        $aggQ->groupBy('me.event');
        $aggRows = $aggQ->fetchAll();

        $agg = [];
        foreach ($aggRows as $r) {
            $agg[(string)$r['event_type']] = (int)$r['cnt'];
        }

        // Optional aggregates by domain
        $aggByDomain = null;
        if ($f['agg_by_domain']) {
            $ad = $this->qb->duplicate()
                ->select(['m.domain_id AS domain_id', 'd.domain AS domain_name', 'me.event AS event_type', 'COUNT(*) AS cnt'])
                ->from('messageevent', 'me')
                ->leftJoin('messages', 'm', 'm.id', '=', 'me.message_id')
                ->leftJoin('domains',  'd', 'd.id', '=', 'm.domain_id')
                ->where('m.company_id', '=', $companyId);

            if (!empty($f['domain_ids'])) $ad->whereIn('m.domain_id', $f['domain_ids']);
            if (!empty($f['types']))      $ad->whereIn('me.event', $f['types']);
            if (!empty($f['recipient']))  $ad->andWhere('me.recipient_email', 'LIKE', '%'.$f['recipient'].'%');
            if ($f['since'])              $ad->andWhere('me.occurred_at', '>=', $f['since']->format('Y-m-d H:i:s'));
            if ($f['until'])              $ad->andWhere('me.occurred_at', '<',  $f['until']->format('Y-m-d H:i:s'));

            $ad->groupBy('m.domain_id')->groupBy('me.event');
            $rowsAD = $ad->fetchAll();

            $aggByDomain = [];
            foreach ($rowsAD as $r) {
                $did = (int)$r['domain_id'];
                if (!isset($aggByDomain[$did])) {
                    $aggByDomain[$did] = [
                        'domainId'   => $did,
                        'domainName' => (string)($r['domain_name'] ?? null),
                        'events'     => [],
                    ];
                }
                $aggByDomain[$did]['events'][(string)$r['event_type']] = (int)$r['cnt'];
            }
            $aggByDomain = array_values($aggByDomain);
        }

        return [
            'total'      => $total,
            'page'       => $f['page'],
            'per_page'   => $f['per_page'],
            'order'      => $f['order'],
            'items_raw'  => $rows,
            'aggregates' => $agg,
            'agg_domain' => $aggByDomain,
        ];
    }

    /* =========================================================================
     * Response shaping
     * ========================================================================= */

    private function formatCompanyEventsResponse(array $data, array $filters): array
    {
        $items = array_map(function(array $r) {
            // payload/meta
            $meta = null;
            if (isset($r['meta_json']) && is_string($r['meta_json'])) {
                $dec = json_decode($r['meta_json'], true);
                if (json_last_error() === JSON_ERROR_NONE) $meta = $dec;
            }
            $meta = is_array($meta) ? $meta : [];
            foreach (['ip','user_agent','url','smtp_code','smtp_response'] as $k) {
                if (array_key_exists($k, $r) && $r[$k] !== null) {
                    $meta[$k] = $r[$k];
                }
            }

            return [
                'id'   => (int)$r['id'],
                'type' => (string)$r['event_type'],
                'at'   => $this->toIso8601((string)$r['created_at']),
                'recipient' => [
                    'id'    => null, // add join later if you store recipient row id
                    'email' => isset($r['recipient_email']) ? (string)$r['recipient_email'] : null,
                ],
                'meta' => $meta,
                'message' => [
                    'id'        => isset($r['msg_id']) ? (int)$r['msg_id'] : null,
                    'messageId' => isset($r['msg_message_id']) ? (string)$r['msg_message_id'] : null,
                    'subject'   => isset($r['msg_subject']) ? (string)$r['msg_subject'] : null,
                ],
                'domain' => [
                    'id'   => isset($r['msg_domain_id']) ? (int)$r['msg_domain_id'] : null,
                    'name' => isset($r['domain_name']) ? (string)$r['domain_name'] : null,
                ],
            ];
        }, $data['items_raw']);

        return [
            'meta' => [
                'page'      => $data['page'],
                'perPage'   => $data['per_page'],
                'total'     => $data['total'],
                'order'     => $data['order'],
                'filters'   => [
                    'domain_id'  => $filters['domain_ids'] ?? [],
                    'types'      => $filters['types'] ?? [],
                    'recipient'  => $filters['recipient'] ?? null,
                    'message_id' => $filters['message_id'] ?? null,
                    'since'      => $filters['since']?->format(DateTimeInterface::ATOM),
                    'until'      => $filters['until']?->format(DateTimeInterface::ATOM),
                    'aggByDomain'=> (bool)($filters['agg_by_domain'] ?? false),
                ],
            ],
            'aggregates'         => $data['aggregates'],     // {"opened": 10, "clicked": 3, ...}
            'aggregatesByDomain' => $data['agg_domain'],     // optional [{domainId,domainName,events:{...}}, ...]
            'items'              => $items,
        ];
    }

    /* =========================================================================
     * Shared helpers (duplicated here for controller independence)
     * ========================================================================= */

    private function authenticateUser(ServerRequestInterface $request): int
    {
        $userId = (int)$request->getAttribute('user_id', 0);
        if ($userId <= 0) throw new RuntimeException('Unauthorized', 401);
        return $userId;
    }

    private function resolveCompany(string $hash, int $userId): Company
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $hash)) {
            throw new RuntimeException('Invalid company identifier', 400);
        }

        /** @var \App\Repository\CompanyRepository $companyRepo */
        $companyRepo = $this->repos->getRepository(Company::class);
        $company = $companyRepo->findOneBy(['hash' => $hash]);
        if (!$company) throw new RuntimeException('Company not found', 404);

        $belongs = array_filter($company->getUsers() ?? [], fn(User $u) => $u->getId() === $userId);
        if (empty($belongs)) throw new RuntimeException('Forbidden', 403);

        return $company;
    }

    private function trimOrNull(?string $value): ?string
    {
        if ($value === null) return null;
        $t = trim($value);
        return $t !== '' ? $t : null;
    }

    private function parseIntegerList(string $value): array
    {
        if ($value === '') return [];
        return array_values(array_filter(
            array_map(fn($v) => (int)trim($v), explode(',', $value)),
            fn($v) => $v > 0
        ));
    }

    private function parseDateOrDateTime(?string $v, bool $isEnd = false): ?DateTimeImmutable
    {
        if ($v === null || $v === '') return null;
        $s = trim((string)$v);

        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
                $dt = new DateTimeImmutable($s . ' 00:00:00', new DateTimeZone('UTC'));
                return $isEnd ? $dt->modify('+1 day') : $dt; // exclusive upper bound when $isEnd
            }
            $dt = new DateTimeImmutable($s);
            return $dt->setTimezone(new DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }

    private function toIso8601(?string $value): ?string
    {
        if ($value === null || $value === '') return null;
        try {
            $dt = new DateTimeImmutable($value, new DateTimeZone('UTC'));
            return $dt->format(DateTimeInterface::ATOM);
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeMessageIdFromPath(string $raw): string
    {
        $s = trim($raw);

        // Decode up to 3 times to handle double-encoding by proxies
        $prev = null; $i = 0;
        while ($s !== $prev && $i < 3) {
            $prev = $s;
            $s = rawurldecode($s);
            $i++;
        }

        // Replace HTML entities
        $s = str_replace(['&lt;', '&gt;'], ['<', '>'], $s);

        // Remove whitespace
        $s = preg_replace('/\s+/', '', $s ?? '');
        if ($s === '') return $s;

        // Ensure angle brackets
        if ($s[0] !== '<')  { $s = '<' . $s; }
        if ($s[strlen($s) - 1] !== '>') { $s .= '>'; }

        return $s;
    }
}
