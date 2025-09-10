<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ApiKey;
use App\Entity\Company;
use App\Entity\Domain;
use App\Entity\Message;
use App\Entity\User;
use App\Entity\RateLimitCounter;
use App\Entity\UsageAggregate;
use App\Entity\MessageRecipient;
use App\Entity\MessageEvent;
use App\Service\OutboundMailService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use MonkeysLegion\Http\Message\JsonResponse;
use MonkeysLegion\Http\Message\Response;
use MonkeysLegion\Query\QueryBuilder;
use MonkeysLegion\Repository\EntityRepository;
use MonkeysLegion\Repository\RepositoryFactory;
use MonkeysLegion\Router\Attributes\Route;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use MonkeysLegion\Http\Message\Stream;

// PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;


final class MessageController
{
    /** @var \Redis|null */
    private ?\Redis $redis = null;
    private string $redisDsn;

    /** @var EntityRepository */
    private $messageRepo;
    /** @var EntityRepository */
    private $messageRecipientRepo;
    /** @var EntityRepository */
    private $messageEventRepo;

    public function __construct(
        private RepositoryFactory $repos,
        private QueryBuilder      $qb,
        private OutboundMailService $mailService,
        string $redisDsn = 'redis://:S3cureRedisPa55!@10.0.0.164:6379/0',
    ) {
        $this->messageRepo          = $this->repos->getRepository(Message::class);
        $this->messageRecipientRepo = $this->repos->getRepository(MessageRecipient::class);
        $this->messageEventRepo     = $this->repos->getRepository(MessageEvent::class);
        $this->redisDsn             = $redisDsn;
    }

    /* =========================================================================
     * JWT entrypoint — send as a user within a company
     * ========================================================================= */
    /**
     * @throws \DateMalformedStringException
     * @throws \JsonException
     */
    #[Route(methods: 'POST', path: '/companies/{hash}/messages/send/{domain}')]
    public function sendForCompany(ServerRequestInterface $request): JsonResponse
    {
        $userId = (int) $request->getAttribute('user_id', 0);
        if ($userId <= 0) throw new RuntimeException('Unauthorized', 401);

        $hash = $request->getAttribute('hash');
        if (!is_string($hash) || strlen($hash) !== 64) throw new RuntimeException('Invalid company identifier', 400);

        /** @var \App\Repository\CompanyRepository $companyRepo */
        $companyRepo = $this->repos->getRepository(Company::class);
        /** @var Company|null $company */
        $company = $companyRepo->findOneBy(['hash' => $hash]);
        if (!$company) throw new RuntimeException('Company not found', 404);

        $domainId  = (int) $request->getAttribute('domain', 0);
        $domainRepo = $this->repos->getRepository(Domain::class);
        /** @var Domain|null $domain */
        $domain = $domainRepo->findOneBy(['id' => $domainId]);

        $belongs = array_filter($company->getUsers() ?? [], fn(User $u) => $u->getId() === $userId);
        if (empty($belongs)) throw new RuntimeException('Forbidden', 403);

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        // Count per *message*. For per-recipient counting, parse the body and compute units.
        $this->enforceAndConsumeMessageQuota($company, $now, 1);

        $body = json_decode((string)$request->getBody(), true) ?: [];

        $result = $this->mailService->createAndEnqueue($body, $company, $domain);
        $status = (string)($result['status'] ?? '');

        $http   = match ($status) {
            'queued'       => 202,
            'preview'      => 200,
            'queue_failed' => 503,
            default        => 200,
        };

        return new JsonResponse($result, $http);
    }

    /* =========================================================================
     * Core flow (preview / send / queue)
     * ========================================================================= */
    /**
     * @throws \DateMalformedStringException
     * @throws \JsonException
     */
    private function handleSend(ServerRequestInterface $request, Company $company, Domain $domain): JsonResponse
    {
        $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $body   = json_decode((string)$request->getBody(), true, JSON_THROW_ON_ERROR);

        // ---- Validate basic fields
        $fromEmail = trim((string)($body['from']['email'] ?? ''));
        if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A valid from.email is required', 422);
        }
        $fromName  = isset($body['from']['name']) ? trim((string)$body['from']['name']) : null;
        $replyTo   = isset($body['replyTo']) ? trim((string)$body['replyTo']) : null;
        if ($replyTo && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('replyTo must be a valid email', 422);
        }

        $subject = isset($body['subject']) ? trim((string)$body['subject']) : null;
        $text    = isset($body['text']) ? (string)$body['text'] : null;
        $html    = isset($body['html']) ? (string)$body['html'] : null;

        $to  = $this->normalizeEmails($body['to']  ?? []);
        $cc  = $this->normalizeEmails($body['cc']  ?? []);
        $bcc = $this->normalizeEmails($body['bcc'] ?? []);
        if (empty($to) && empty($cc) && empty($bcc)) {
            throw new RuntimeException('At least one recipient (to/cc/bcc) is required', 422);
        }

        $headers  = isset($body['headers']) && is_array($body['headers']) ? $this->sanitizeHeaders($body['headers']) : null;
        $tracking = isset($body['tracking']) && is_array($body['tracking']) ? $body['tracking'] : [];

        // ✅ DEFAULT tracking ON unless explicitly disabled
        $opensEnabled  = array_key_exists('opens',  $tracking) ? (bool)$tracking['opens']  : true;
        $clicksEnabled = array_key_exists('clicks', $tracking) ? (bool)$tracking['clicks'] : true;

        $attachments = [];
        if (!empty($body['attachments']) && is_array($body['attachments'])) {
            foreach ($body['attachments'] as $att) {
                if (!is_array($att)) continue;
                $fn  = trim((string)($att['filename'] ?? ''));
                $ct  = trim((string)($att['contentType'] ?? 'application/octet-stream'));
                $b64 = (string)($att['content'] ?? '');
                if ($fn !== '' && $b64 !== '') {
                    $attachments[] = ['filename' => $fn, 'contentType' => $ct, 'content' => $b64];
                }
            }
        }

        $dryRun = (bool)($body['dryRun'] ?? false);
        $queue  = (bool)($body['queue']  ?? false);

        // ---- Persist Message scaffold
        /** @var \App\Repository\MessageRepository $messageRepo */
        $messageRepo = $this->repos->getRepository(Message::class);

        $msg = new Message();
        $msg->setCompany($company)
            ->setDomain($domain)
            ->setFrom_email($fromEmail)
            ->setFrom_name($fromName)
            ->setReply_to($replyTo)
            ->setSubject($subject)
            ->setHtml_body($html)
            ->setText_body($text)
            ->setHeaders($headers)
            ->setOpen_tracking($opensEnabled)      // <- default true
            ->setClick_tracking($clicksEnabled)    // <- default true
            ->setAttachments(!empty($attachments) ? $attachments : null)
            ->setCreated_at($nowUtc)
            ->setQueued_at($nowUtc)
            ->setFinal_state($dryRun ? 'preview' : ($queue ? 'queued' : 'queued'));
        $messageRepo->save($msg);

        // Persist recipients and generate rids
        $recipients = $this->persistRecipients($msg, $to, $cc, $bcc);

        // ---- Tracking helpers using PUBLIC BASE URL derived from request
        $base = $this->publicBaseFromRequest($request) ?? 'https://smtp.monkeysmail.com';

