<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\ApiKey;
use App\Entity\Company;
use App\Entity\Domain;
use App\Entity\RateLimitCounter;
use App\Service\OutboundMailService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use MonkeysLegion\Http\Message\JsonResponse;
use MonkeysLegion\Query\QueryBuilder;
use MonkeysLegion\Repository\RepositoryFactory;
use MonkeysLegion\Router\Attributes\Route;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Throwable;

/**
 * MonkeysMail — Public API (API Key) send endpoints
 *
 * Endpoints:
 *  - POST /messages/send
 *  - POST /messages/send/list
 *  - POST /messages/send/segment/{segmentId}
 */
final class ApiSendController
{
    public function __construct(
        private RepositoryFactory   $repos,
        private QueryBuilder        $qb,
        private OutboundMailService $mailService,
    ) {}

    /* ---------------------------------------------------------------------
     * Small helpers (JSON error + body decode)
     * ------------------------------------------------------------------- */
    private function jsonError(int $status, string $code, string $message, array $extra = []): JsonResponse
    {
        return new JsonResponse(['error' => $code, 'message' => $message] + $extra, $status);
    }

    private function decodeJsonBody(ServerRequestInterface $request): array
    {
        $raw = (string)$request->getBody();
        if ($raw === '') return [];
        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            return [];
        }
        return $data;
    }

    private function handleQuotaException(RuntimeException $e): JsonResponse
    {
        $msg = $e->getMessage();
        $maybeJson = json_decode($msg, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($maybeJson)) {
            // Your quota helper already returns a JSON-able string
            return new JsonResponse($maybeJson, 429);
        }
        return $this->jsonError(429, 'rate_limited', 'Message quota exceeded');
    }

    /* =========================================================================
     * 1) One-off send with explicit recipients
     * ========================================================================= */
    #[Route(methods: 'POST', path: '/messages/send')]
    public function sendWithApiKey(ServerRequestInterface $request): JsonResponse
    {
        try {
            // --- Auth
            $apiKey = $this->readApiKeyFromHeader($request);
            if (!$apiKey) {
                error_log('sendWithApiKey: missing/invalid API key');
                return $this->jsonError(401, 'unauthorized', 'Invalid or missing API key');
            }

            // --- Scope
            try {
                $this->assertApiKeyAllowed($apiKey, 'mail:send');
            } catch (RuntimeException $e) {
                error_log('sendWithApiKey: scope check failed: ' . $e->getMessage());
                return $this->jsonError(403, 'forbidden', 'API key is missing the required scope', ['required' => 'mail:send']);
            }

            // --- Bindings
            $company = $apiKey->getCompany();
            if (!$company) return $this->jsonError(403, 'forbidden', 'API key not bound to a company');

            $domain = $apiKey->getDomain();
            if (!$domain) return $this->jsonError(403, 'forbidden', 'API key not bound to a domain');

            // --- Body
            $now  = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $body = $this->decodeJsonBody($request);

            // --- Validation
            $units = $this->countRecipientUnitsFromBody($body);
            if ($units <= 0) {
                return $this->jsonError(422, 'invalid_request', 'At least one recipient (to/cc/bcc) is required', ['field' => 'to/cc/bcc']);
            }

            // --- Quota
            try {
                $this->enforceAndConsumeMessageQuota($company, $now, $units);
            } catch (RuntimeException $e) {
                return $this->handleQuotaException($e);
            }

            // --- Enqueue
            $result = $this->mailService->createAndEnqueue($body, $company, $domain);
            $status = (string)($result['status'] ?? '');
            $http   = match ($status) {
                'queued'       => 202,
                'preview'      => 200,
                'queue_failed' => 503,
                'sent'         => 201,
                default        => 200,
            };

            return new JsonResponse([
                'status'     => $status ?: 'queued',
                'recipients' => $units,
                'message'    => $result['message'] ?? null,
            ], $http);

        } catch (Throwable $e) {
            error_log('sendWithApiKey: unexpected error: ' . $e->getMessage());
            return $this->jsonError(500, 'internal_error', 'Internal Server Error');
        }
    }

    /* =========================================================================
     * 2) Send to a LIST (recipients resolved server-side) — BY HASH (in body)
     * ========================================================================= */
    #[Route(methods: 'POST', path: '/messages/send/list')]
    public function sendListWithApiKey(ServerRequestInterface $request): JsonResponse
    {
        try {
            // --- Auth
            $apiKey = $this->readApiKeyFromHeader($request);
            if (!$apiKey) {
                error_log('sendListWithApiKey: missing/invalid API key');
                return $this->jsonError(401, 'unauthorized', 'Invalid or missing API key');
            }

            // --- Scope
            try {
                $this->assertApiKeyAllowed($apiKey, 'mail:send:list');
            } catch (RuntimeException $e) {
                error_log('sendListWithApiKey: scope check failed: ' . $e->getMessage());
                return $this->jsonError(403, 'forbidden', 'API key is missing the required scope', ['required' => 'mail:send:list']);
            }

            // --- Bindings
            $company = $apiKey->getCompany();
            if (!$company) return $this->jsonError(403, 'forbidden', 'API key not bound to a company');

            $domain = $apiKey->getDomain();
            if (!$domain) return $this->jsonError(403, 'forbidden', 'API key not bound to a domain');

            // --- Body + list hash (from JSON body)
            $now  = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $body = $this->decodeJsonBody($request);

            $listHash = (string)($body['listHash'] ?? '');
            if (!preg_match('/^[a-f0-9]{64}$/', $listHash)) {
                return $this->jsonError(400, 'invalid_request', 'Invalid list hash', ['field' => 'listHash']);
            }

            // --- Resolve recipients (dedup + valid)
            [$emails, $total] = $this->resolveRecipientsForListHash((int)$company->getId(), $listHash);
            if ($total === 0) {
                return new JsonResponse([
                    'status'     => 'queued',
                    'recipients' => 0,
                    'target'     => ['type' => 'list', 'hash' => $listHash],
                    'message'    => 'No recipients in list',
                ], 202);
            }

            // --- Quota (per recipient unit)
            try {
                $this->enforceAndConsumeMessageQuota($company, $now, $total);
            } catch (RuntimeException $e) {
                return $this->handleQuotaException($e);
            }

            // --- Fan-out: enqueue one message per recipient
            $fanout = $this->fanOutPerRecipient($emails, $body, $company, $domain);

            $status = ($fanout['failed'] === 0) ? 'queued' : 'partial';
            $http   = ($status === 'queued') ? 202 : 207; // 207 Multi-Status for mixed results

            return new JsonResponse([
                'status'            => $status,
                'target'            => ['type' => 'list', 'hash' => $listHash],
                'recipientsTotal'   => $total,
                'recipientsQueued'  => $fanout['queued'],
                'recipientsFailed'  => $fanout['failed'],
                'details'           => $fanout['sample'], // truncated sample
                'detailsTruncated'  => $fanout['truncated'],
            ], $http);

        } catch (\Throwable $e) {
            error_log('sendListWithApiKey: unexpected error: ' . $e->getMessage());
            return $this->jsonError(500, 'internal_error', 'Internal Server Error');
        }
    }

    /**
     * Enqueue one message per recipient (single "to" each).
     * Returns a summary and a small sample of results for debugging.
     *
     * @param string[] $emails
     * @return array{queued:int,failed:int,sample:array<int,array{to:string,status:string,error?:string}>,truncated:bool}
     */
    private function fanOutPerRecipient(array $emails, array $baseBody, Company $company, Domain $domain): array
    {
        $queued = 0;
        $failed = 0;

        $sample = [];
        $maxSample = 50; // avoid huge payloads in API response

        foreach ($emails as $to) {
            // clone body and constrain to a single recipient
            $payload = $baseBody;
            $payload['to']  = [$to];
            // (Optional) make sure these sends are *queued* and not immediate:
            // $payload['queue'] = true;

            try {
                $res = $this->mailService->createAndEnqueue($payload, $company, $domain);
                $st  = (string)($res['status'] ?? 'queued');
                if ($st === 'queue_failed') {
                    $failed++;
                    if (count($sample) < $maxSample) {
                        $sample[] = ['to' => $to, 'status' => 'queue_failed'];
                    }
                } else {
                    $queued++;
                    if (count($sample) < $maxSample) {
                        $sample[] = ['to' => $to, 'status' => $st ?: 'queued'];
                    }
                }
            } catch (\Throwable $e) {
                $failed++;
                error_log('fanOutPerRecipient: enqueue failed for ' . $to . ' -> ' . $e->getMessage());
                if (count($sample) < $maxSample) {
                    $sample[] = ['to' => $to, 'status' => 'error', 'error' => $e->getMessage()];
                }
            }
        }

        return [
            'queued'    => $queued,
            'failed'    => $failed,
            'sample'    => $sample,
            'truncated' => count($emails) > $maxSample,
        ];
    }

    /* =========================================================================
     * 3) Send to a SEGMENT (recipients resolved server-side)
     * ========================================================================= */
    #[Route(methods: 'POST', path: '/messages/send/segment')]
    public function sendSegmentWithApiKey(ServerRequestInterface $request): JsonResponse
    {
        try {
            // --- Auth
            $apiKey = $this->readApiKeyFromHeader($request);
            if (!$apiKey) {
                error_log('sendSegmentWithApiKey: missing/invalid API key');
                return $this->jsonError(401, 'unauthorized', 'Invalid or missing API key');
            }

            // --- Scope
            try {
                $this->assertApiKeyAllowed($apiKey, 'mail:send:segment');
            } catch (RuntimeException $e) {
                error_log('sendSegmentWithApiKey: scope check failed: ' . $e->getMessage());
                return $this->jsonError(403, 'forbidden', 'API key is missing the required scope', ['required' => 'mail:send:segment']);
            }

            // --- Bindings
            $company = $apiKey->getCompany();
            if (!$company) return $this->jsonError(403, 'forbidden', 'API key not bound to a company');

            $domain = $apiKey->getDomain();
            if (!$domain) return $this->jsonError(403, 'forbidden', 'API key not bound to a domain');
            $body = $this->decodeJsonBody($request);

            // --- Params + Body
            $segmentHash = (string)($body['segmentHash'] ?? '');
            if (!preg_match('/^[a-f0-9]{64}$/', $segmentHash)) {
                return $this->jsonError(400, 'invalid_request', 'Invalid segment hash', ['field' => 'segmentHash']);
            }

            $now  = new DateTimeImmutable('now', new DateTimeZone('UTC'));

            // --- Resolve recipients
            [$emails, $total] = $this->resolveRecipientsForSegmentHash((int)$company->getId(), $segmentHash);
            if ($total === 0) {
                return new JsonResponse([
                    'status'     => 'queued',
                    'recipients' => 0,
                    'target'     => ['type' => 'segment', 'hash' => $segmentHash],
                    'message'    => 'No recipients in segment',
                ], 202);
            }

            // --- Quota
            try {
                $this->enforceAndConsumeMessageQuota($company, $now, $total);
            } catch (RuntimeException $e) {
                return $this->handleQuotaException($e);
            }

            // --- Enqueue
            $body['to'] = array_values($emails);
            $result     = $this->mailService->createAndEnqueue($body, $company, $domain);
            $status     = (string)($result['status'] ?? 'queued');

            return new JsonResponse([
                'status'     => $status ?: 'queued',
                'recipients' => $total,
                'target'     => ['type' => 'segment', 'hash' => $segmentHash],
                'message'    => $result['message'] ?? null,
            ], $status === 'queued' ? 202 : 200);

        } catch (Throwable $e) {
            error_log('sendSegmentWithApiKey: unexpected error: ' . $e->getMessage());
            return $this->jsonError(500, 'internal_error', 'Internal Server Error');
        }
    }

    /* =========================================================================
     * Recipient resolution helpers
     * ========================================================================= */

    /** Count unique valid recipient emails across to/cc/bcc in the posted body. */
    private function countRecipientUnitsFromBody(array $body): int
    {
        $set = [];
        $push = function (mixed $v) use (&$set): void {
            $arr = is_array($v) ? $v : (is_string($v) ? [$v] : []);
            foreach ($arr as $e) {
                $e = strtolower(trim((string)$e));
                if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) {
                    $set[$e] = true;
                }
            }
        };

        $push($body['to']  ?? []);
        $push($body['cc']  ?? []);
        $push($body['bcc'] ?? []);

        return count($set);
    }

    /**
     * Resolve recipients for a list by HASH (dedup + only valid, subscribed, not bounced).
     * NOTE: Adjust table names to your actual schema if needed.
     */
    private function resolveRecipientsForListHash(int $companyId, string $listHash): array
    {
        $qb = $this->qb->duplicate();

        $rows = $qb->select(['LOWER(c.email) AS email'])
            ->from('listcontact', 'lc')             // table for ListContact
            ->innerJoin('listgroup', 'lg', 'lg.id', '=', 'lc.listGroup_id') // snake_case FK
            ->innerJoin('contacts', 'c', 'c.id', '=', 'lc.contact_id')
            ->where('lg.hash', '=', $listHash)
            ->andWhere('lg.company_id', '=', $companyId)
            ->andWhere('c.company_id', '=', $companyId)
            ->andWhere('c.email', 'IS NOT', null)
            ->fetchAll();

        $emails = [];
        foreach ($rows as $r) {
            $e = trim((string)$r['email']);
            if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) {
                $emails[$e] = true;
            }
        }
        return [array_keys($emails), count($emails)];
    }

    /**
     * Resolve recipients for a segment by HASH (dedup + only valid/subscribed/not-bounced).
     * Tables used (your naming):
     *   - segment (id, hash, company_id, ...)
     *   - contact_segments (contact_id, segment_id)
     *   - contacts (id, company_id, email, unsubscribed_at, bounced_at, ...)
     */
    private function resolveRecipientsForSegmentHash(int $companyId, string $segmentHash): array
    {
        $qb = $this->qb->duplicate();

        $rows = $qb->select(['LOWER(c.email) AS email'])
            ->from('contact_segments', 'cs')
            ->innerJoin('segment',  's', 's.id', '=', 'cs.segment_id')
            ->innerJoin('contacts', 'c', 'c.id', '=', 'cs.contact_id')
            ->where('s.hash', '=', $segmentHash)
            ->andWhere('s.company_id', '=', $companyId)
            ->andWhere('c.company_id', '=', $companyId)
            ->andWhere('c.email', 'IS NOT', null)
            ->andWhere('c.unsubscribed_at', 'IS', null)
            ->andWhere('c.bounced_at', 'IS', null)
            ->fetchAll();

        $emails = [];
        foreach ($rows as $r) {
            $e = trim((string)$r['email']);
            if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) {
                $emails[$e] = true;
            }
        }
        return [array_keys($emails), count($emails)];
    }

    /* =========================================================================
     * API-key & scope helpers (kept compatible with your MessageController)
     * ========================================================================= */

    /** Read ApiKey from X-API-Key header and fetch entity using prefix/hash. */
    private function readApiKeyFromHeader(ServerRequestInterface $request): ?ApiKey
    {
        $hdr = trim($request->getHeaderLine('X-API-Key'));
        if ($hdr === '' || !str_contains($hdr, '.')) return null;

        [$prefixRaw, $secretRaw] = explode('.', $hdr, 2);
        $prefix = strtolower(trim($prefixRaw));
        $secret = strtolower(trim($secretRaw));
        if ($prefix === '' || $secret === '') return null;

        /** @var \App\Repository\ApiKeyRepository $apiKeyRepo */
        $apiKeyRepo = $this->repos->getRepository(ApiKey::class);

        // preferred schema: WHERE prefix = ? AND hash = sha256(secret)
        $apiKey = $apiKeyRepo->findOneBy(['prefix' => $prefix, 'hash' => $secret]);
        if (!$apiKey) return null;

        if ($apiKey->getRevoked_at() !== null) return null;

        $apiKey->setLast_used_at(new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $apiKeyRepo->save($apiKey);

        return $apiKey;
    }

    /**
     * Ensure ApiKey covers required scope (supports aliases mail/messages, wildcard, csv/json, split JSON).
     * (Instrumented with error_log for debugging.)
     */
    private function assertApiKeyAllowed(ApiKey $key, string $scope): void
    {
        $raw = $key->getScopes();
        $scopes = [];
        error_log('assertApiKeyAllowed: start, required scope=' . $scope);
        error_log('assertApiKeyAllowed: raw scopes=' . var_export($raw, true));

        if (is_string($raw)) {
            error_log('assertApiKeyAllowed: raw is string');
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $scopes = $decoded;
                error_log('assertApiKeyAllowed: decoded JSON string=' . json_encode($scopes));
            } else {
                $scopes = preg_split('/[\s,]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                error_log('assertApiKeyAllowed: parsed CSV/string scopes=' . json_encode($scopes));
            }
        } elseif (is_array($raw)) {
            error_log('assertApiKeyAllowed: raw is array -> ' . json_encode($raw));
            $arr = array_map('strval', $raw);

            if (count($arr) === 1 && preg_match('/^\s*\[.*\]\s*$/', $arr[0])) {
                $dec = json_decode($arr[0], true);
                if (json_last_error() === 0 && is_array($dec)) {
                    $scopes = $dec;
                    error_log('assertApiKeyAllowed: array contained JSON string=' . json_encode($scopes));
                }
            }

            if (!$scopes && preg_match('/\[/', $arr[0] ?? '') && preg_match('/\]$/', end($arr) ?: '')) {
                $joined = implode('', $arr);
                $dec = json_decode($joined, true);
                if (json_last_error() === 0 && is_array($dec)) {
                    $scopes = $dec;
                    error_log('assertApiKeyAllowed: joined split JSON=' . json_encode($scopes));
                }
            }

            if (!$scopes) {
                $flat = [];
                foreach ($arr as $piece) {
                    $piece = trim($piece, " \t\n\r\0\x0B[]");
                    $parts = array_map('trim', explode(',', $piece));
                    foreach ($parts as $p) {
                        $p = trim($p, " \t\n\r\0\x0B\"'");
                        if ($p !== '') $flat[] = $p;
                    }
                }
                $scopes = $flat;
                error_log('assertApiKeyAllowed: flattened array scopes=' . json_encode($scopes));
            }
        }

        $scopes = array_values(array_unique(array_map(fn($s) => strtolower(trim((string)$s)), $scopes)));
        error_log('assertApiKeyAllowed: normalized scopes=' . json_encode($scopes));

        if (in_array('*', $scopes, true)) {
            error_log('assertApiKeyAllowed: matched * (all scopes allowed)');
            return;
        }
        if (in_array('mail', $scopes, true) || in_array('messages', $scopes, true)) {
            error_log('assertApiKeyAllowed: matched bucket scope (mail/messages)');
            return;
        }
        if (in_array('mail:*', $scopes, true) || in_array('mail.*', $scopes, true)
            || in_array('messages:*', $scopes, true) || in_array('messages.*', $scopes, true)) {
            error_log('assertApiKeyAllowed: matched wildcard scope');
            return;
        }

        $alts = [
            strtolower($scope),
            str_replace(':', '.', strtolower($scope)),
            str_replace('.', ':', strtolower($scope)),
        ];
        $swap = [];
        foreach ($alts as $v) {
            if (str_starts_with($v, 'mail'))     $swap[] = preg_replace('/^mail/', 'messages', $v, 1);
            if (str_starts_with($v, 'messages')) $swap[] = preg_replace('/^messages/', 'mail', $v, 1);
        }
        $alts = array_values(array_unique(array_merge($alts, $swap)));
        error_log('assertApiKeyAllowed: candidate alt scopes=' . json_encode($alts));

        foreach ($alts as $s) {
            if (in_array($s, $scopes, true)) {
                error_log("assertApiKeyAllowed: matched alt scope='{$s}'");
                return;
            }
        }

        error_log('assertApiKeyAllowed: FAIL, required scope missing -> ' . $scope);
        throw new RuntimeException('API key missing required scope: ' . $scope, 403);
    }

    /* =========================================================================
     * Quota helpers — per recipient unit
     * ========================================================================= */

    private function planPolicy(Company $c): array
    {
        $plan   = $c->getPlan();
        $name   = strtolower(trim((string)($plan?->getName() ?? 'starter')));
        $window = ($name === 'starter') ? 'day' : 'month';
        $limit  = ($name === 'starter')
            ? 150
            : (int)($plan?->getIncludedMessages() ?? 0); // 0/null => unlimited

        return ['window' => $window, 'limit' => ($limit > 0 ? $limit : null)];
    }

    private function windowStart(DateTimeImmutable $now, string $window): DateTimeImmutable
    {
        return $window === 'day'
            ? $now->setTime(0, 0, 0)
            : $now->setDate((int)$now->format('Y'), (int)$now->format('m'), 1)->setTime(0, 0, 0);
    }

    private function windowResetAt(DateTimeImmutable $start, string $window): DateTimeImmutable
    {
        return $window === 'day' ? $start->modify('+1 day') : $start->modify('+1 month');
    }

    /**
     * Consume N message units from the current window or throw 429 if over limit.
     * Returns {window,limit,remaining,resetAt}.
     */
    private function enforceAndConsumeMessageQuota(Company $company, DateTimeImmutable $now, int $units = 1): array
    {
        $policy = $this->planPolicy($company);
        $limit  = $policy['limit'];
        $window = $policy['window'];

        if ($limit === null) {
            return ['window' => $window, 'limit' => null, 'remaining' => null, 'resetAt' => null];
        }

        $start   = $this->windowStart($now, $window);
        $key     = sprintf('messages:%s:%s', $window, $window === 'day' ? $now->format('Y-m-d') : $now->format('Y-m'));
        $resetAt = $this->windowResetAt($start, $window);

        /** @var \App\Repository\RateLimitCounterRepository $repo */
        $repo = $this->repos->getRepository(RateLimitCounter::class);

        $counter = $repo->findOneBy([
            'company_id' => $company->getId(),
            'key'        => $key,
        ]);

        if (!$counter) {
            $counter = (new RateLimitCounter())
                ->setCompany($company)
                ->setKey($key)
                ->setWindow_start($start)
                ->setCount(0);
        } else {
            if (!$counter->getWindow_start() || $counter->getWindow_start() < $start) {
                $counter->setWindow_start($start)->setCount(0);
            }
        }

        $current = (int)($counter->getCount() ?? 0);
        if ($current + $units > $limit) {
            $remaining = max(0, $limit - $current);
            throw new RuntimeException(json_encode([
                'error'     => 'rate_limited',
                'reason'    => 'Message quota exceeded for your plan',
                'window'    => $window,
                'limit'     => $limit,
                'remaining' => $remaining,
                'resetAt'   => $resetAt->format(DateTimeInterface::ATOM),
            ], JSON_UNESCAPED_SLASHES), 429);
        }

        $counter->setCount($current + $units)->setUpdated_at($now);
        $repo->save($counter);

        return [
            'window'    => $window,
            'limit'     => $limit,
            'remaining' => $limit - ($current + $units),
            'resetAt'   => $resetAt->format(DateTimeInterface::ATOM),
        ];
    }
}
