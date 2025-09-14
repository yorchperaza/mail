<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Company;
use App\Entity\Domain;
use App\Entity\InboundMessage;
use App\Entity\InboundRoute;
use App\Service\CompanyResolver;
use App\Service\OutboundMailService;
use MonkeysLegion\Http\Message\JsonResponse;
use MonkeysLegion\Repository\RepositoryFactory;
use MonkeysLegion\Router\Attributes\Route;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailerException;

final class InboundMessageController
{
    public function __construct(
        private RepositoryFactory $repos,
        private CompanyResolver   $companyResolver,
        private OutboundMailService $outbound,
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

    /** ------------------- INTERNAL UTILS ------------------- */

    private function hmacOk(ServerRequestInterface $r, string $raw): bool
    {
        $secret = (string)($_ENV['INBOUND_SECRET'] ?? getenv('INBOUND_SECRET') ?: '');
        if ($secret === '') return true; // if not configured, skip (you can force require by returning false)
        $hdr = $r->getHeaderLine('X-Inbound-Signature');
        if ($hdr === '') return false;

        $expected = 'sha256=' . hash_hmac('sha256', $raw, $secret);
        return hash_equals($expected, $hdr);
    }

    private function storeRaw(string $mime): string
    {
        $base = '/var/mail/inbound';
        $key  = date('Y/m/d/') . bin2hex(random_bytes(8)) . '.eml';
        $path = $base . '/' . $key;
        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0775, true);
        }
        if (!is_dir(dirname($path))) {
            error_log("[inbound] mkdir failed for " . dirname($path));
            throw new RuntimeException('Failed to prepare storage directory', 500);
        }
        if (file_put_contents($path, $mime) === false) {
            error_log("[inbound] file_put_contents failed path=$path");
            throw new RuntimeException('Failed to store raw MIME', 500);
        }
        return $path;
    }

    private function parseAuthResultsString(string $ar): array
    {
        $norm = strtolower($ar);
        $dkim  = str_contains($norm, 'dkim=pass')  ? 'pass' : (str_contains($norm, 'dkim=fail')  ? 'fail' : (str_contains($norm, 'dkim=none') ? 'none' : null));
        $dmarc = str_contains($norm, 'dmarc=pass') ? 'pass' : (str_contains($norm, 'dmarc=fail') ? 'fail' : (str_contains($norm, 'dmarc=none') ? 'none' : null));
        $arc   = str_contains($norm, 'arc=pass')   ? 'pass' : (str_contains($norm, 'arc=fail')   ? 'fail' : (str_contains($norm, 'arc=none') ? 'none' : null));
        return [$dkim, $dmarc, $arc];
    }

    private function parseFromMime(string $mime): array
    {
        if (class_exists(\PhpMimeMailParser\Parser::class)) {
            $p = new \PhpMimeMailParser\Parser();
            $p->setText($mime);
            $from    = $p->getHeader('from') ?: null;
            $subject = $p->getHeader('subject') ?: null;
            $ar      = $p->getHeader('authentication-results') ?: '';
            [$dkim, $dmarc, $arc] = $this->parseAuthResultsString($ar);
            return [$from, $subject, $dkim, $dmarc, $arc];
        }

        // Minimal dependency-free parser
        $parts = preg_split("/\r?\n\r?\n/", $mime, 2);
        $raw = $parts[0] ?? '';
        $raw = preg_replace("/\r?\n[ \t]+/", ' ', $raw); // unfold
        $from = $subject = $ar = null;
        foreach (explode("\n", $raw) as $line) {
            if (stripos($line, 'From:') === 0)     $from = trim(substr($line, 5));
            elseif (stripos($line, 'Subject:') === 0) $subject = trim(substr($line, 8));
            elseif (stripos($line, 'Authentication-Results:') === 0) $ar = trim(substr($line, 21));
        }
        [$dkim, $dmarc, $arc] = $this->parseAuthResultsString($ar ?? '');
        return [$from, $subject, $dkim, $dmarc, $arc];
    }


    private function resolveDomainAndCompanyByRcpt(?string $rcpt): array
    {
        $domainPart = null;
        if ($rcpt && str_contains($rcpt, '@')) {
            $domainPart = substr(strrchr($rcpt, '@'), 1);
            $domainPart = $domainPart ? strtolower($domainPart) : null;
        }

        /** @var \App\Repository\DomainRepository $domainRepo */
        $domainRepo = $this->repos->getRepository(Domain::class);
        $domain = $domainPart ? $domainRepo->findOneBy(['domain' => $domainPart]) : null;
        $company = $domain?->getCompany();

        return [$domain, $company, $domainPart];
    }

    /** ------------------- NEW ENDPOINT ------------------- */
    /**
     * POST /inbound/receive
     *
     * Accepts either:
     *  - application/json with {mail_from, rcpt_tos[], mime_b64, received_at?, auth_results?, spam_score?}
     *  - message/rfc822 with raw MIME in body and X-* headers
     *
     * Auth: Optional HMAC in X-Inbound-Signature: sha256=<hex>
     */
    #[Route(methods: 'POST', path: '/inbound/receive')]
    public function receive(ServerRequestInterface $r): JsonResponse
    {
        $t0  = microtime(true);
        $rid = bin2hex(random_bytes(6)); // trace id

        try {
            $rawBody = (string)$r->getBody();
            $ct      = strtolower($r->getHeaderLine('Content-Type'));
            $this->lg($rid, "START", ['ct' => $ct, 'len' => strlen($rawBody)]);

            // HMAC (optional)
            if (!$this->hmacOk($r, $rawBody)) {
                $this->lg($rid, "HMAC FAIL");
                throw new RuntimeException('Invalid signature', 401);
            }
            $this->lg($rid, "HMAC OK");

            $mailFrom = null; $rcpts = []; $mime = null; $receivedAt = null;
            $authResults = null; $spamScore = null;

            if (str_starts_with($ct, 'application/json')) {
                try {
                    $j = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    $this->lg($rid, "JSON parse error", ['msg' => $e->getMessage()]);
                    throw new RuntimeException('Invalid JSON', 400);
                }
                $mailFrom   = isset($j['mail_from']) ? (string)$j['mail_from'] : null;
                $rcpts      = isset($j['rcpt_tos']) && is_array($j['rcpt_tos']) ? $j['rcpt_tos'] : [];
                $mimeB64    = isset($j['mime_b64']) ? (string)$j['mime_b64'] : '';
                $mime       = $mimeB64 !== '' ? base64_decode($mimeB64, true) : null;
                $receivedAt = isset($j['received_at']) ? (string)$j['received_at'] : null;
                $authResults= isset($j['auth_results']) ? (string)$j['auth_results'] : null;
                $spamScore  = isset($j['spam_score']) && is_numeric($j['spam_score']) ? (float)$j['spam_score'] : null;
                $this->lg($rid, "JSON parsed", ['rcpts' => $rcpts, 'mime_len' => is_string($mime) ? strlen($mime) : 0]);
            } elseif (str_starts_with($ct, 'message/rfc822')) {
                $mime       = $rawBody;
                $mailFrom   = $r->getHeaderLine('X-Mail-From') ?: null;
                $rcptHdr    = $r->getHeaderLine('X-Rcpt-To');
                $rcpts      = $rcptHdr ? array_map('trim', explode(',', $rcptHdr)) : [];
                $receivedAt = $r->getHeaderLine('X-Received-At') ?: null;
                $authResults= $r->getHeaderLine('X-Auth-Results') ?: null;
                $spamScore  = ($v = $r->getHeaderLine('X-Spam-Score')) !== '' ? (float)$v : null;
                $this->lg($rid, "RFC822 parsed", ['rcpts' => $rcpts, 'mime_len' => strlen($mime ?? '')]);
            } else {
                $this->lg($rid, "Unsupported CT", ['ct' => $ct]);
                throw new RuntimeException('Unsupported Content-Type', 415);
            }

            if (!$mime || !is_string($mime) || $mime === '') {
                $this->lg($rid, "Missing MIME");
                throw new RuntimeException('Missing MIME body', 400);
            }
            if (empty($rcpts)) {
                $this->lg($rid, "Missing rcpts");
                throw new RuntimeException('Missing rcpt_tos / X-Rcpt-To', 400);
            }

            $primaryRcpt = $rcpts[0];

            // 1) parse minimal headers + auth results
            [$fromHdr, $subject, $dkim, $dmarc, $arc] = $this->parseFromMime($mime);
            if ($authResults) {
                [$dkim2, $dmarc2, $arc2] = $this->parseAuthResultsString($authResults);
                $dkim  = $dkim2  ?? $dkim;
                $dmarc = $dmarc2 ?? $dmarc;
                $arc   = $arc2   ?? $arc;
            }
            $top = $this->parseTopHeaders($mime); // ['map'=>[], 'raw'=>string]
            $headers = $top['map'];

            // 2) resolve domain/company
            [$domain, $company, $domainPart] = $this->resolveDomainAndCompanyByRcpt($primaryRcpt);
            $this->lg($rid, "Resolved domain/company", [
                'rcpt' => $primaryRcpt, 'domainPart' => $domainPart,
                'domainId' => $domain?->getId(), 'companyId' => $company?->getId()
            ]);
            if (!$domain || !$company) {
                throw new RuntimeException("Unknown inbound domain '{$domainPart}'", 404);
            }

            // 3) load + sort routes (priority asc, newest first within same priority)
            /** @var \App\Repository\InboundRouteRepository $routeRepo */
            $routeRepo = $this->repos->getRepository(InboundRoute::class);
            $allRoutes = array_values(array_filter(
                $routeRepo->findBy([]),
                fn($r2) => $r2->getCompany()?->getId() === $company->getId()
            ));
            $routes = $this->sortRoutes($allRoutes);

            // TLS info (optional; LMTP/SMTP proxy can set it)
            $tls = null;
            $xtls = $r->getHeaderLine('X-Transport-TLS');
            if ($xtls !== '') $tls = $this->boolish($xtls);

            // 4) evaluate routes
            $ctx = [
                'companyId'  => $company->getId(),
                'domainId'   => $domain->getId(),
                'rcpts'      => array_map('strtolower', $rcpts),
                'sender'     => strtolower(trim((string)($fromHdr ?? $mailFrom ?? ''))),
                'headers'    => $headers,
                'spam_score' => $spamScore,
                'dkim'       => $dkim,  // 'pass'|'fail'|'none'|null
                'tls'        => $tls,   // true|false|null
            ];

            $shouldStore   = false;
            $storeNotifies = [];
            $forwards      = [];
            $matchedIds    = [];

            foreach ($routes as $route) {
                $this->lg($rid, "EVAL", [
                    'routeId' => $route->getId(),
                    'pattern' => $route->getPattern(),
                    'action'  => $route->getAction(),
                    'rcpts'   => $ctx['rcpts'],
                    'sender'  => $ctx['sender'],
                ]);
                if (!$this->routeMatches($route, $ctx)) continue;

                $matchedIds[] = $route->getId();
                $action = strtolower((string)$route->getAction());
                $dest   = $route->getDestination() ?? [];

                if ($action === 'store') {
                    $shouldStore = true;
                    $notify = (array)($dest['notify'] ?? []);
                    foreach ($notify as $u) {
                        $u = trim((string)$u);
                        if ($u !== '') $storeNotifies[] = $u;
                    }
                    continue;
                }

                if ($action === 'forward') {
                    $to = (array)($dest['to'] ?? []);
                    foreach ($to as $d) {
                        $d = trim((string)$d);
                        if ($d !== '') $forwards[] = $d;
                    }
                    continue;
                }

                if ($action === 'stop') {
                    break; // stop evaluating further routes
                }
            }

            // 5) perform non-store side effects (placeholders)
            if (!empty($forwards)) {
                // Add any metadata you want to pass to webhooks
                $meta = [
                    'rcpt_tos'     => $rcpts,
                    'received_at'  => $receivedAt,
                    'auth_results' => $authResults,
                    'spam_score'   => $spamScore,
                ];
                $this->performForwards($forwards, $mime, $meta, $mailFrom, $rid, $company, $domain);
            }

            if (!$shouldStore) {
                // Nothing asked to be stored → acknowledge without persisting
                $this->lg($rid, "NO STORE (204)", ['matched' => $matchedIds]);
                return new JsonResponse(null, 204);
            }

            // 6) store raw + DB only now (follows your original code)
            $path = $this->storeRaw($mime);

            $this->lg($rid, "Stored MIME", ['path' => $path]);

            /** @var \App\Repository\InboundMessageRepository $repo */
            $repo = $this->repos->getRepository(InboundMessage::class);

            $msg = new InboundMessage()
                ->setCompany($company)
                ->setDomain($domain)
                ->setFrom_email($fromHdr ?? $mailFrom)
                ->setSubject($subject)
                ->setRaw_mime_ref($path)
                ->setSpam_score($spamScore)
                ->setDkim_result($dkim)
                ->setDmarc_result($dmarc)
                ->setArc_result($arc)
                ->setReceived_at(new \DateTimeImmutable($receivedAt ?: 'now', new \DateTimeZone('UTC')));

            $repo->save($msg);
            $this->lg($rid, "DB saved", ['msgId' => $msg->getId(), 'matchedRoutes' => $matchedIds]);

            // optional: notify webhooks (non-blocking recommended)
            foreach ($storeNotifies as $u) {
                $this->lg($rid, "Notify placeholder", ['url' => $u, 'msgId' => $msg->getId()]);
            }

            $dt = number_format((microtime(true) - $t0) * 1000, 1);
            $this->lg($rid, "OK 201", ['dt_ms' => $dt]);

            return new JsonResponse([
                'id'             => $msg->getId(),
                'domain'         => $domain->getDomain(),
                'company_id'     => $company->getId(),
                'from'           => $msg->getFrom_email(),
                'subject'        => $msg->getSubject(),
                'dkim'           => $msg->getDkim_result(),
                'dmarc'          => $msg->getDmarc_result(),
                'arc'            => $msg->getArc_result(),
                'spam_score'     => $msg->getSpam_score(),
                'raw_mime_ref'   => $msg->getRaw_mime_ref(),
                'received_at'    => $msg->getReceived_at()?->format(\DateTimeInterface::ATOM),
                'matched_routes' => $matchedIds,
                'traceId'        => $rid,
            ], 201);

        } catch (\Throwable $e) {
            $dt = number_format((microtime(true) - $t0) * 1000, 1);
            $traceTop = explode("\n", $e->getTraceAsString())[0] ?? '';
            $this->lg($rid, "ERROR", [
                'code' => $e->getCode(),
                'msg'  => $e->getMessage(),
                'at'   => $traceTop,
                'dt_ms'=> $dt
            ]);

            $code = ($e->getCode() >= 400 && $e->getCode() <= 599) ? $e->getCode() : 500;
            return new JsonResponse(['error' => $e->getMessage(), 'traceId' => $rid], $code);
        }
    }

    private function lg(string $rid, string $msg, array $ctx = []): void
    {
        // Keep logs compact; don’t log MIME/content
        foreach ($ctx as $k => $v) {
            if (is_scalar($v) || $v === null) continue;
            $ctx[$k] = json_encode($v, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        }
        error_log("[inbound][$rid] " . $msg . (empty($ctx) ? "" : " " . json_encode($ctx)));
    }

    /** ------------------- DETAIL ENDPOINT ------------------- */
    /**
     * GET /companies/{hash}/inbound-messages/{id}
     *
     * Returns detailed info about a single inbound message:
     *  - basic DB fields (id, subject, spam/auth results, received_at, etc.)
     *  - parsed headers (from, to, cc, date, message-id, …)
     *  - body previews (text, html)
     *  - attachments metadata (name, size, content_type, is_inline, content_id)
     * @throws \ReflectionException
     */
    #[Route(methods: 'GET', path: '/companies/{hash}/inbound-messages/{id}')]
    public function show(ServerRequestInterface $r): JsonResponse
    {
        $uid  = $this->auth($r);
        $hash = (string)$r->getAttribute('hash');
        $id   = (int)$r->getAttribute('id');
        $co   = $this->company($hash, $uid);

        /** @var \App\Repository\InboundMessageRepository $repo */
        $repo = $this->repos->getRepository(InboundMessage::class);

        /** @var InboundMessage|null $m */
        $m = $repo->find($id);
        if (!$m || $m->getCompany()?->getId() !== $co->getId()) {
            throw new RuntimeException('Message not found', 404);
        }

        $path = (string)$m->getRaw_mime_ref();
        if ($path === '' || !is_file($path)) {
            throw new RuntimeException('Raw MIME not found on disk', 404);
        }

        $mime = @file_get_contents($path);
        if ($mime === false) {
            throw new RuntimeException('Unable to read raw MIME', 500);
        }

        // Parse details
        [$hdrs, $text, $html, $atts] = $this->parseMimeDetails($mime);

        return new JsonResponse([
            'item' => array_merge($this->shape($m), [
                'headers' => $hdrs,               // associative array (subset of useful headers)
                'body' => [
                    'text' => $this->preview($text, 20000),  // limit ~20KB to keep response light
                    'html' => $this->preview($html, 20000),
                ],
                'attachments' => $atts,           // array of {filename, size, content_type, inline, content_id}
                // tip: if you later add a raw download route, return its URL here too.
            ]),
        ]);
    }

    /* ------------------- helpers for details ------------------- */

    private function preview(?string $s, int $max): ?string
    {
        if ($s === null) return null;
        if (strlen($s) <= $max) return $s;
        return substr($s, 0, $max) . "\n…[truncated]";
    }

    /**
     * Returns: [headers, text, html, attachments[]]
     * headers: associative array of interesting headers
     * attachments: list of arrays with filename,size,content_type,inline,content_id
     */
    private function parseMimeDetails(string $mime): array
    {
        // If PhpMimeMailParser is available, use it (recommended).
        if (class_exists(\PhpMimeMailParser\Parser::class)) {
            $p = new \PhpMimeMailParser\Parser();
            $p->setText($mime);

            // Headers of interest
            $rcv = $p->getHeader('received'); // can be string|array|null
            $headers = [
                'from'            => $p->getHeader('from') ?: null,
                'to'              => $p->getHeader('to') ?: null,
                'cc'              => $p->getHeader('cc') ?: null,
                'subject'         => $p->getHeader('subject') ?: null,
                'date'            => $p->getHeader('date') ?: null,
                'message-id'      => $p->getHeader('message-id') ?: null,
                'reply-to'        => $p->getHeader('reply-to') ?: null,
                'return-path'     => $p->getHeader('return-path') ?: null,
                'authentication-results' => $p->getHeader('authentication-results') ?: null,
                'dkim-signature'  => $p->getHeader('dkim-signature') ?: null,
                'received'        => $rcv === null ? null : (is_array($rcv) ? implode("\n", $rcv) : (string)$rcv),
            ];

            // Bodies
            $text = $p->getMessageBody('text') ?: null;
            // Use embedded HTML so inline images are CID-resolved (still returns HTML string)
            $html = $p->getMessageBody('htmlEmbedded') ?: ($p->getMessageBody('html') ?: null);

            // Attachments
            $atts = [];
            foreach ($p->getAttachments() as $att) {
                /** @var \PhpMimeMailParser\Attachment $att */
                $atts[] = [
                    'filename'     => $att->getFilename(),
                    'size'         => $att->getFilesize(),
                    'content_type' => $att->getContentType(),
                    'inline'       => (bool)$att->getInline(),
                    'content_id'   => $att->getContentID(),
                ];
            }

            return [$headers, $text, $html, $atts];
        }

        // ------ Minimal fallback parser (no library) ------
        // Parse only top-level headers + naive text/html parts
        $parts = preg_split("/\r?\n\r?\n/", $mime, 2);
        $rawHdrs = $parts[0] ?? '';
        $bodyAll = $parts[1] ?? '';

        // Unfold headers
        $rawHdrs = preg_replace("/\r?\n[ \t]+/", ' ', $rawHdrs);
        $map = [];
        foreach (explode("\n", $rawHdrs) as $line) {
            if (!str_contains($line, ':')) continue;
            [$k, $v] = array_map('trim', explode(':', $line, 2));
            $lk = strtolower($k);

            if ($lk === 'received') {
                // SAFE append (avoid touching undefined index)
                $prev = $map['received'] ?? '';
                $map['received'] = ($prev !== '' ? $prev . "\n" : '') . $v;
                continue;
            }

            // keep first occurrence for the rest
            if (!isset($map[$lk])) {
                $map[$lk] = $v;
            }
        }

        // Super-naive body extraction (works for simple emails; multipart is best with the parser lib)
        $text = null; $html = null;
        if (isset($map['content-type']) && str_contains(strtolower($map['content-type']), 'text/html')) {
            $html = $bodyAll;
        } else {
            $text = $bodyAll;
        }

        return [[
            'from'        => $map['from']        ?? null,
            'to'          => $map['to']          ?? null,
            'cc'          => $map['cc']          ?? null,
            'subject'     => $map['subject']     ?? null,
            'date'        => $map['date']        ?? null,
            'message-id'  => $map['message-id']  ?? null,
            'reply-to'    => $map['reply-to']    ?? null,
            'return-path' => $map['return-path'] ?? null,
            'auth-results'=> $map['authentication-results'] ?? null,
            'dkim-signature' => $map['dkim-signature'] ?? null,
            'received'    => $map['received']    ?? null,
        ], $text, $html, /*attachments*/[]];
    }

    /**
     * GET /companies/{hash}/inbound-messages/{id}/raw
     * Returns {filename, mime_b64}
     */
    #[Route(methods: 'GET', path: '/companies/{hash}/inbound-messages/{id}/raw')]
    public function raw(ServerRequestInterface $r): JsonResponse
    {
        $uid  = $this->auth($r);
        $hash = (string)$r->getAttribute('hash');
        $id   = (int)$r->getAttribute('id');
        $co   = $this->company($hash, $uid);

        /** @var \App\Repository\InboundMessageRepository $repo */
        $repo = $this->repos->getRepository(InboundMessage::class);
        /** @var InboundMessage|null $m */
        $m = $repo->find($id);
        if (!$m || $m->getCompany()?->getId() !== $co->getId()) {
            throw new RuntimeException('Message not found', 404);
        }

        $path = (string)$m->getRaw_mime_ref();
        if ($path === '' || !is_file($path)) {
            throw new RuntimeException('Raw MIME not found on disk', 404);
        }

        $mime = @file_get_contents($path);
        if ($mime === false) {
            throw new RuntimeException('Unable to read raw MIME', 500);
        }

        return new JsonResponse([
            'filename' => basename($path),
            'mime_b64' => base64_encode($mime),
        ]);
    }

    /** @return array{map: array<string,string>, raw: string} */
    private function parseTopHeaders(string $mime): array
    {
        [$rawHdrs] = preg_split("/\r?\n\r?\n/", $mime, 2);
        $rawHdrs = (string)($rawHdrs ?? '');
        // unfold
        $unfolded = preg_replace("/\r?\n[ \t]+/", ' ', $rawHdrs) ?? $rawHdrs;
        $map = [];
        foreach (explode("\n", $unfolded) as $line) {
            if (!str_contains($line, ':')) continue;
            [$k, $v] = array_map('trim', explode(':', $line, 2));
            $lk = strtolower($k);
            // first wins (except Received, but we only need single-value headers for matching)
            if (!isset($map[$lk])) $map[$lk] = $v;
        }
        return ['map' => $map, 'raw' => $unfolded];
    }

    private function ci(string $s): string { return mb_strtolower($s); }
    private function startsWithCI(string $s, string $prefix): bool {
        return str_starts_with($this->ci($s), $this->ci($prefix));
    }
    private function endsWithCI(string $s, string $suffix): bool {
        $s = $this->ci($s); $suffix = $this->ci($suffix);
        return $suffix === '' || ($suffix !== '' && str_ends_with($s, $suffix));
    }
    private function containsCI(string $s, string $needle): bool {
        return $needle === '' ? false : str_contains($this->ci($s), $this->ci($needle));
    }

    private function globMatch(string $pattern, string $subject, bool $caseInsensitive = true): bool
    {
        $re = '/^' . str_replace(['\*','\?'], ['.*','.?'], preg_quote($pattern, '/')) . '$/';
        return (bool)preg_match($caseInsensitive ? $re.'i' : $re, $subject);
    }

    /** Normalize boolean-ish header value */
    private function boolish(string $s): bool
    {
        $s = strtolower(trim($s));
        return in_array($s, ['1','true','on','yes','y'], true);
    }

    /**
     * Decide if a single route matches the message + constraints.
     * @param InboundRoute $route
     * @param array $ctx [
     *   'companyId'=>int,'domainId'=>?int,'rcpts'=>string[],'sender'=>?string,
     *   'headers'=>array<string,string>, 'spam_score'=>?float, 'dkim'=>'pass|fail|none|null',
     *   'tls'=>?bool
     * ]
     */
    /**
     * Decide if a single route matches…
     */
    private function routeMatches(InboundRoute $route, array $ctx): bool
    {
        // domain scope as before
        $rid = $route->getDomain()?->getId();
        if ($rid !== null && $rid !== ($ctx['domainId'] ?? null)) return false;

        // constraints as before
        if (($route->getDkim_required() ?? 0) === 1 && ($ctx['dkim'] ?? null) !== 'pass') return false;
        if (($route->getTls_required()  ?? 0) === 1 && ($ctx['tls']  ?? null) !== true)  return false;
        $thr = $route->getSpam_threshold();
        $score = $ctx['spam_score'] ?? null;
        if ($thr !== null && $score !== null && $score > $thr) {
            $this->lg($rid, "SPAM THRESHOLD BLOCK", ['score' => $score, 'thr' => $thr, 'routeId' => $route->getId()]);
            return false;
        }

        $pat = trim((string)($route->getPattern() ?? ''));
        if ($pat === '' || $pat === '*') return true;

        // ---------- HEADER ----------
        if (str_starts_with($pat, 'header')) {
            // header[:op]:name=value
            // op ∈ ^, $, ~, *  (default exact when omitted)
            $op = '';
            $rest = '';
            if (preg_match('/^header(\^|\$|~|\*):(.+)$/i', $pat, $m)) {
                $op = $m[1];
                $rest = $m[2];
            } elseif (str_starts_with($pat, 'header:')) {
                $rest = substr($pat, 7);
            } else {
                return false;
            }

            [$name, $expected] = array_map('trim', explode('=', $rest, 2) + [null,null]);
            if ($name === null || $name === '') return false;

            $val = $ctx['headers'][mb_strtolower($name)] ?? null;
            if ($val === null) return false;

            return match ($op) {
                '^' => $this->startsWithCI($val, (string)$expected),
                '$' => $this->endsWithCI($val, (string)$expected),
                '~' => $this->containsCI($val, (string)$expected),
                '*' => $this->globMatch((string)$expected, $val),
                default => $val === (string)$expected, // exact
            };
        }

        // Collect rcpts/sender lowercased
        $rcpts  = array_map(fn($x) => mb_strtolower(trim((string)$x)), (array)($ctx['rcpts'] ?? []));
        $sender = mb_strtolower(trim((string)($ctx['sender'] ?? '')));

        // ---------- RCPT ----------
        if (str_starts_with($pat, 'rcpt')) {
            // rcpt^:, rcpt$:, rcpt~:, rcpt:glob, rcpt:exact:
            $mode = 'glob';
            $needle = '';
            if (preg_match('/^rcpt(\^|\$|~):(.+)$/i', $pat, $m)) {
                $mode = $m[1]; $needle = trim($m[2]);
            } elseif (str_starts_with($pat, 'rcpt:exact:')) {
                $mode = 'exact'; $needle = trim(substr($pat, 11));
            } elseif (str_starts_with($pat, 'rcpt:')) {
                $mode = 'glob'; $needle = trim(substr($pat, 5));
            } else {
                return false;
            }
            if ($needle === '') return false;

            foreach ($rcpts as $rcpt) {
                $match = match ($mode) {
                    '^' => $this->startsWithCI($rcpt, $needle),
                    '$' => $this->endsWithCI($rcpt, $needle),
                    '~' => $this->containsCI($rcpt, $needle),
                    'exact' => $rcpt === mb_strtolower($needle),
                    default => $this->globMatch($needle, $rcpt),
                };
                if ($match) return true;
            }
            return false;
        }

        // ---------- SENDER ----------
        if (str_starts_with($pat, 'sender')) {
            $mode = 'glob';
            $needle = '';
            if (preg_match('/^sender(\^|\$|~):(.+)$/i', $pat, $m)) {
                $mode = $m[1]; $needle = trim($m[2]);
            } elseif (str_starts_with($pat, 'sender:exact:')) {
                $mode = 'exact'; $needle = trim(substr($pat, 13));
            } elseif (str_starts_with($pat, 'sender:')) {
                $mode = 'glob'; $needle = trim(substr($pat, 7));
            } else {
                return false;
            }
            if ($needle === '' || $sender === '') return false;

            return match ($mode) {
                '^' => $this->startsWithCI($sender, $needle),
                '$' => $this->endsWithCI($sender, $needle),
                '~' => $this->containsCI($sender, $needle),
                'exact' => $sender === mb_strtolower($needle),
                default => $this->globMatch($needle, $sender),
            };
        }

        return false; // unknown pattern
    }

    /**
     * Sort routes: lowest priority first. Same priority → newest first.
     * Priority is read from destination.meta.priority if present; default 0.
     * @param InboundRoute[] $routes
     * @return InboundRoute[]
     */
    private function sortRoutes(array $routes): array
    {
        usort($routes, function($a, $b) {
            $pa = (int)($a->getDestination()['meta']['priority'] ?? 0);
            $pb = (int)($b->getDestination()['meta']['priority'] ?? 0);
            if ($pa !== $pb) return $pa <=> $pb; // lower first
            // same priority → newer first
            $ta = $a->getCreated_at()?->getTimestamp() ?? 0;
            $tb = $b->getCreated_at()?->getTimestamp() ?? 0;
            if ($ta !== $tb) return $tb <=> $ta;
            return ($b->getId() ?? 0) <=> ($a->getId() ?? 0);
        });
        return $routes;
    }

    /** Simple validators */
    private function isEmail(string $s): bool {
        return (bool)filter_var($s, FILTER_VALIDATE_EMAIL);
    }
    private function isUrl(string $s): bool {
        return (bool)filter_var($s, FILTER_VALIDATE_URL);
    }

    /** Extract bare email from "Name <email>" or return null if invalid */
    private function extractEmail(?string $s): ?string
    {
        if (!$s) return null;
        // cheap parse: prefer angle address, else the string as-is
        if (preg_match('/<([^>]+)>/', $s, $m)) {
            $s = trim($m[1]);
        }
        $s = trim($s, " \t\r\n<>");
        return filter_var($s, FILTER_VALIDATE_EMAIL) ? $s : null;
    }

    /** Ensure MIME ends with newline, optionally inject missing To: */
    private function ensureDeliverableMime(string $mime, string $rcpt): string
    {
        // Split headers/body
        $parts = preg_split("/\r?\n\r?\n/", $mime, 2);
        $rawHdrs = (string)($parts[0] ?? '');
        $body    = (string)($parts[1] ?? '');

        // Unfold to check for To/Cc/Bcc easily
        $unfold = preg_replace("/\r?\n[ \t]+/", ' ', $rawHdrs) ?? $rawHdrs;
        $lh = strtolower($unfold);
        $hasHdrRcpts = str_contains($lh, "\nto:") || str_contains($lh, "\ncc:") || str_contains($lh, "\nbcc:");

        if (!$hasHdrRcpts) {
            // Inject a To header at the top (keep original header order mostly intact)
            // Also ensure Subject/From exist in case upstream stripped them
            $add = [];
            $add[] = "To: {$rcpt}";
            if (!str_contains($lh, "\nfrom:"))    $add[] = "From: <>";
            if (!str_contains($lh, "\nsubject:")) $add[] = "Subject: (no subject)";
            $rawHdrs = implode("\r\n", $add) . "\r\n" . $rawHdrs;
            $mime = $rawHdrs . "\r\n\r\n" . $body;
        } else {
            $mime = $rawHdrs . "\r\n\r\n" . $body;
        }

        // Ensure trailing newline
        if ($mime === '' || substr($mime, -1) !== "\n") $mime .= "\n";
        return $mime;
    }

    private function forwardEmailRaw(string $mime, string $rcpt, ?string $envelopeFrom = null, ?string $rid = null): bool
    {
        $sendmail = '/usr/sbin/sendmail';
        if (!is_file($sendmail) || !is_executable($sendmail)) {
            $this->lg($rid ?? '-', "FORWARD email FAILED (sendmail missing)", ['rcpt' => $rcpt, 'path' => $sendmail]);
            return false;
        }

        // Sanitize/override envelope sender
        $envOverride = getenv('FORWARD_ENVELOPE_FROM') ?: ($_ENV['FORWARD_ENVELOPE_FROM'] ?? null);
        $envFrom = $this->extractEmail($envOverride ?: $envelopeFrom); // null if invalid

        // Make MIME deliverable (inject To: if missing, ensure newline)
        $mime = $this->ensureDeliverableMime($mime, $rcpt);

        // Verbose transcript if requested
        $verbose = (bool)($GLOBALS['_INBOUND_SENDMAIL_VERBOSE'] ?? false);
        if (!$verbose) {
            $flag = getenv('INBOUND_SENDMAIL_VERBOSE') ?: ($_ENV['INBOUND_SENDMAIL_VERBOSE'] ?? '');
            $verbose = in_array(strtolower($flag), ['1','true','on','yes'], true);
            $GLOBALS['_INBOUND_SENDMAIL_VERBOSE'] = $verbose;
        }

        $try = function(array $argv) use ($mime) {
            $desc = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $proc = proc_open($argv, $desc, $pipes);
            if (!is_resource($proc)) return [false, 0, '', 'proc_open failed', 0];

            // Chunked write in case reader closes early
            $off = 0; $len = strlen($mime); $chunk = 64 * 1024; $ok = true;
            stream_set_blocking($pipes[0], true);
            while ($off < $len) {
                $n = min($chunk, $len - $off);
                $w = @fwrite($pipes[0], substr($mime, $off, $n));
                if ($w === false) { $ok = false; break; }
                $off += $w;
            }
            @fclose($pipes[0]);

            $stdout = stream_get_contents($pipes[1]); @fclose($pipes[1]);
            $stderr = stream_get_contents($pipes[2]); @fclose($pipes[2]);
            $code   = proc_close($proc);
            return [($ok && $code === 0), $code, (string)$stdout, (string)$stderr, $off];
        };

        // Always use argv recipients (no -t)
        $base = [$sendmail, '-oi', '-i'];
        if ($verbose) $base[] = '-v';

        $withRcpt = function(array $a) use ($rcpt) {
            $a[] = '--'; $a[] = $rcpt; return $a;
        };

        // Variant 1: with -f (if valid)
        $v1 = $base;
        if ($envFrom) { $v1[] = '-f'; $v1[] = $envFrom; }
        $v1 = $withRcpt($v1);

        // Variant 2: without -f
        $v2 = $withRcpt($base);

        // Try V1 then V2
        [$ok, $code, $out, $err, $wrote] = $try($v1);
        if (!$ok && $envFrom) {
            [$ok, $code, $out, $err, $wrote] = $try($v2);
        }

        if ($ok) {
            // Trim long transcripts for logs
            $t = $verbose ? substr(($out . "\n" . $err), 0, 2000) : null;
            $this->lg($rid ?? '-', "FORWARD email OK", array_filter([
                'rcpt_hint'  => $rcpt,
                'mode'       => 'argv',
                'from'       => $envFrom,
                'transcript' => $t,
            ]));
            return true;
        }

        $this->lg($rid ?? '-', "FORWARD email FAILED", [
            'rcpt_hint'   => $rcpt,
            'exit'        => $code,
            'wrote_bytes' => $wrote,
            'stderr'      => $err,
            'stdout'      => $out,
            'from'        => $envFrom,
            'mode'        => 'argv',
        ]);
        return false;
    }

    /**
     * Forward the raw MIME to a webhook URL as JSON.
     * Body: {mime_b64, received_at?, auth_results?, spam_score?, rcpt_tos?}
     */
    private function forwardWebhook(string $mime, string $url, array $meta = [], ?string $rid = null): bool
    {
        if (!function_exists('curl_init')) {
            $this->lg($rid ?? '-', "FORWARD hook FAILED (no cURL)", ['url' => $url]);
            return false;
        }

        $payload = array_merge([
            'mime_b64'      => base64_encode($mime),
        ], $meta);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
        ]);

        $resp = curl_exec($ch);
        $errno = curl_errno($ch);
        $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $code < 200 || $code >= 300) {
            $this->lg($rid ?? '-', "FORWARD hook FAILED", ['url' => $url, 'http' => $code, 'errno' => $errno, 'resp' => (string)$resp]);
            return false;
        }

        $this->lg($rid ?? '-', "FORWARD hook OK", ['url' => $url, 'http' => $code]);
        return true;
    }

    /**
     * Fan-out to all forward destinations (email addresses or URLs).
     * Runs synchronously; consider queueing later if traffic grows.
     */
    private function performForwards(
        array $destinations,
        string $mime,
        array $meta,
        ?string $envelopeFrom,
        ?string $rid,
        Company $company,
        Domain $domain
    ): void
    {
        // You already have company/domain in scope just before calling performForwards()
        // Pass them in (or store into $meta) so we can route via Outbound.
        [$headers] = [$this->parseTopHeaders($mime)['map']];

        foreach ($destinations as $d) {
            $d = trim((string)$d);
            if ($d === '') continue;

            if ($this->isEmail($d)) {
                // ⬇ replace PHPMailer path with Outbound
                $this->forwardViaOutbound($mime, $d, $headers, $company, $domain, $rid);
                continue;
            }

            if ($this->isUrl($d)) {
                $this->forwardWebhook($mime, $d, $meta, $rid);
                continue;
            }

            $this->lg($rid ?? '-', "FORWARD skipped (unknown dest type)", ['dest' => $d]);
        }
    }



    private function forwardAsNewEmail(string $mime, string $to, array $origHeaders, ?string $rid = null): bool
    {
        $origFrom    = $origHeaders['from']    ?? '';
        $origSubject = $origHeaders['subject'] ?? '(no subject)';

        $m = new PHPMailer(true);
        try {
            // SMTP submission to your own server
            $m->isSMTP();
            $m->Host       = 'smtp.monkeysmail.com';
            $m->Port       = 587;
            $m->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $m->SMTPAuth   = true;
            $m->Username   = 'smtpuser';
            $m->Password   = 'S3cureP@ssw0rd';
            $m->Timeout    = 15;

            // Build a NEW message from your domain
            $m->setFrom('forwarder@monkeysmail.com', 'MonkeysMail Forwarder');
            if ($origFrom !== '') {
                // preserve reply path
                $m->addReplyTo($origFrom);
            }
            $m->addAddress($to);

            $m->Subject = 'Fwd: ' . $origSubject;
            $m->Body    = "Forwarded message attached.\n\n--\nTrace: {$rid}";

            // Attach original as message/rfc822 to keep it intact
            $m->addStringAttachment($mime, 'original.eml', 'base64', 'message/rfc822');

            $ok = $m->send();
            if ($ok) $this->lg($rid ?? '-', "FORWARD (resend-as-new) OK", ['rcpt' => $to]);
            else     $this->lg($rid ?? '-', "FORWARD (resend-as-new) FAILED (unknown)", ['rcpt' => $to]);
            return $ok;
        } catch (MailerException $e) {
            $this->lg($rid ?? '-', "FORWARD (resend-as-new) FAILED", ['rcpt' => $to, 'err' => $e->getMessage()]);
            return false;
        }
    }

    private function forwardViaOutbound(
        string $mime,
        string $to,
        array $origHeaders,
        Company $company,
        Domain $domain,
        ?string $traceId = null
    ): void {
        $origFrom    = $origHeaders['from']    ?? '';
        $origSubject = $origHeaders['subject'] ?? '(no subject)';

        $payload = [
            'from' => [
                'email' => 'forwarder@' . ($domain->getDomain() ?: 'monkeysmail.com'),
                'name'  => 'MonkeysMail Forwarder',
            ],
            'replyTo' => $origFrom ?: null,
            'to'      => [$to],
            'subject' => 'Fwd: ' . $origSubject,
            'text'    => "Forwarded message attached.\n\n--\nTrace: {$traceId}",
            'attachments' => [[
                'filename'    => 'original.eml',
                'contentType' => 'message/rfc822',
                'content'     => base64_encode($mime),
            ]],
            'tracking' => ['opens' => false, 'clicks' => false],
            'headers'  => [
                'Auto-Submitted'    => 'auto-forwarded',
                'X-Forwarded-From'  => $origFrom,
                'X-Forwarded-By'    => 'MonkeysMail',
                'X-Forward-TraceId' => (string)$traceId,
            ],
        ];

        // enqueue through your existing pipeline (quotas/events/retries)
        $this->outbound->createAndEnqueue($payload, $company, $domain);
    }


}