        $b64url = static function (string $s): string {
            return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        };
        $trackingPixelTag = static function (string $rid, string $base): string {
            return '<img src="'.$base.'/t/o/'.$rid.'.gif" width="1" height="1" style="display:none" alt=""/>';
        };
        $rewriteLinksWithRid = static function (string $htmlIn, string $rid, string $base) use ($b64url): string {
            if ($htmlIn === '') return $htmlIn;
            return preg_replace_callback(
                '#(<a\b[^>]*\bhref=["\'])(https?://[^"\']+)(["\'][^>]*>)#i',
                function($m) use ($base, $rid, $b64url) {
                    $orig  = $m[2];
                    $redir = $base.'/t/c/'.$rid.'?u='.$b64url($orig);
                    return $m[1].$redir.$m[3];
                },
                $htmlIn
            ) ?? $htmlIn;
        };

        // ---- Preview
        if ($dryRun) {
            return new JsonResponse([
                'status'  => 'preview',
                'message' => $this->shapeMessage($msg),
                'smtp'    => ['host' => 'smtp.monkeysmail.com', 'port' => 587, 'secure' => 'STARTTLS'],
                'envelope' => [
                    'from' => $fromName ? sprintf('%s <%s>', $fromName, $fromEmail) : $fromEmail,
                    'to'   => $to,
                    'cc'   => $cc,
                    'bcc'  => $bcc,
                ],
                'headers' => $headers,
                'subject' => $subject,
                'text'    => $text,
                'html'    => $html,
                'attachments' => array_map(fn($a) => [
                    'filename'    => $a['filename'],
                    'contentType' => $a['contentType'],
                    'length'      => strlen($a['content']),
                ], $attachments),
            ]);
        }

        // ---- Queue path unchanged (uses the same base/rewrites)
        if ($queue) {
            $jobs = [];
            foreach (['to','cc','bcc'] as $type) {
                foreach ($recipients[$type] as $email) {
                    $row = $this->qb->duplicate()
                        ->select(['track_token'])
                        ->from('messagerecipients')
                        ->where('message_id', '=', (int)$msg->getId())
                        ->andWhere('email', '=', $email)
                        ->andWhere('type', '=', $type)
                        ->fetchOne();

                    $rid = (string)($row['track_token'] ?? '');

                    $htmlForThisRcpt = $html;
                    if ($clicksEnabled && $htmlForThisRcpt !== '') {
                        $htmlForThisRcpt = $rewriteLinksWithRid($htmlForThisRcpt, $rid, $base);
                    }
                    if ($opensEnabled && $htmlForThisRcpt !== '') {
                        $htmlForThisRcpt .= $trackingPixelTag($rid, $base);
                    }

                    $jobHeaders = ($headers ?? []);
                    $jobHeaders['X-MM-RID']    = $rid;
                    $jobHeaders['X-MM-Opens']  = $opensEnabled ? '1' : '0';
                    $jobHeaders['X-MM-Clicks'] = $clicksEnabled ? '1' : '0';

                    $jobs[] = [
                        'message_id' => $msg->getId(),
                        'company_id' => $company->getId(),
                        'created_at' => $nowUtc->format(DATE_ATOM),
                        'envelope'   => [
                            'to'      => [$email],
                            'cc'      => [],
                            'bcc'     => [],
                            'headers' => $jobHeaders,
                        ],
                        'payload'    => [
                            'fromEmail'   => $fromEmail,
                            'fromName'    => $fromName,
                            'replyTo'     => $replyTo,
                            'subject'     => $subject,
                            'text'        => $text,
                            'html'        => $htmlForThisRcpt,
                            'attachments' => $attachments,
                        ],
                    ];
                }
            }

            $ok = true;
            foreach ($jobs as $job) {
                $ok = $this->enqueueToRedis('mail:outbound', $job) && $ok;
            }

            if (!$ok) {
                $msg->setFinal_state('queue_failed');
                $messageRepo->save($msg);
                return new JsonResponse([
                    'status'  => 'queue_failed',
                    'reason'  => 'Could not push to Redis',
                    'message' => $this->shapeMessage($msg),
                ], 503);
            }

            return new JsonResponse([
                'status'  => 'queued',
                'queued'  => count($jobs),
                'message' => $this->shapeMessage($msg),
            ], 202);
        }

        // ---- Immediate SMTP per-recipient (with tracking + debug headers)
        $errors   = [];
        $sent     = 0;
        $lastMid  = null;

        foreach (['to','cc','bcc'] as $type) {
            foreach ($recipients[$type] as $email) {
                $row = $this->qb->duplicate()
                    ->select(['track_token'])
                    ->from('messagerecipients')
                    ->where('message_id', '=', (int)$msg->getId())
                    ->andWhere('email', '=', $email)
                    ->andWhere('type', '=', $type)
                    ->fetchOne();

                $rid = (string)($row['track_token'] ?? '');

                $htmlForRcpt = $html;
                if ($clicksEnabled && $htmlForRcpt !== '') {
                    $htmlForRcpt = $rewriteLinksWithRid($htmlForRcpt, $rid, $base);
                }
                if ($opensEnabled && $htmlForRcpt !== '') {
                    $htmlForRcpt .= $trackingPixelTag($rid, $base);
                }

                $perHeaders = ($headers ?? []);
                $perHeaders['X-MM-RID']    = $rid;
                $perHeaders['X-MM-Opens']  = $opensEnabled ? '1' : '0';
                $perHeaders['X-MM-Clicks'] = $clicksEnabled ? '1' : '0';

                $env = [
                    'fromEmail'   => $fromEmail,
                    'fromName'    => $fromName,
                    'replyTo'     => $replyTo,
                    'to'          => [$email],
                    'cc'          => [],
                    'bcc'         => [],
                    'subject'     => $subject,
                    'text'        => $text,
                    'html'        => $htmlForRcpt,
                    'headers'     => $perHeaders,
                    'attachments' => $attachments,
                ];

                $res = $this->smtpSend($msg, $env);
                if (!($res['ok'] ?? false)) {
                    $errors[] = $email . ': ' . ($res['error'] ?? 'unknown');
                } else {
                    $sent++;
                    $lastMid = $res['message_id'] ?? $lastMid;
                }
            }
        }

        // Update DB state
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($sent > 0) {
            $msg->setSent_at($now);
        }
        if ($lastMid) {
            $msg->setMessage_id($lastMid);
        }
        $msg->setFinal_state(
            $sent === 0         ? 'failed'  :
                (count($errors) > 0 ? 'partial' : 'sent')
        );
        $messageRepo->save($msg);

        if ($sent === 0) {
            return new JsonResponse([
                'status'  => 'error',
                'errors'  => $errors,
                'message' => $this->shapeMessage($msg),
            ], 502);
        }

        return new JsonResponse([
            'status'  => (count($errors) > 0 ? 'partial' : 'sent'),
            'sent'    => $sent,
            'errors'  => $errors,
            'message' => $this->shapeMessage($msg),
        ], 201);
    }

    /**
     * Build a public base URL (scheme + host[:port]) from the incoming request,
     * falling back to your configured domain if proxies don’t pass headers.
     */
    private function publicBaseFromRequest(ServerRequestInterface $r): ?string
    {
        $headers = $r->getHeaders();
        $proto = $r->getHeaderLine('X-Forwarded-Proto') ?: $r->getHeaderLine('X-Proto') ?: ($r->getUri()->getScheme() ?: 'https');
        $host  = $r->getHeaderLine('X-Forwarded-Host')  ?: $r->getHeaderLine('Host')      ?: $r->getUri()->getHost();
        if ($host === '') return null;
        // Optional forwarded port
        $port  = $r->getHeaderLine('X-Forwarded-Port') ?: $r->getUri()->getPort();
        $portS = $port && !in_array((int)$port, [80,443], true) ? ':' . (int)$port : '';
        return sprintf('%s://%s%s', $proto, $host, $portS);
    }

    /* =========================================================================
     * SMTP (PHPMailer) sender
     * ========================================================================= */

    /**
     * @param Message $msg
     * @param array $data
     * @return array{ok: bool, message_id?: string, error?: string}
     */
    private function smtpSend(Message $msg, array $data): array
    {
        $mail = new PHPMailer(true);
        try {
            // SMTP config (use your server)
            $mail->isSMTP();
            $mail->Host       = 'smtp.monkeysmail.com';
            $mail->Port       = 587;
            $mail->SMTPAuth   = true;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Username   = $_ENV['SMTP_USER']     ?? 'smtpuser';
            $mail->Password   = $_ENV['SMTP_PASSWORD'] ?? 'S3cureP@ssw0rd';
            $mail->Timeout    = 15;

            // Envelope
            $mail->setFrom($data['fromEmail'], $data['fromName'] ?: '');
            foreach ($data['to'] as $rcpt)  $mail->addAddress($rcpt);
            foreach ($data['cc'] as $rcpt)  $mail->addCC($rcpt);
            foreach ($data['bcc'] as $rcpt) $mail->addBCC($rcpt);
            if (!empty($data['replyTo'])) $mail->addReplyTo($data['replyTo']);

            // Headers
            $mail->XMailer = 'MonkeysMail/1.0';
            foreach ($data['headers'] as $k => $v) {
                $mail->addCustomHeader($k, (string)$v);
            }

            // Message-ID (we generate one so we can store it)
            $generatedId = sprintf('<%s@mta-1.monkeysmail.com>', bin2hex(random_bytes(16)));
            $mail->MessageID = $generatedId;

            // Body
            $mail->Subject = (string)($data['subject'] ?? '');
            if (!empty($data['html'])) {
                $mail->isHTML(true);
                $mail->Body    = (string)$data['html'];
                $mail->AltBody = (string)($data['text'] ?? strip_tags((string)$data['html']));
            } else {
                $mail->isHTML(false);
                $mail->Body    = (string)($data['text'] ?? '');
            }

            // Attachments
            foreach ($data['attachments'] as $att) {
                $bin = base64_decode($att['content'], true);
                if ($bin === false) continue;
                $mail->addStringAttachment($bin, $att['filename'], PHPMailer::ENCODING_BASE64, $att['contentType']);
            }

            $mail->send();

            return ['ok' => true, 'message_id' => $generatedId];
        } catch (PHPMailerException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /* =========================================================================
     * Helpers
     * ========================================================================= */

    /** Normalize string|string[] into array of valid emails */
    private function normalizeEmails(mixed $value): array
    {
        $arr = is_array($value) ? $value : (is_string($value) ? [$value] : []);
        $out = [];
        foreach ($arr as $v) {
            $v = trim((string)$v);
            if ($v !== '' && filter_var($v, FILTER_VALIDATE_EMAIL)) $out[] = strtolower($v);
        }
        // de-duplicate
        return array_values(array_unique($out));
    }

    /** Defensive header whitelist (simple). Expand as needed. */
    private function sanitizeHeaders(array $headers): array
    {
        $allowed = [
            // Common safe headers
            'X-Campaign', 'X-Tag', 'List-Unsubscribe', 'List-ID',
            'In-Reply-To', 'References',
        ];
        $out = [];
        foreach ($headers as $k => $v) {
            $k = trim((string)$k);
            if ($k === '' || preg_match('/[\r\n]/', $k)) continue; // no header injection
            if (!in_array($k, $allowed, true)) continue;
            $out[$k] = (string)$v;
        }
        return $out;
    }

    /** Shape Message for API response */
    private function shapeMessage(Message $m): array
    {
        return [
            'id'          => $m->getId(),
            'company_id'  => $m->getCompany()?->getId(),
            'domain_id'   => $m->getDomain()?->getId(),
            'from'        => ['email' => $m->getFrom_email(), 'name' => $m->getFrom_name()],
            'replyTo'     => $m->getReply_to(),
            'subject'     => $m->getSubject(),
            'createdAt'   => $m->getCreated_at()?->format(\DateTimeInterface::ATOM),
            'queuedAt'    => $m->getQueued_at()?->format(\DateTimeInterface::ATOM),
            'sentAt'      => $m->getSent_at()?->format(\DateTimeInterface::ATOM),
            'state'       => $m->getFinal_state(),
            'messageId'   => $m->getMessage_id(),
        ];
    }

    /** Read ApiKey from X-API-Key header and fetch entity using (prefix, hash).
     * @throws \DateMalformedStringException
     */
    private function readApiKeyFromHeader(ServerRequestInterface $request): ?ApiKey
    {
        $hdr = trim($request->getHeaderLine('X-API-Key'));
        if ($hdr === '' || !str_contains($hdr, '.')) {
            return null;
        }

        // Split into <prefix>.<secret>
        [$prefixRaw, $secretRaw] = explode('.', $hdr, 2);
        $prefix = strtolower(trim($prefixRaw));
        $secret = strtolower(trim($secretRaw));

        if ($prefix === '' || $secret === '') {
            return null;
        }

        /** @var \App\Repository\ApiKeyRepository $apiKeyRepo */
        $apiKeyRepo = $this->repos->getRepository(ApiKey::class);

        // 1) Try preferred schema: WHERE prefix = ? AND hash = sha256(secret)
        $apiKey = $apiKeyRepo->findOneBy(['prefix' => $prefix, 'hash' => $secret]);
        if (!$apiKey) {
            return null;
        }

        // Deny revoked
        if ($apiKey->getRevoked_at() !== null) {
            return null;
        }
        $apiKey->setLast_used_at(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $apiKeyRepo->save($apiKey);

        return $apiKey;
    }

    /** Ensure ApiKey covers required scope (handles split-JSON, csv, wildcards, mail/messages aliases). */
    private function assertApiKeyAllowed(ApiKey $key, string $scope): void
    {
        $raw = $key->getScopes();

        // ---- Normalize to array<string>
        $scopes = [];

        if (is_string($raw)) {
            // Try JSON array first
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $scopes = $decoded; $src = 'json-string';
            } else {
                // Fallback: CSV / whitespace separated
                $scopes = preg_split('/[\s,]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $src = 'csv-string';
            }
        } elseif (is_array($raw)) {
            $arr = array_map('strval', $raw);

            // Case A: array contains one proper JSON string already
            if (count($arr) === 1 && preg_match('/^\s*\[.*\]\s*$/', $arr[0])) {
                $decoded = json_decode($arr[0], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $scopes = $decoded; $src = 'json-in-array';
                }
            }

            // Case B: "split JSON" pieces like ['["mail:send"', ' "mail:read"', ... , ' "users:manage"]']
            if (!$scopes && preg_match('/\[/', $arr[0] ?? '') && preg_match('/\]$/', end($arr) ?: '')) {
                $joined = implode('', $arr);
                $decoded = json_decode($joined, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $scopes = $decoded; $src = 'json-split';
                }
            }

            // Case C: plain array of scopes (or weird pieces) -> flatten by splitting commas & stripping quotes/brackets
            if (!$scopes) {
                $flat = [];
                foreach ($arr as $piece) {
                    // remove surrounding [ ], quotes, then split on commas
                    $piece = trim($piece);
                    $piece = trim($piece, " \t\n\r\0\x0B[]");
                    $parts = array_map('trim', explode(',', $piece));
                    foreach ($parts as $p) {
                        $p = trim($p, " \t\n\r\0\x0B\"'");
                        if ($p !== '') $flat[] = $p;
                    }
                }
                $scopes = $flat; $src = 'array-flattened';
            }
        }

        // Clean: lower/trim, unique
        $scopes = array_values(array_unique(array_map(
            fn($s) => strtolower(trim((string)$s)),
            $scopes
        )));

        // ---- Fast passes
        if (in_array('*', $scopes, true))               { error_log("scope pass: *"); return; }
        if (in_array('mail', $scopes, true) ||
            in_array('messages', $scopes, true))        { error_log("scope pass: bucket"); return; }
        if (in_array('mail:*', $scopes, true) ||
            in_array('mail.*', $scopes, true) ||
            in_array('messages:*', $scopes, true) ||
            in_array('messages.*', $scopes, true))      { error_log("scope pass: wildcard"); return; }

        // ---- Exact + alias variants (mail ⇔ messages, dot ⇔ colon)
        $alts = [
            strtolower($scope),
            str_replace(':', '.', strtolower($scope)),
            str_replace('.', ':', strtolower($scope)),
        ];
        $swap = [];
        foreach ($alts as $v) {
            if (str_starts_with($v, 'mail'))      $swap[] = preg_replace('/^mail/', 'messages', $v, 1);
            if (str_starts_with($v, 'messages'))  $swap[] = preg_replace('/^messages/', 'mail', $v, 1);
        }
        $alts = array_values(array_unique(array_merge($alts, $swap)));

        foreach ($alts as $s) {
            if (in_array($s, $scopes, true)) {
                error_log("scope pass: matched '{$s}'");
                return;
            }
        }

        throw new RuntimeException('API key missing required scope: ' . $scope, 403);
    }


    /** Push job to Redis if configured via REDIS_URL, return true/false */
    private function enqueueToRedis(string $list, array $job): bool
    {
        $dsn = $_ENV['REDIS_URL'] ?? getenv('REDIS_URL') ?: '';
        if ($dsn === '') return false;

        // Expecting format: redis://:password@host:port/db
        $parts = parse_url($dsn);
        if (!$parts || !isset($parts['host'])) return false;

        $host = $parts['host'];
        $port = isset($parts['port']) ? (int)$parts['port'] : 6379;
        $pass = isset($parts['pass']) ? $parts['pass'] : null;
        $db   = 0;
        if (isset($parts['path'])) {
            $dbStr = ltrim($parts['path'], '/');
            if ($dbStr !== '' && ctype_digit($dbStr)) $db = (int)$dbStr;
        }

        try {
            if (class_exists(\Redis::class)) {
                $r = new \Redis();
                $r->connect($host, $port, 2.0);
                if ($pass) $r->auth($pass);
                if ($db)   $r->select($db);
                $r->lPush($list, json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return true;
            }

            // If ext-redis isn’t installed, silently skip
            return false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @throws \Throwable
     * @throws \JsonException
     */
    #[Route(methods: 'GET', path: '/companies/{hash}/messages')]
    public function listMessages(ServerRequestInterface $request): JsonResponse
    {
        // 1) Authentication & Company Resolution
        $userId = $this->authenticateUser($request);
        $company = $this->resolveCompany($request->getAttribute('hash'), $userId);
        // 2) Parse and validate query parameters
        $filters = $this->parseMessageFilters($request->getQueryParams());
        // 3) Build and execute query
        $result = $this->queryMessages($company->getId(), $filters);
        // 4) Format response
        return new JsonResponse($this->formatMessagesResponse($result, $filters));
    }

    /**
     * Authenticate user from request
     */
    private function authenticateUser(ServerRequestInterface $request): int
    {
        $userId = (int)$request->getAttribute('user_id', 0);
        if ($userId <= 0) {
            throw new RuntimeException('Unauthorized', 401);
        }
        return $userId;
    }

    /**
     * Resolve and authorize company access
     */
    private function resolveCompany(string $hash, int $userId): Company
    {
        // Validate hash format
        if (!preg_match('/^[a-f0-9]{64}$/', $hash)) {
            throw new RuntimeException('Invalid company identifier', 400);
        }

        // Find company
        /** @var \App\Repository\CompanyRepository $companyRepo */
        $companyRepo = $this->repos->getRepository(Company::class);
        $company = $companyRepo->findOneBy(['hash' => $hash]);

        if (!$company) {
            throw new RuntimeException('Company not found', 404);
        }

        // Check user belongs to company
        $belongs = array_filter(
            $company->getUsers() ?? [],
            fn(User $u) => $u->getId() === $userId
        );

        if (empty($belongs)) {
            throw new RuntimeException('Forbidden', 403);
        }

        return $company;
    }

    /**
     * Parse and validate message filters from query params
     */
    private function parseMessageFilters(array $queryParams): array
    {
        $q = $queryParams;

        return [
            // Domain filtering
            'domain_ids' => $this->parseIntegerList($q['domain_id'] ?? ''),

            // Pagination
            'page' => max(1, (int)($q['page'] ?? 1)),
            'per_page' => min(200, max(1, (int)($q['perPage'] ?? 25))),

            // Sorting
            'sort' => $this->validateSort($q['sort'] ?? 'created_at'),
            'order' => $this->validateOrder($q['order'] ?? 'desc'),

            // Date/time filters
            'date_from' => $this->parseDateOrDateTime($q['date_from'] ?? null, false),
            'date_to'   => $this->parseDateOrDateTime($q['date_to']   ?? null, true),
            'hour_from' => $this->parseHourNullable($q['hour_from'] ?? null),
            'hour_to' => $this->parseHourNullable($q['hour_to'] ?? null),

            // State filtering
            'states' => $this->parseStates($q['state'] ?? null),

            // Text search filters
            'from_like' => $this->trimOrNull($q['from'] ?? null),
            'to_like' => $this->trimOrNull($q['to'] ?? null),
            'subject_like' => $this->trimOrNull($q['subject'] ?? null),
            'message_id' => $this->trimOrNull($q['message_id'] ?? null),

            // Tracking filters
            'has_opens' => $this->parseBoolNullable($q['has_opens'] ?? null),
            'has_clicks' => $this->parseBoolNullable($q['has_clicks'] ?? null),
        ];
    }

    /**
     * Query messages using the enhanced QueryBuilder
     * @throws \Throwable
     */
    private function queryMessages(int $companyId, array $filters): array
    {
        $qb = $this->qb->duplicate();

        // Base query with join
        $qb->select([
            'm.*',
            'd.domain AS domain_name'
        ])
            ->from('messages', 'm')
            ->leftJoin('domains', 'd', 'd.id', '=', 'm.domain_id')
            ->where('m.company_id', '=', $companyId);

        // Apply domain filter
        if (!empty($filters['domain_ids'])) {
            $qb->whereIn('m.domain_id', $filters['domain_ids']);
        }

        // Apply date filters
        if ($filters['date_from']) {
            $qb->andWhere('m.created_at', '>=', $filters['date_from']->format('Y-m-d H:i:s'));
        }
        if ($filters['date_to']) {
            $qb->andWhere('m.created_at', '<', $filters['date_to']->format('Y-m-d H:i:s'));
        }

        // Apply hour filters (using raw SQL for HOUR function)
        $this->applyHourFilter($qb, $filters['hour_from'], $filters['hour_to']);

        // Apply state filter
        if (!empty($filters['states'])) {
            $qb->whereIn('m.final_state', $filters['states']);
        }

        // Apply text search filters
        if ($filters['from_like']) {
            $qb->whereLike('m.from_email', '%' . $filters['from_like'] . '%');
        }
        if ($filters['subject_like']) {
            $qb->whereLike('m.subject', '%' . $filters['subject_like'] . '%');
        }
        if ($filters['message_id']) {
            $qb->andWhere('m.message_id', '=', $filters['message_id']);
        }

        // Special handling for 'to' search (searches multiple fields)
        if ($filters['to_like']) {
            $pattern = '%' . $filters['to_like'] . '%';
            $qb->whereGroup(function($q) use ($pattern) {
                $q->whereLike('m.headers', $pattern)
                    ->orWhereLike('m.html_body', $pattern)
                    ->orWhereLike('m.text_body', $pattern);
            });
        }

        // Apply tracking filters
        if ($filters['has_opens'] !== null) {
            if ($filters['has_opens']) {
                $qb->andWhere('m.open_tracking', '=', 1);
            } else {
                $qb->whereGroup(function($q) {
                    $q->where('m.open_tracking', '=', 0)
                        ->orWhereNull('m.open_tracking');
                });
            }
        }

        if ($filters['has_clicks'] !== null) {
            if ($filters['has_clicks']) {
                $qb->andWhere('m.click_tracking', '=', 1);
            } else {
                $qb->whereGroup(function($q) {
                    $q->where('m.click_tracking', '=', 0)
                        ->orWhereNull('m.click_tracking');
                });
            }
        }
        // Count total items before pagination
        $total = $qb->count();

        // Apply sorting and pagination
        $qb->orderBy('m.' . $filters['sort'], $filters['order'])
            ->paginate($filters['page'], $filters['per_page']);

        // Execute query
        $items = $qb->fetchAll();

        // Attach recipients in one extra query
        $messageIds = array_values(array_filter(array_map(fn($r) => (int)($r['id'] ?? 0), $items)));
        $recMap = $this->loadRecipientsForMessageIds($messageIds);

        // Shape payloads and merge recipients
        $items = array_map(function(array $row) use ($recMap) {
            $item = $this->formatMessageItem($row);
            $mid  = $item['id'] ?? 0;
            $item['recipients'] = $recMap[$mid] ?? ['to'=>[], 'cc'=>[], 'bcc'=>[]];
            return $item;
        }, $items);

        return [
            'items'       => $items,
            'total'       => $total,
            'page'        => $filters['page'],
            'per_page'    => $filters['per_page'],
            'total_pages' => (int)max(1, ceil($total / $filters['per_page'])),
        ];
    }

    /**
     * Apply hour filtering with proper handling of cross-midnight ranges
     */
    private function applyHourFilter($qb, ?int $hourFrom, ?int $hourTo): void
    {
        if ($hourFrom === null && $hourTo === null) {
            return;
        }

        $hf = $hourFrom ?? 0;
        $ht = $hourTo ?? 23;

        if ($hf <= $ht) {
            // Normal range (e.g., 9-17)
            $qb->whereRaw(
                'HOUR(m.created_at) BETWEEN ? AND ?',
                [$hf, $ht]
            );
        } else {
            // Cross-midnight range (e.g., 22-3)
            $qb->whereGroup(function($q) use ($hf, $ht) {
                $q->whereRaw('HOUR(m.created_at) >= ?', [$hf])
                    ->orWhere('HOUR(m.created_at)', '<=', $ht);
            });
        }
    }

    /**
     * Format the response data
     */
    private function formatMessagesResponse(array $result, array $filters): array
    {
        $items = array_map(
            fn($row) => isset($row['from_email']) ? $this->formatMessageItem($row) : $row,
            $result['items']
        );

        return [
            'meta' => [
                'page'       => $result['page'],
                'perPage'    => $result['per_page'],
                'total'      => $result['total'],
                'totalPages' => $result['total_pages'],
                'sort'       => $filters['sort'],
                'order'      => $filters['order'],
                'filters'    => [
                    'domain_id'  => $filters['domain_ids'],
                    'date_from'  => $filters['date_from']?->format(DATE_ATOM),
                    'date_to'    => $filters['date_to']?->format(DATE_ATOM),
                    'hour_from'  => $filters['hour_from'],
                    'hour_to'    => $filters['hour_to'],
                    'state'      => $filters['states'],
                    'from'       => $filters['from_like'],
                    'to'         => $filters['to_like'],
                    'subject'    => $filters['subject_like'],
                    'message_id' => $filters['message_id'],
                    'has_opens'  => $filters['has_opens'],
                    'has_clicks' => $filters['has_clicks'],
                ],
            ],
            'items' => $items,
        ];
    }

    private function col(array $row, string $snake, ?string $camel = null): mixed
    {
        if (array_key_exists($snake, $row)) return $row[$snake];
        if ($camel && array_key_exists($camel, $row)) return $row[$camel];
        return null;
    }

    private function formatMessageItem(array $row): array
    {
        // Prefer snake_case columns (raw DB), fall back to camelCase if already shaped
        $fromEmail = (string)($this->col($row, 'from_email', 'fromEmail') ?? '');
        $fromName  = $this->col($row, 'from_name', 'fromName');
        $replyTo   = $this->col($row, 'reply_to', 'replyTo');
        $subject   = $this->col($row, 'subject', 'subject');
        $created   = $this->col($row, 'created_at', 'createdAt');
        $queued    = $this->col($row, 'queued_at',  'queuedAt');
        $sent      = $this->col($row, 'sent_at',    'sentAt');
        $state     = $this->col($row, 'final_state','state');
        $msgId     = $this->col($row, 'message_id', 'messageId');
        $domainNm  = $this->col($row, 'domain_name','domainName');

        return [
            'id'         => isset($row['id']) ? (int)$row['id'] : (int)($row['id'] ?? 0),
            'company_id' => isset($row['company_id']) ? (int)$row['company_id'] : (int)($row['companyId'] ?? 0),
            'domain_id'  => isset($row['domain_id'])  ? (int)$row['domain_id']  : (int)($row['domainId'] ?? 0),
            'from'       => [
                'email' => $fromEmail,
                'name'  => $fromName !== null ? (string)$fromName : null,
            ],
            'replyTo'    => $replyTo !== null ? (string)$replyTo : null,
            'subject'    => $subject !== null ? (string)$subject : null,
            'createdAt'  => is_string($created) ? $this->toIso8601($created) : (is_string($row['createdAt'] ?? null) ? $row['createdAt'] : null),
            'queuedAt'   => is_string($queued)  ? $this->toIso8601($queued)  : (is_string($row['queuedAt'] ?? null)  ? $row['queuedAt']  : null),
            'sentAt'     => is_string($sent)    ? $this->toIso8601($sent)    : (is_string($row['sentAt'] ?? null)    ? $row['sentAt']    : null),
            'state'      => $state !== null ? (string)$state : null,
            'messageId'  => $msgId !== null ? (string)$msgId : null,
            'domainName' => $domainNm !== null ? (string)$domainNm : null,
            'recipients' => $row['recipients'] ?? ['to'=>[], 'cc'=>[], 'bcc'=>[]],
        ];
    }


    /* ===== Helper Methods ===== */

    /**
     * Parse comma-separated list of integers
     */
    private function parseIntegerList(string $value): array
    {
        if ($value === '') {
            return [];
        }

        return array_values(array_filter(
            array_map(
                fn($v) => (int)trim($v),
                explode(',', $value)
            ),
            fn($v) => $v > 0
        ));
    }

    /**
     * Validate and return allowed sort field
     */
    private function validateSort(string $sort): string
    {
        $allowedSort = ['created_at', 'queued_at', 'sent_at'];
        $sort = strtolower($sort);

        return in_array($sort, $allowedSort, true) ? $sort : 'created_at';
    }

    /**
     * Validate and return sort order
     */
    private function validateOrder(string $order): string
    {
        $order = strtolower($order);
        return in_array($order, ['asc', 'desc'], true) ? $order : 'desc';
    }

    /**
     * Parse and validate states from CSV string
     */
    private function parseStates(?string $stateRaw): array
    {
        if (!$stateRaw) {
            return [];
        }

        $states = array_values(array_filter(
            array_map('trim', explode(',', strtolower($stateRaw)))
        ));

        $allowedStates = ['queued', 'sent', 'failed', 'preview', 'queue_failed'];

        return array_values(array_intersect($states, $allowedStates));
    }

    /**
     * Trim string or return null
     */
    private function trimOrNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * Parse datetime string to DateTimeImmutable or null
     */
    private function parseDateOrDateTime(?string $v, bool $isEnd = false): ?DateTimeImmutable
    {
        if ($v === null || $v === '') return null;

        $s = trim((string)$v);

        try {
            // If only a date is provided (YYYY-MM-DD)
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
                $dt = new DateTimeImmutable($s . ' 00:00:00', new DateTimeZone('UTC'));
                // For the upper bound, move to the next day (exclusive)
                return $isEnd ? $dt->modify('+1 day') : $dt;
            }

            // Full datetime provided
            $dt = new DateTimeImmutable($s);
            return $dt->setTimezone(new DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Parse hour value (0-23) or null
     */
    private function parseHourNullable(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }

        if (is_numeric($v)) {
            $i = (int)$v;
            if ($i >= 0 && $i <= 23) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Parse boolean value or null
     */
    private function parseBoolNullable(mixed $v): ?bool
    {
        if ($v === null || $v === '') {
            return null;
        }

        if (is_bool($v)) {
            return $v;
        }

        $s = strtolower((string)$v);

        if (in_array($s, ['1', 'true', 'yes', 'y'], true)) {
            return true;
        }

        if (in_array($s, ['0', 'false', 'no', 'n'], true)) {
            return false;
        }

        return null;
    }

    /**
     * Convert datetime string to ISO8601 format
     */
    private function toIso8601(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $dt = new DateTimeImmutable($value, new DateTimeZone('UTC'));
            return $dt->format(DateTimeInterface::ATOM);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @throws \JsonException
     */
    #[Route(methods: 'GET', path: '/companies/{hash}/messages/message-id/{messageId}')]
    public function getMessageByMessageId(ServerRequestInterface $request): JsonResponse
    {
        $userId  = $this->authenticateUser($request);
        $company = $this->resolveCompany((string)$request->getAttribute('hash'), $userId);

        $raw = (string)$request->getAttribute('messageId', '');
        if ($raw === '') {
            throw new \RuntimeException('Invalid Message-ID', 400);
        }

        $normalized = $this->normalizeMessageIdFromPath($raw);

        error_log(sprintf(
            "Message lookup (company=%d) raw='%s' normalized='%s'",
            $company->getId(), $raw, $normalized
        ));

        /** @var \App\Repository\MessageRepository $msgRepo */
        $msgRepo = $this->repos->getRepository(Message::class);

        // Try normalized first
        $msg = $msgRepo->findOneBy([
            'message_id' => $normalized,
            'company_id' => $company,
        ]);

        // Fallback: some systems store without angle brackets
        if (!$msg && str_starts_with($normalized, '<') && str_ends_with($normalized, '>')) {
            $without = substr($normalized, 1, -1);
            $msg = $msgRepo->findOneBy([
                'message_id' => $without,
                'company_id' => $company,
            ]);
        }

        if (!$msg) {
            throw new \RuntimeException('Message not found', 404);
        }

        return new JsonResponse($this->shapeMessageDetailFromEntity($msg));
    }


    private function normalizeMessageIdFromPath(string $raw): string
    {
        $s = trim($raw);

        // Decode up to 3 times to handle proxies that double-encode
        $prev = null; $i = 0;
        while ($s !== $prev && $i < 3) {
            $prev = $s;
            $s = rawurldecode($s);
            $i++;
        }

        // Handle HTML entities just in case
        $s = str_replace(['&lt;', '&gt;'], ['<', '>'], $s);

        // Remove whitespace
        $s = preg_replace('/\s+/', '', $s ?? '');

        if ($s === '') return $s;

        // Ensure it’s wrapped in angle brackets (DB stores with <>)
        if ($s[0] !== '<')  { $s = '<' . $s; }
        if ($s[strlen($s) - 1] !== '>') { $s .= '>'; }

        return $s;
    }

    /**
     * Build a rich detail payload directly from the Message entity.
     * Keeps field names consistent with your list/shapeMessage().
     */
    private function shapeMessageDetailFromEntity(Message $m): array
    {
        // If these are stored as arrays on the entity, great; if strings (JSON), decode.
        $headers     = $m->getHeaders();
        if (is_string($headers)) {
            $dec = json_decode($headers, true);
            if (json_last_error() === JSON_ERROR_NONE) $headers = $dec;
        }
        $attachments = $m->getAttachments();
        if (is_string($attachments)) {
            $dec = json_decode($attachments, true);
            if (json_last_error() === JSON_ERROR_NONE) $attachments = $dec;
        }

        $attachmentsOut = is_array($attachments) ? array_map(static function ($a) {
            return [
                'filename'    => (string)($a['filename'] ?? ''),
                'contentType' => (string)($a['contentType'] ?? 'application/octet-stream'),
                'length'      => isset($a['content']) && is_string($a['content']) ? strlen($a['content']) : null,
            ];
        }, $attachments) : null;

        $recipients = $this->shapeRecipientsForMessageId((int)$m->getId());

        return [
            'id'         => $m->getId(),
            'company_id' => $m->getCompany()?->getId(),
            'domain'     => [
                'id'   => $m->getDomain()?->getId(),
                'name' => $m->getDomain()?->getDomain(),
            ],
            'envelope'   => [
                'from'    => ['email' => $m->getFrom_email(), 'name' => $m->getFrom_name()],
                'replyTo' => $m->getReply_to(),
                'to'      => $recipients['to']  ?? [],
                'cc'      => $recipients['cc']  ?? [],
                'bcc'     => $recipients['bcc'] ?? [],
            ],
            'subject'    => $m->getSubject(),
            'text'       => $m->getText_body(),
            'html'       => $m->getHtml_body(),
            'headers'    => is_array($headers) ? $headers : null,
            'attachments'=> $attachmentsOut,
            'tracking'   => [
                'opens'  => $m->getOpen_tracking(),
                'clicks' => $m->getClick_tracking(),
            ],
            'state'      => $m->getFinal_state(),
            'messageId'  => $m->getMessage_id(),
            'timestamps' => [
                'createdAt' => $m->getCreated_at()?->format(\DateTimeInterface::ATOM),
                'queuedAt'  => $m->getQueued_at()?->format(\DateTimeInterface::ATOM),
                'sentAt'    => $m->getSent_at()?->format(\DateTimeInterface::ATOM),
            ],
        ];
    }
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

    private function windowStart(\DateTimeImmutable $now, string $window): \DateTimeImmutable
    {
        return $window === 'day'
            ? $now->setTime(0, 0, 0)
            : $now->setDate((int)$now->format('Y'), (int)$now->format('m'), 1)->setTime(0, 0, 0);
    }

    private function windowResetAt(\DateTimeImmutable $start, string $window): \DateTimeImmutable
    {
        return $window === 'day' ? $start->modify('+1 day') : $start->modify('+1 month');
    }

    /**
     * Consume N message units from the current window or throw 429 if over limit.
     * Returns {window,limit,remaining,resetAt}.
     */
    private function enforceAndConsumeMessageQuota(Company $company, \DateTimeImmutable $now, int $units = 1): array
    {
        $policy = $this->planPolicy($company);
        $limit  = $policy['limit'];
        $window = $policy['window'];

        if ($limit === null) {
            return ['window'=>$window,'limit'=>null,'remaining'=>null,'resetAt'=>null];
        }
        $start   = $this->windowStart($now, $window);
        $key     = sprintf('messages:%s:%s', $window, $window === 'day' ? $now->format('Y-m-d') : $now->format('Y-m'));
        $resetAt = $this->windowResetAt($start, $window);

        /** @var \App\Repository\RateLimitCounterRepository $repo */
        $repo    = $this->repos->getRepository(RateLimitCounter::class);

        // use the FK and not the relation object
        $counter = $repo->findOneBy([
            'company_id' => $company->getId(),
            'key'        => $key,
        ]);
        error_log('here4');
        if (!$counter) {
            $counter = new RateLimitCounter()
                ->setCompany($company)
                ->setKey($key)
                ->setWindow_start($start)
                ->setCount(0);
        } else {
            // rotate window if stale
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
                'resetAt'   => $resetAt->format(\DateTimeInterface::ATOM),
            ], JSON_UNESCAPED_SLASHES), 429);
        }

        $counter->setCount($current + $units)->setUpdated_at($now);
        $repo->save($counter);

        return [
            'window'    => $window,
            'limit'     => $limit,
            'remaining' => $limit - ($current + $units),
            'resetAt'   => $resetAt->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param int[] $messageIds
     * @return array<int,array{to:string[],cc:string[],bcc:string[]}>
     */
    private function loadRecipientsForMessageIds(array $messageIds): array
    {
        if (empty($messageIds)) return [];
        error_log('01 recipents');
        $qb = $this->qb->duplicate()
            ->select(['mr.message_id','mr.type','mr.email'])
            ->from('messagerecipients', 'mr')
            ->whereIn('mr.message_id', $messageIds);

        $rows = $qb->fetchAll();
        error_log('02 recipents');
        $out = [];
        foreach ($rows as $r) {
            $mid  = (int)($r['message_id'] ?? 0);
            $type = strtolower((string)($r['type'] ?? ''));
            $email= trim((string)($r['email'] ?? ''));
            if ($mid <= 0 || $email === '') continue;
            if (!isset($out[$mid])) $out[$mid] = ['to'=>[], 'cc'=>[], 'bcc'=>[]];
            if (!in_array($type, ['to','cc','bcc'], true)) $type = 'to';
            $out[$mid][$type][] = $email;
        }
        // de-dup per bucket
        foreach ($out as $mid => $buckets) {
            foreach ($buckets as $k => $arr) {
                $out[$mid][$k] = array_values(array_unique($arr));
            }
        }
        return $out;
    }

    private function shapeRecipientsForMessageId(int $messageId): array
    {
        $map = $this->loadRecipientsForMessageIds([$messageId]);
        return $map[$messageId] ?? ['to'=>[], 'cc'=>[], 'bcc'=>[]];
    }

    private function mkStream(): Stream
    {
        $h = fopen('php://temp', 'r+');        // in-memory temp stream
        return new Stream($h);
    }

    #[Route(methods: 'GET', path: '/t/o/{rid}.gif')]
    public function trackOpen(ServerRequestInterface $request): ResponseInterface
    {
        $rid = (string)($request->getAttribute('rid') ?? '');
        $this->trackEventSafe($rid, 'opened', $request);

        // 1x1 transparent GIF
        $gif = base64_decode('R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==');

        $body = $this->mkStream();
        $resp = new Response(
            $body,
            200,
            [
                'Content-Type'  => 'image/gif',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            ]
        );
        $resp->getBody()->write($gif);
        return $resp;
    }

    #[Route(methods: 'GET', path: '/t/c/{rid}')]
    public function trackClick(ServerRequestInterface $request): ResponseInterface
    {
        $rid  = (string)($request->getAttribute('rid') ?? '');
        $uEnc = (string)($request->getQueryParams()['u'] ?? '');
        $url  = $this->b64urlDecode($uEnc) ?: '/';

        $this->trackEventSafe($rid, 'clicked', $request, ['url' => $url]);

        $resp = new Response($this->mkStream(), 302, ['Location' => $url]);
        return $resp;
    }

    #[Route(methods: 'GET', path: '/t/u/{rid}')]
    public function unsubscribe(ServerRequestInterface $request): ResponseInterface
    {
        $rid    = (string)($request->getAttribute('rid') ?? '');
        $reason = (string)($request->getQueryParams()['reason'] ?? 'unsubscribed');

        $this->trackEventSafe($rid, $reason, $request);

        $body = $this->mkStream();
        $resp = new Response(
            $body,
            200,
            [
                'Content-Type'  => 'text/html; charset=utf-8',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            ]
        );
        $resp->getBody()->write('<!doctype html><html><body><p>You are unsubscribed.</p></body></html>');
        return $resp;
    }

    /** base64url decoder used by /t/c */
    private function b64urlDecode(string $s): ?string
    {
        if ($s === '') return null;
        $s = strtr($s, '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad) $s .= str_repeat('=', 4 - $pad);
        $out = base64_decode($s, true);
        return $out === false ? null : $out;
    }


    private function trackEventSafe(string $rid, string $type, ServerRequestInterface $r, array $extra = []): void
    {
        try {
            if ($rid === '') return;

            // idempotency key per recipient+type(+url host)
            $ua = $r->getHeaderLine('User-Agent');
            $ip = $r->getServerParams()['REMOTE_ADDR'] ?? '';
            $meta = array_merge($extra, [
                'ua' => $ua,
                'ip' => $ip,
            ]);

            // Resolve MessageRecipient by rid (see section 2 — token scheme)
            [$messageId, $recipientId] = $this->resolveByRid($rid);
            if ($messageId <= 0 || $recipientId <= 0) return;

            // Dedupe via Redis (5 minutes for clicks, 24h for opens)
            $ttl = $type === 'opened' ? 86400 : 300;
            $idKey = 'ml:trk:' . $type . ':' . $rid . (isset($extra['url']) ? ':' . md5($extra['url']) : '');
            $didWrite = $this->trackingDedup($idKey, $ttl);
            if (!$didWrite) return;

            // Persist durable event
            $this->appendEvent($messageId, $recipientId, $type, $meta);
        } catch (\Throwable $e) {
            error_log("trackEventSafe error: " . $e->getMessage());
        }
    }

    private function trackingDedup(string $key, int $ttl): bool
    {
        try {
            $r = $this->getRedis();
            if (!$r) return true; // no redis → don't block event logging

            // SET key 1 NX EX <ttl>
            $ok = $r->set($key, '1', ['nx', 'ex' => $ttl]);
            return (bool)$ok;
        } catch (\Throwable) {
            return true; // if Redis errors, allow write once
        }
    }


    /**
     * @return array{0:int,1:int} [messageId, recipientId] or [0,0] if not found
     */
    private function resolveByRid(string $rid): array
    {
        if ($rid === '') return [0, 0];

        /** @var \App\Entity\MessageRecipient|null $rec */
        $rec = $this->messageRecipientRepo->findOneBy(['track_token' => $rid]);
        if (!$rec) return [0, 0];

        // Derive message id robustly
        $mid = 0;
        if (method_exists($rec, 'getMessage') && $rec->getMessage()) {
            $mid = (int)$rec->getMessage()->getId();
        } elseif (method_exists($rec, 'getMessage_id')) {
            $mid = (int)$rec->getMessage_id();
        }

        $ridInt = method_exists($rec, 'getId') ? (int)$rec->getId() : 0;
        return [$mid, $ridInt];
    }


    private function appendEvent(int $messageId, int $recipientId, string $type, array $meta): void
    {
        if ($messageId <= 0 || $recipientId <= 0) {
            // avoid "Expected parameter of type 'int', 'null' provided"
            return;
        }

        $ev = new MessageEvent();

        // Set message / recipient (handle both relation and raw FK styles)
        if (method_exists($ev, 'setMessageId')) {
            $ev->setMessageId($messageId);
        } elseif (method_exists($ev, 'setMessage')) {
            /** @var \App\Entity\Message|null $msg */
            $msg = $this->messageRepo->find($messageId);
            if ($msg) $ev->setMessage($msg);
        }

        if (method_exists($ev, 'setRecipientId')) {
            $ev->setRecipientId($recipientId);
        } elseif (method_exists($ev, 'setRecipient')) {
            /** @var \App\Entity\MessageRecipient|null $mr */
            $mr = $this->messageRecipientRepo->find($recipientId);
            if ($mr) $ev->setRecipient($mr);
        }

        // Set type
        if (method_exists($ev, 'setType')) {
            $ev->setType($type);
        } elseif (method_exists($ev, 'setEvent_type')) {
            $ev->setEvent_type($type);
        }

        // Set metadata (array or JSON)
        if (method_exists($ev, 'setMeta')) {
            $ev->setMeta($meta);
        } elseif (method_exists($ev, 'setMeta_json')) {
            $ev->setMeta_json(json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        // Timestamp
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if (method_exists($ev, 'setCreatedAt'))       $ev->setCreatedAt($now);
        elseif (method_exists($ev, 'setCreated_at'))  $ev->setCreated_at($now);

        // Persist
        $this->messageEventRepo->save($ev);

        // OPTIONAL: publish to Redis pub/sub for live dashboards
        try {
            if ($r = $this->getRedis()) {
                $r->publish('ml:events', json_encode([
                    'type'       => $type,
                    'message_id' => $messageId,
                    'recipient'  => $recipientId,
                    'meta'       => $meta,
                    'ts'         => time(),
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
        } catch (\Throwable) {}
    }


    private function getRedis(): ?\Redis
    {
        if ($this->redis instanceof \Redis) {
            return $this->redis;
        }

        if ($this->redisDsn === '') {
            return null;
        }

        $parts = parse_url($this->redisDsn);
        if (!$parts || !isset($parts['host'])) {
            return null;
        }

        $host = $parts['host'];
        $port = isset($parts['port']) ? (int)$parts['port'] : 6379;
        $pass = $parts['pass'] ?? null;
        $db   = 0;

        if (isset($parts['path'])) {
            $dbStr = ltrim($parts['path'], '/');
            if ($dbStr !== '' && ctype_digit($dbStr)) {
                $db = (int)$dbStr;
            }
        }

        try {
            $r = new \Redis();
            $r->connect($host, $port, 2.0);
            if ($pass) {
                $r->auth($pass);
            }
            if ($db) {
                $r->select($db);
            }

            $this->redis = $r;
            return $this->redis;
        } catch (\Throwable) {
            return null;
        }
    }

    /** base64url */
    private function b64url(string $s): string {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    /** create recipients + track tokens and return [ 'to'=>[], 'cc'=>[], 'bcc'=>[] ] of plain emails */
    private function persistRecipients(Message $msg, array $to, array $cc, array $bcc): array
    {
        /** @var EntityRepository $recRepo */
        $recRepo = $this->messageRecipientRepo;

        $buckets = ['to' => $to, 'cc' => $cc, 'bcc' => $bcc];

        foreach ($buckets as $type => $list) {
            foreach ($list as $email) {
                $rec = new MessageRecipient();
                $rec->setMessage($msg);
                $rec->setType($type);
                $rec->setEmail($email);

                // rid/track token: random, URL-safe
                $rid = bin2hex(random_bytes(16));            // 32 hex chars
                if (method_exists($rec, 'setTrack_token')) { $rec->setTrack_token($rid); }
                elseif (method_exists($rec, 'setTrackToken')) { $rec->setTrackToken($rid); }

                $recRepo->save($rec);
            }
        }

        return ['to' => $to, 'cc' => $cc, 'bcc' => $bcc];
    }

    /** Build tracking pixel tag */
    private function trackingPixelTag(string $rid): string
    {
        // Use your public base URL (no auth, GET)
        $base = 'https://smtp.monkeysmail.com';
        return '<img src="'.$base.'/t/o/'.$rid.'.gif" width="1" height="1" style="display:none" alt=""/>';
    }

    /** Rewrite links inside HTML for click tracking */
    private function rewriteLinksWithRid(string $html, string $rid): string
    {
        $base = 'https://smtp.monkeysmail.com';
        return preg_replace_callback(
            '#(<a\b[^>]*\bhref=["\'])(https?://[^"\']+)(["\'][^>]*>)#i',
            function($m) use ($base, $rid) {
                $orig = $m[2];
                $redir = $base.'/t/c/'.$rid.'?u='.$this->b64url($orig);
                return $m[1].$redir.$m[3];
            },
            $html
        );
    }

    /** Inject pixel + link-rewrite per-recipient; returns map rid => renderedHtml */
    private function renderPerRecipientHtml(Message $msg, array $recipientsByType): array
    {
        $html = (string)($msg->getHtml_body() ?? '');
        $withClicks = (bool)$msg->getClick_tracking();
        $withOpens  = (bool)$msg->getOpen_tracking();

        // Load all MessageRecipient rows we just created for this message
        $rows = $this->qb->duplicate()
            ->select(['id','email','type','track_token'])
            ->from('messagerecipients')
            ->where('message_id', '=', (int)$msg->getId())
            ->fetchAll();

        $out = []; // rid => html
        foreach ($rows as $r) {
            $rid  = (string)$r['track_token'];
            $h = $html;
            if ($withClicks && $h !== '') {
                $h = $this->rewriteLinksWithRid($h, $rid);
            }
            if ($withOpens && $h !== '') {
                $h .= $this->trackingPixelTag($rid);
            }
            $out[$rid] = $h;
        }
        return $out;
    }


}
