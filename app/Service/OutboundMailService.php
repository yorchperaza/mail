<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Company;
use App\Entity\Domain;
use App\Entity\Message;
use App\Entity\MessageEvent;
use App\Entity\MessageRecipient;
use App\Entity\RateLimitCounter;
use App\Entity\UsageAggregate;
use App\Service\Ports\MailQueue;
use App\Service\Ports\MailSender;
use MonkeysLegion\Repository\RepositoryFactory;
use PHPMailer\PHPMailer\PHPMailer;
use Random\RandomException;
use MonkeysLegion\Query\QueryBuilder;
use Predis\Client as Predis;

final class OutboundMailService
{
    /** Use the same naming style as the daily rows. Example: messages:month:2025-09-01 */
    private const RL_KEY_MONTH_PREFIX = 'messages:month:';

    public function __construct(
        private RepositoryFactory $repos,
        private QueryBuilder      $qb,
        private MailQueue         $queue,
        private MailSender        $sender,
        private \Redis|Predis|null $redis = null,
        private int               $statusTtlSec = 3600,
    ) {
        $this->redis = $this->redis ?: self::makeRedisFromEnv();
    }

    /** Persist Message as queued/preview and push to Redis (always queues when not dryRun). */
    public function createAndEnqueue(array $payload, Company $company, Domain $domain): array
    {
        // Add request tracking to detect duplicate calls
        $requestId = $payload['request_id'] ?? uniqid('req_', true);
        static $processedRequests = [];

        if (isset($processedRequests[$requestId])) {
            error_log(sprintf('[Mail][DUPLICATE] Request already processed: %s', $requestId));
            return $processedRequests[$requestId];
        }

        $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        error_log(sprintf('[Mail] createAndEnqueue start requestId=%s company=%d domain=%d dryRun=%s now=%s',
            $requestId, $company->getId(), $domain->getId(), json_encode((bool)($payload['dryRun'] ?? false)), $nowUtc->format(DATE_ATOM)));

        // -------- validate / normalize ----------
        $fromEmail = trim((string)($payload['from']['email'] ?? ''));
        if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            error_log('[Mail] invalid from.email');
            throw new \RuntimeException('A valid from.email is required', 422);
        }
        $fromName  = isset($payload['from']['name']) ? trim((string)$payload['from']['name']) : null;
        $replyTo   = isset($payload['replyTo']) ? trim((string)$payload['replyTo']) : null;
        if ($replyTo && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            error_log('[Mail] invalid replyTo');
            throw new \RuntimeException('replyTo must be a valid email', 422);
        }

        $subject = isset($payload['subject']) ? trim((string)$payload['subject']) : null;
        $text    = isset($payload['text']) ? (string)$payload['text'] : null;
        $html    = isset($payload['html']) ? (string)$payload['html'] : null;

        $to  = $this->normalizeEmails($payload['to']  ?? []);
        $cc  = $this->normalizeEmails($payload['cc']  ?? []);
        $bcc = $this->normalizeEmails($payload['bcc'] ?? []);
        if (empty($to) && empty($cc) && empty($bcc)) {
            error_log('[Mail] no recipients provided');
            throw new \RuntimeException('At least one recipient (to/cc/bcc) is required', 422);
        }
        $rcptCount = count($to) + count($cc) + count($bcc);
        error_log(sprintf('[Mail] normalized recipients to=%d cc=%d bcc=%d total=%d',
            count($to), count($cc), count($bcc), $rcptCount));

        $headers     = isset($payload['headers']) && is_array($payload['headers']) ? $this->sanitizeHeaders($payload['headers']) : null;
        $tracking = is_array($payload['tracking'] ?? null) ? $payload['tracking'] : [];
        $opensEnabled  = array_key_exists('opens',  $tracking) ? (bool)$tracking['opens']  : true;
        $clicksEnabled = array_key_exists('clicks', $tracking) ? (bool)$tracking['clicks'] : true;
        $attachments = $this->normalizeAttachments($payload['attachments'] ?? []);
        $dryRun = (bool)($payload['dryRun'] ?? false);

        // ---------- ensure the monthly row ALWAYS exists for visibility ----------
        if (!$dryRun) {
            error_log('[RateLimit][CALL] ensureMonthlyCounterRow -> start');
            $this->ensureMonthlyCounterRow($company, $nowUtc);
            error_log('[RateLimit][CALL] ensureMonthlyCounterRow -> done');
        }

        // ---------- enforce quotas (daily + monthly) ----------
        if (!$dryRun) {
            try {
                $this->enforceQuotas($company, $nowUtc, $rcptCount);
            } catch (\RuntimeException $e) {
                error_log('[Mail][ERROR] enforceQuotas: '.$e->getMessage());
                if ($e->getCode() === 429 || str_contains(strtolower($e->getMessage()), 'limit')) {
                    throw $e;
                }
                throw new \RuntimeException('Quota check failed: '.$e->getMessage(), 429);
            }
        }

        // ---------- persist Message ----------
        /** @var \App\Repository\MessageRepository $messageRepo */
        $messageRepo = $this->repos->getRepository(Message::class);
        $hash = bin2hex(random_bytes(64));
        $msg = (new Message())
            ->setCompany($company)
            ->setDomain($domain)
            ->setFrom_email($fromEmail)
            ->setFrom_name($fromName)
            ->setReply_to($replyTo)
            ->setSubject($subject)
            ->setHtml_body($html)
            ->setText_body($text)
            ->setHeaders($headers)
            ->setOpen_tracking($opensEnabled)
            ->setClick_tracking($clicksEnabled)
            ->setAttachments(!empty($attachments) ? $attachments : null)
            ->setCreated_at($nowUtc)
            ->setQueued_at($nowUtc)
            ->setMessage_id($hash)
            ->setFinal_state($dryRun ? 'preview' : 'queued');

        $messageRepo->save($msg);
        error_log(sprintf('[Mail] message saved id=%d state=%s rcpts=%d', $msg->getId(), $msg->getFinal_state(), $rcptCount));

        $this->persistRecipients($msg, $to, $cc, $bcc, $dryRun ? 'preview' : 'queued');
        error_log(sprintf('[Mail] recipients persisted message_id=%d', $msg->getId()));
        $this->addMessageEvent($msg, $dryRun ? 'preview' : 'queued');

        if ($dryRun) {
            error_log('[Mail] dryRun=true returning preview');
            $response = [
                'status'  => 'preview',
                'message' => $this->shapeMessage($msg),
                'envelope'=> [
                    'fromEmail' => $msg->getFrom_email(),
                    'fromName'  => $msg->getFrom_name(),
                    'replyTo'   => $msg->getReply_to(),
                    'to'        => $to, 'cc' => $cc, 'bcc' => $bcc,
                    'headers'   => $headers
                ],
            ];

            if (isset($requestId)) {
                $processedRequests[$requestId] = $response;
            }

            return $response;
        }

        // ---------- enqueue individual jobs per recipient for tracking ----------
        // Collect all recipients with their types
        $allRecipients = [];
        foreach ($to as $email) {
            $allRecipients[] = ['email' => $email, 'type' => 'to'];
        }
        foreach ($cc as $email) {
            $allRecipients[] = ['email' => $email, 'type' => 'cc'];
        }
        foreach ($bcc as $email) {
            $allRecipients[] = ['email' => $email, 'type' => 'bcc'];
        }

        // Create and enqueue separate job for each recipient
        $queuedCount = 0;
        $failedCount = 0;
        $entryIds = [];

        foreach ($allRecipients as $recipient) {
            $recipientJob = [
                'message_id' => $msg->getId(),
                'company_id' => $company->getId(),
                'domain_id'  => $domain->getId(),
                'envelope'   => [
                    // Only ONE recipient per job to enable tracking injection
                    'to'          => $recipient['type'] === 'to' ? [$recipient['email']] : [],
                    'cc'          => $recipient['type'] === 'cc' ? [$recipient['email']] : [],
                    'bcc'         => $recipient['type'] === 'bcc' ? [$recipient['email']] : [],
                    'headers'     => $headers ?? [],
                    'fromEmail'   => $msg->getFrom_email(),
                    'fromName'    => $msg->getFrom_name(),
                    'replyTo'     => $msg->getReply_to(),
                ],
                'created_at' => $nowUtc->format(DATE_ATOM),
            ];

            $entryId = $this->queue->enqueue($recipientJob);

            if ($entryId) {
                $queuedCount++;
                $entryIds[] = $entryId;
                error_log(sprintf('[Mail] Enqueued job for %s: %s (entryId=%s)',
                    $recipient['type'], $recipient['email'], $entryId));
            } else {
                $failedCount++;
                error_log(sprintf('[Mail] Failed to enqueue for %s: %s',
                    $recipient['type'], $recipient['email']));
            }
        }

        // Check if any jobs were queued
        if ($queuedCount === 0) {
            $msg->setFinal_state('queue_failed');
            $messageRepo->save($msg);
            $this->addMessageEvent($msg, 'queue_failed');
            $response = ['status' => 'queue_failed', 'message' => $this->shapeMessage($msg)];

            if (isset($requestId)) {
                $processedRequests[$requestId] = $response;
            }

            return $response;
        }

        // Log queue status
        error_log(sprintf('[Mail] Queue results: %d queued, %d failed out of %d total recipients',
            $queuedCount, $failedCount, $rcptCount));

        // ✅ Increment monthly at enqueue time (per *queued* recipients)
        error_log(sprintf('[RateLimit][inc][enqueue] will inc monthly by rcpts=%d', $queuedCount));
        $this->incMonthlyCount($company, $nowUtc, $queuedCount);

        // ✅ FIX: Also increment daily usage at enqueue time (per *queued* recipients)
        // Using 'sent' field for now since 'queued' might not exist in your DB
        error_log(sprintf('[Usage][inc][enqueue] will inc daily sent by rcpts=%d', $queuedCount));
        try {
            $this->upsertUsage($company, $nowUtc, ['sent' => $queuedCount]);
            error_log('[Usage][inc][enqueue] daily usage updated successfully');
        } catch (\Throwable $e) {
            error_log(sprintf('[Usage][inc][enqueue][ERROR] Failed to update daily usage: %s at %s:%d',
                $e->getMessage(), $e->getFile(), $e->getLine()));
            // Don't fail the entire request if usage tracking fails
            // The message was already saved and queued
        }

        // Cache the response for duplicate detection
        $response = [
            'status'  => 'queued',
            'queue'   => $this->queue->getStream(),
            'entryIds' => $entryIds,  // Return all entry IDs
            'queued'  => $queuedCount,
            'failed'  => $failedCount,
            'message' => $this->shapeMessage($msg),
        ];

        if (isset($requestId)) {
            $processedRequests[$requestId] = $response;
        }

        return $response;
    }

    /** Worker path: load Message, send via SMTP, update state; update recipients + events. */
    private function getDirectDbConnection(): \PDO
    {
        static $pdo = null;
        static $lastPing = 0;

        $now = time();

        // Check connection every 30 seconds
        if ($pdo !== null && ($now - $lastPing) > 30) {
            try {
                $pdo->query('SELECT 1');
                $lastPing = $now;
            } catch (\PDOException $e) {
                error_log('[Mail] DB connection lost, reconnecting: ' . $e->getMessage());
                $pdo = null;
            }
        }

        if ($pdo === null) {
            $host = '34.9.43.102';
            $db = 'ml_mail';
            $user = 'mailmonkeys';
            $pass = 't3mp0r4lAllyson#22';

            $dsn = "mysql:host=$host;dbname=$db;charset=utf8mb4";
            $pdo = new \PDO($dsn, $user, $pass, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_PERSISTENT => false,  // Don't use persistent connections
            ]);

            // Set timeout to prevent "server has gone away"
            $pdo->exec("SET SESSION wait_timeout=28800");  // 8 hours
            $pdo->exec("SET SESSION interactive_timeout=28800");

            $lastPing = $now;
            error_log('[Mail] DB connection established');
        }

        return $pdo;
    }

    public function processJob(array $job): void
    {
        error_log('[Mail] processJob start payload='.json_encode($job));
        $id = (int)($job['message_id'] ?? 0);
        if ($id <= 0) {
            error_log('[Mail] processJob missing message_id');
            return;
        }

        try {
            // Get fresh connection
            $pdo = $this->getDirectDbConnection();

            // Get message
            $stmt = $pdo->prepare('SELECT * FROM message WHERE id = ?');
            $stmt->execute([$id]);
            $msgData = $stmt->fetch();

            if (!$msgData) {
                error_log('[Mail] processJob message not found id='.$id);
                return;
            }

            // Get envelope from job
            $envelope = (array)($job['envelope'] ?? []);

            // Get tracking settings
            $opensEnabled = (bool)($msgData['open_tracking'] ?? false);
            $clicksEnabled = (bool)($msgData['click_tracking'] ?? false);

            // Get ALL recipients with their tracking tokens
            $recipientStmt = $pdo->prepare('
            SELECT email, type, track_token 
            FROM messagerecipient 
            WHERE message_id = ?
        ');
            $recipientStmt->execute([$id]);
            $recipients = $recipientStmt->fetchAll();

            // Build recipient map with tokens
            $recipientTokens = [];
            foreach ($recipients as $recipient) {
                $email = $recipient['email'];
                $token = $recipient['track_token'];
                if ($email && $token) {
                    $recipientTokens[$email] = $token;
                }
            }

            error_log('[Mail] Found ' . count($recipientTokens) . ' recipients with tokens');

            // For single recipient messages, inject tracking into HTML
            $htmlBody = $msgData['html_body'];
            if (count($envelope['to']) === 1 && count($envelope['cc']) === 0 && count($envelope['bcc']) === 0) {
                // Single recipient - we can inject tracking
                $recipientEmail = $envelope['to'][0];
                $trackToken = $recipientTokens[$recipientEmail] ?? null;

                if ($trackToken && $htmlBody) {
                    $base = getenv('TRACK_BASE_URL') ?: 'https://smtp.monkeysmail.com';

                    // Add click tracking
                    if ($clicksEnabled) {
                        $htmlBody = preg_replace_callback(
                            '#(<a\b[^>]*\bhref=["\'])(https?://[^"\']+)(["\'][^>]*>)#i',
                            function($m) use ($base, $trackToken) {
                                $url = $m[2];
                                $encoded = rtrim(strtr(base64_encode($url), '+/', '-_'), '=');
                                return $m[1] . $base . '/t/c/' . $trackToken . '?u=' . $encoded . $m[3];
                            },
                            $htmlBody
                        );
                    }

                    // Add open tracking pixel
                    if ($opensEnabled) {
                        $pixelUrl = $base . '/t/o/' . $trackToken; // <- extensionless
                        $pixel = '<img src="' . $pixelUrl . '" width="1" height="1" alt="" ' .
                            'style="display:block;border:0;outline:none;text-decoration:none;width:1px;height:1px;max-width:1px;opacity:0;" />';

                        if (is_string($htmlBody) && $htmlBody !== '') {
                            // Try to place before </body>; fallback to append
                            $count = 0;
                            $htmlBody = preg_replace('/<\/body\s*>/i', $pixel . '</body>', $htmlBody, 1, $count);
                            if ($count === 0) {
                                $htmlBody .= $pixel;
                            }
                        }
                    }

                    error_log('[Mail] Tracking injected for ' . $recipientEmail . ' with token ' . $trackToken);
                }
            } else if (count($recipientTokens) > 1) {
                // Multiple recipients - we should queue separate jobs for each
                // For now, log a warning
                error_log('[Mail] WARNING: Multiple recipients in single job - tracking will only work for individual sends');
            }

            // Build email data with tracking-enabled HTML
            $emailData = [
                'from_email' => $envelope['fromEmail'] ?? $msgData['from_email'],
                'from_name' => $envelope['fromName'] ?? $msgData['from_name'],
                'reply_to' => $envelope['replyTo'] ?? $msgData['reply_to'],
                'subject' => $msgData['subject'],
                'html_body' => $htmlBody,  // Use tracking-enabled HTML
                'text_body' => $msgData['text_body'],
                'to' => $envelope['to'] ?? [],
                'cc' => $envelope['cc'] ?? [],
                'bcc' => $envelope['bcc'] ?? [],
            ];

            // Handle attachments if present
            if (!empty($msgData['attachments'])) {
                $attachments = is_string($msgData['attachments'])
                    ? json_decode($msgData['attachments'], true)
                    : $msgData['attachments'];
                $emailData['attachments'] = $attachments;
            }

            error_log('[Mail] Sending email from=' . $emailData['from_email'] . ' to=' . json_encode($emailData['to']));

            // Send the email
            if (method_exists($this->sender, 'sendRaw')) {
                $res = $this->sender->sendRaw($emailData);
            } else {
                $msg = new \App\Entity\Message();
                $msg->setId($id);
                $msg->setFrom_email($emailData['from_email']);
                $msg->setFrom_name($emailData['from_name']);
                $msg->setReply_to($emailData['reply_to']);
                $msg->setSubject($emailData['subject']);
                $msg->setHtml_body($emailData['html_body']);
                $msg->setText_body($emailData['text_body']);

                $res = $this->sender->send($msg, $envelope);
            }

            error_log('[Mail] Send result: ' . json_encode($res));

            // Update message status
            $now = date('Y-m-d H:i:s');
            $status = ($res['ok'] ?? false) ? 'sent' : 'failed';
            $messageId = $res['message_id'] ?? null;

            if ($messageId) {
                $updateStmt = $pdo->prepare('UPDATE message SET final_state = ?, sent_at = ?, message_id = ? WHERE id = ?');
                $updateStmt->execute([$status, $now, $messageId, $id]);
            } else {
                $updateStmt = $pdo->prepare('UPDATE message SET final_state = ?, sent_at = ? WHERE id = ?');
                $updateStmt->execute([$status, $now, $id]);
            }

            // Update recipients status
            $recipientStmt = $pdo->prepare('UPDATE messagerecipient SET status = ? WHERE message_id = ?');
            $recipientStmt->execute([$status, $id]);

            error_log(sprintf('[Mail] processJob completed status=%s id=%d', $status, $id));

        } catch (\PDOException $e) {
            error_log('[Mail] processJob DB error: ' . $e->getMessage());
            throw $e;  // Re-throw to trigger retry
        } catch (\Throwable $e) {
            error_log('[Mail] processJob unexpected error: ' . $e->getMessage());
            throw $e;
        }
    }
    /* ----------------------- helpers ----------------------- */

    private function normalizeEmails(array $list): array
    {
        $out = [];
        foreach ($list as $v) {
            $e = is_array($v) ? ($v['email'] ?? '') : $v;
            $e = trim((string)$e);
            if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) $out[] = strtolower($e);
        }
        return array_values(array_unique($out));
    }

    private function sanitizeHeaders(array $headers): array
    {
        $clean = [];
        foreach ($headers as $k => $v) {
            $k = trim((string)$k);
            $v = is_array($v) ? '' : trim((string)$v);
            if ($k !== '' && $v !== '') $clean[$k] = $v;
        }
        return $clean;
    }

    private function normalizeAttachments(array $atts): array
    {
        $out = [];
        foreach ($atts as $a) {
            if (!is_array($a)) continue;
            $fn  = trim((string)($a['filename'] ?? ''));
            $ct  = trim((string)($a['contentType'] ?? 'application/octet-stream'));
            $b64 = (string)($a['content'] ?? '');
            if ($fn !== '' && $b64 !== '') $out[] = ['filename'=>$fn,'contentType'=>$ct,'content'=>$b64];
        }
        return $out;
    }

    private function shapeMessage(Message $m): array
    {
        return [
            'id'        => $m->getId(),
            'subject'   => $m->getSubject(),
            'state'     => $m->getFinal_state(),
            'createdAt' => $m->getCreated_at()?->format(DATE_ATOM),
            'queuedAt'  => $m->getQueued_at()?->format(DATE_ATOM),
            'sentAt'    => $m->getSent_at()?->format(DATE_ATOM),
            'messageId' => $m->getMessage_id(),
        ];
    }

    private function persistRecipients(Message $msg, array $to, array $cc, array $bcc, string $status): void
    {
        /** @var \App\Repository\MessageRecipientRepository $rRepo */
        $rRepo = $this->repos->getRepository(MessageRecipient::class);

        $save = function(string $email, string $type) use ($msg, $status, $rRepo) {
            $trackToken = bin2hex(random_bytes(16));

            // Add verification log
            if (empty($trackToken)) {
                error_log('[CRITICAL] Failed to generate tracking token!');
                return;
            }

            $r = (new MessageRecipient())
                ->setMessage($msg)
                ->setType($type)
                ->setEmail($email)
                ->setStatus($status)
                ->setTrack_token($trackToken);

            $rRepo->save($r);

            // Verify it was saved
            error_log(sprintf('[Mail] Recipient saved: id=%d, email=%s, type=%s, token=%s',
                $r->getId(), $email, $type, $trackToken));
        };

        foreach ($to as $e)  { $save($e, 'to'); }
        foreach ($cc as $e)  { $save($e, 'cc'); }
        foreach ($bcc as $e) { $save($e, 'bcc'); }
    }

    private function updateRecipientsStatus(Message $msg, string $status): void
    {
        /** @var \App\Repository\MessageRecipientRepository $rRepo */
        $rRepo = $this->repos->getRepository(MessageRecipient::class);

        $rows = $rRepo->findBy(['message_id' => $msg->getId()]) ?: $rRepo->findBy(['message' => $msg]);
        foreach ($rows as $r) {
            $r->setStatus($status);
            $rRepo->save($r);
        }
        error_log(sprintf('[Mail] recipients updated status=%s rows=%d', $status, is_array($rows) ? count($rows) : 0));
    }

    private function addMessageEvent(Message $msg, string $event, ?string $rcpt = null, array $payload = []): void
    {
        /** @var \App\Repository\MessageEventRepository $eRepo */
        $eRepo = $this->repos->getRepository(MessageEvent::class);
        $eRepo->save(
            new MessageEvent()
                ->setMessage($msg)
                ->setEvent($event)
                ->setRecipient_email($rcpt)
                ->setOccurred_at(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->setProvider_hint('local')
                ->setPayload($payload ?: null)
        );
        error_log(sprintf('[Mail] event added event=%s message_id=%d', $event, $msg->getId()));
    }

    /** Upsert daily usage aggregate for the company (sent, delivered, opens, etc). */
    private function upsertUsage(?Company $company, \DateTimeImmutable $when, array $deltas): void
    {
        if (!$company) {
            error_log('[Usage] upsertUsage called with null company');
            return;
        }

        try {
            /** @var \App\Repository\UsageAggregateRepository $repo */
            $repo = $this->repos->getRepository(UsageAggregate::class);
            error_log(sprintf('[Usage] Got repository: %s', get_class($repo)));

            $dayStart = $when->setTime(0, 0, 0);
            $dateStr = $dayStart->format('Y-m-d H:i:s');
            error_log(sprintf('[Usage] Looking for usage row: company_id=%d date=%s',
                $company->getId(), $dateStr));

            // Always use string format for date queries
            $row = $repo->findOneBy(['company_id' => $company->getId(), 'date' => $dateStr]);

            $created = false;
            if (!$row) {
                error_log('[Usage] Creating new usage row');
                $row = new UsageAggregate()
                    ->setCompany($company)
                    ->setDate($dayStart)
                    ->setCreated_at($when)
                    ->setSent(0)->setDelivered(0)->setBounced(0)->setComplained(0)->setOpens(0)->setClicks(0);
                $created = true;
            } else {
                error_log(sprintf('[Usage] Found existing row id=%s',
                    method_exists($row, 'getId') ? $row->getId() : 'N/A'));
            }

            $before = ['sent'=>$row->getSent(),'delivered'=>$row->getDelivered(),'bounced'=>$row->getBounced(),'complained'=>$row->getComplained(),'opens'=>$row->getOpens(),'clicks'=>$row->getClicks()];
            $map = ['sent'=>['getSent','setSent'], 'delivered'=>['getDelivered','setDelivered'], 'bounced'=>['getBounced','setBounced'], 'complained'=>['getComplained','setComplained'], 'opens'=>['getOpens','setOpens'], 'clicks'=>['getClicks','setClicks']];

            foreach ($deltas as $field => $inc) {
                if (!isset($map[$field])) {
                    error_log(sprintf('[Usage] Skipping unknown field: %s', $field));
                    continue;
                }
                [$g,$s] = $map[$field];
                $oldVal = (int)($row->{$g}() ?? 0);
                $newVal = max(0, $oldVal + (int)$inc);
                $row->{$s}($newVal);
                error_log(sprintf('[Usage] Updated %s: %d -> %d (delta=%d)', $field, $oldVal, $newVal, $inc));
            }

            if (!method_exists($repo, 'save')) {
                error_log('[Usage][FATAL] Repository does not have save method!');
                return;
            }

            $repo->save($row);

            $after = ['sent'=>$row->getSent(),'delivered'=>$row->getDelivered(),'bounced'=>$row->getBounced(),'complained'=>$row->getComplained(),'opens'=>$row->getOpens(),'clicks'=>$row->getClicks()];
            error_log(sprintf('[Usage] %s company=%d day=%s before=%s delta=%s after=%s',
                $created ? 'created' : 'updated', $company->getId(), $dayStart->format('Y-m-d'), json_encode($before), json_encode($deltas), json_encode($after)));

        } catch (\Throwable $e) {
            error_log(sprintf('[Usage][EXCEPTION] %s at %s:%d\nTrace: %s',
                $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString()));
        }
    }

    /** Sum "sent" between two UTC datetimes (inclusive start, exclusive end). */
    private function sumSentBetween(int $companyId, \DateTimeImmutable $startUtc, \DateTimeImmutable $endUtc): int
    {
        /** @var \App\Repository\UsageAggregateRepository $repo */
        $repo = $this->repos->getRepository(UsageAggregate::class);

        $sum = 0;
        $rows = method_exists($repo, 'findBy')
            ? $repo->findBy(['company_id' => $companyId])
            : (method_exists($repo, 'findAll') ? $repo->findAll() : []);

        $startDay = $startUtc->setTime(0,0,0);
        $endDay = $endUtc->setTime(0,0,0);

        foreach ((array)$rows as $ua) {
            $d = $ua->getDate();
            if ($d instanceof \DateTimeInterface) {
                $dayDate = $d instanceof \DateTimeImmutable ? $d : new \DateTimeImmutable($d->format('Y-m-d H:i:s'));
                $dayDate = $dayDate->setTime(0,0,0);
                if ($dayDate >= $startDay && $dayDate < $endDay) {
                    // We're counting 'sent' which now includes messages at enqueue time
                    $sum += (int)($ua->getSent() ?? 0);
                }
            }
        }
        error_log(sprintf('[Usage] sumSentBetween company=%d start=%s end=%s sum=%d',
            $companyId, $startUtc->format('Y-m-d'), $endUtc->format('Y-m-d'), $sum));
        return $sum;
    }

    /* =================== QUOTAS (Plan + monthly counter) =================== */

    /** Enforce per-day and per-month quotas from the Company's Plan. Throws 429 on exceed. */
    private function enforceQuotas(Company $company, \DateTimeImmutable $nowUtc, int $thisMessageRcpts): void
    {
        [$dailyLimit, $monthlyLimit] = $this->resolveQuotas($company);
        error_log(sprintf('[Quota] resolved limits company=%d daily=%d monthly=%d rcptsThisMessage=%d',
            $company->getId(), $dailyLimit, $monthlyLimit, $thisMessageRcpts));

        if ($dailyLimit <= 0 && $monthlyLimit <= 0) { error_log('[Quota] no limits to enforce'); return; }

        $companyId = (int)$company->getId();

        if ($dailyLimit > 0) {
            $dayStart  = $nowUtc->setTime(0,0,0);
            $dayEnd    = $dayStart->modify('+1 day');
            $sentToday = $this->sumSentBetween($companyId, $dayStart, $dayEnd);
            error_log(sprintf('[Quota] daily sentToday=%d dailyLimit=%d', $sentToday, $dailyLimit));
            if (($sentToday + $thisMessageRcpts) > $dailyLimit) {
                throw new \RuntimeException('Daily sending limit exceeded', 429);
            }
        }

        if ($monthlyLimit > 0) {
            // ensure row exists so admins can see it even before send
            $this->ensureMonthlyCounterRow($company, $nowUtc);

            $sentThisMonth = $this->getMonthlyCount($company, $nowUtc);
            error_log(sprintf('[Quota] monthly sentThisMonth=%d monthlyLimit=%d', $sentThisMonth, $monthlyLimit));
            if (($sentThisMonth + $thisMessageRcpts) > $monthlyLimit) {
                throw new \RuntimeException('Monthly sending limit exceeded', 429);
            }
        }
    }

    /** Resolve daily / monthly quotas for a company from its Plan (with optional company overrides). */
    private function resolveQuotas(Company $company): array
    {
        $plan     = method_exists($company, 'getPlan') ? $company->getPlan() : null;
        $features = $plan && method_exists($plan, 'getFeatures') ? ($plan->getFeatures() ?? []) : [];
        $emailsPerDayFeature   = (int)($features['quotas']['emailsPerDay']   ?? 0);
        $emailsPerMonthFeature = (int)($features['quotas']['emailsPerMonth'] ?? 0);
        $includedMessages = $plan && method_exists($plan, 'getIncludedMessages')
            ? (int)($plan->getIncludedMessages() ?? 0)
            : 0;
        $monthlyFromPlan = $emailsPerMonthFeature > 0 ? $emailsPerMonthFeature : $includedMessages;

        $dailyOverride   = method_exists($company, 'getDaily_quota')   ? (int)($company->getDaily_quota()   ?? 0) : 0;
        $monthlyOverride = method_exists($company, 'getMonthly_quota') ? (int)($company->getMonthly_quota() ?? 0) : 0;

        $dailyLimit   = $dailyOverride   > 0 ? $dailyOverride   : $emailsPerDayFeature;
        $monthlyLimit = $monthlyOverride > 0 ? $monthlyOverride : $monthlyFromPlan;

        error_log(sprintf('[Quota] plan features: day=%d month=%d included=%d -> resolved daily=%d monthly=%d (overrides: d=%d m=%d)',
            $emailsPerDayFeature, $emailsPerMonthFeature, $includedMessages, $dailyLimit, $monthlyLimit, $dailyOverride, $monthlyOverride));

        return [$dailyLimit, $monthlyLimit];
    }

    private function monthAnchor(\DateTimeImmutable $nowUtc): \DateTimeImmutable
    {
        $anchor = $nowUtc
            ->setDate((int)$nowUtc->format('Y'), (int)$nowUtc->format('m'), 1)
            ->setTime(0, 0, 0, 0)
            ->setTimezone(new \DateTimeZone('UTC'));
        error_log('[RateLimit] monthAnchor='.$anchor->format('Y-m-d H:i:s'));
        return $anchor;
    }

    /** Build monthly key from the month anchor (first of month). */
    private function monthlyKey(\DateTimeImmutable $anchor): string
    {
        // Example output: messages:month:2025-09-01
        return self::RL_KEY_MONTH_PREFIX.$anchor->format('Y-m-01');
    }

    /** Read the monthly count from RateLimitCounter (0 if absent). */
    private function getMonthlyCount(Company $company, \DateTimeImmutable $nowUtc): int
    {
        $window = $this->monthAnchor($nowUtc);
        $windowStr = $window->format('Y-m-d H:i:s');
        $key = $this->monthlyKey($window);
        error_log(sprintf('[RateLimit][get] key=%s company=%d window=%s', $key, $company->getId(), $windowStr));

        try {
            /** @var \App\Repository\RateLimitCounterRepository $repo */
            $repo = $this->repos->getRepository(RateLimitCounter::class);

            // 🔧 Search using string window_start
            $row = method_exists($repo, 'findOneBy')
                ? $repo->findOneBy(['company_id' => $company->getId(), 'key' => $key, 'window_start' => $windowStr])
                : null;

            if (!$row) {
                error_log('[RateLimit][get] row NOT found -> 0');
                return 0;
            }

            $count = (int)($row->getCount() ?? 0);
            error_log(sprintf('[RateLimit][get] row FOUND id=%s count=%d',
                method_exists($row, 'getId') ? (string)$row->getId() : 'NULL', $count));
            return $count;

        } catch (\Throwable $t) {
            error_log('[RateLimit][get][EXCEPTION] '.$t->getMessage().' @ '.$t->getFile().':'.$t->getLine());
            return 0;
        }
    }


    /** Upsert/increment the monthly counter by $delta. */
    private function incMonthlyCount(Company $company, \DateTimeImmutable $nowUtc, int $delta): void
    {
        if ($delta === 0) { error_log('[RateLimit][inc] skip delta=0'); return; }

        $window = $this->monthAnchor($nowUtc);
        $windowStr = $window->format('Y-m-d H:i:s');
        $key = $this->monthlyKey($window);
        error_log(sprintf('[RateLimit][inc] key=%s company=%d window=%s delta=%d',
            $key, $company->getId(), $windowStr, $delta));

        try {
            /** @var \App\Repository\RateLimitCounterRepository $repo */
            $repo = $this->repos->getRepository(RateLimitCounter::class);

            // 🔧 Search using string window_start
            $row = method_exists($repo, 'findOneBy')
                ? $repo->findOneBy(['company_id' => $company->getId(), 'key' => $key, 'window_start' => $windowStr])
                : null;

            $created = false;
            if (!$row) {
                error_log('[RateLimit][inc] creating NEW row');
                $row = (new RateLimitCounter())
                    ->setCompany($company)
                    ->setKey($key)
                    ->setWindow_start($window)   // keep DateTime on entity
                    ->setCount(0)
                    ->setUpdated_at($nowUtc);
                $created = true;
            }

            $before = (int)($row->getCount() ?? 0);
            $row->setCount(max(0, $before + $delta))
                ->setUpdated_at($nowUtc);

            if (!method_exists($repo, 'save')) {
                error_log('[RateLimit][inc][FATAL] repository.save not available — cannot persist');
                return;
            }

            $repo->save($row);
            $after = (int)$row->getCount();
            error_log(sprintf('[RateLimit][inc] %s id=%s before=%d after=%d',
                $created ? 'CREATED' : 'UPDATED',
                method_exists($row, 'getId') ? (string)$row->getId() : 'NULL',
                $before, $after
            ));

        } catch (\Throwable $t) {
            error_log('[RateLimit][inc][EXCEPTION] '.$t->getMessage().' @ '.$t->getFile().':'.$t->getLine());
        }
    }


    /** Count message recipients (to+cc+bcc) for incrementing the monthly counter per recipient. */
    private function countRecipients(Message $msg): int
    {
        /** @var \App\Repository\MessageRecipientRepository $rRepo */
        $rRepo = $this->repos->getRepository(MessageRecipient::class);
        $rows  = $rRepo->findBy(['message_id' => $msg->getId()]) ?: $rRepo->findBy(['message' => $msg]);
        $count = is_array($rows) ? count($rows) : 0;
        error_log(sprintf('[Mail] countRecipients message_id=%d count=%d', $msg->getId(), $count));
        return $count;
    }

    /** Ensure the monthly counter row exists (count stays 0 if new). */
    private function ensureMonthlyCounterRow(Company $company, \DateTimeImmutable $nowUtc): void
    {
        $window = $this->monthAnchor($nowUtc);
        $windowStr = $window->format('Y-m-d H:i:s');
        $key = $this->monthlyKey($window);

        error_log(sprintf('[RateLimit][ensure] key=%s company=%d window=%s', $key, $company->getId(), $windowStr));

        try {
            /** @var \App\Repository\RateLimitCounterRepository $repo */
            $repo = $this->repos->getRepository(RateLimitCounter::class);

            // 🔧 IMPORTANT: always search with string window_start
            $row = method_exists($repo, 'findOneBy')
                ? $repo->findOneBy(['company_id' => $company->getId(), 'key' => $key, 'window_start' => $windowStr])
                : null;

            if ($row) {
                error_log(sprintf('[RateLimit][ensure] row EXISTS id=%s count=%s',
                    method_exists($row, 'getId') ? (string)$row->getId() : 'NULL',
                    (string)($row->getCount() ?? 'NULL')
                ));
                return;
            }

            error_log('[RateLimit][ensure] row NOT found, creating new…');
            $row = new RateLimitCounter()
                ->setCompany($company)               // entity relation OK
                ->setKey($key)
                ->setWindow_start($window)           // entity can keep DateTime
                ->setCount(0)
                ->setUpdated_at($nowUtc);

            if (!method_exists($repo, 'save')) {
                error_log('[RateLimit][ensure][FATAL] repository.save not available — cannot persist monthly row');
                return;
            }

            $repo->save($row);
            error_log(sprintf('[RateLimit][ensure] row CREATED id=%s window=%s count=0',
                method_exists($row, 'getId') ? (string)$row->getId() : 'NULL', $windowStr));

        } catch (\Throwable $t) {
            error_log('[RateLimit][ensure][EXCEPTION] '.$t->getMessage().' @ '.$t->getFile().':'.$t->getLine());
        }
    }

    /**
     * Create message entity, persist, send immediately, and heartbeat status to Redis.
     * Returns a SendOutcome with httpStatus + data (mirrors your Segment runNow shape).
     */
    public function createAndSendNowWithHeartbeat(array $body, Company $company, Domain $domain): SendOutcome
    {
        $t0 = microtime(true);

        // 1) Create + persist Message (implement these helpers to fit your codebase)
        $msg = $this->createMessageEntityFromBody($body, $company, $domain);
        $this->persistMessage($msg);

        $companyId = (int)$company->getId();
        $messageId = (int)$msg->getId();
        $key       = $this->mailStatusKey($companyId, $messageId);

        $lastBeat = 0;
        $beat = function (int $progress, string $message) use ($key, &$lastBeat) {
            $now = time();
            if ($now - $lastBeat < 5) return; // every ~5s
            $this->setMailStatus($key, [
                'status'      => 'sending',
                'message'     => $message,
                'progress'    => $progress,
                'heartbeatAt' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DATE_ATOM),
            ]);
            $lastBeat = $now;
        };

        // initial status
        $this->setMailStatus($key, [
            'status'   => 'starting',
            'progress' => 1,
            'createdAt'=> (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DATE_ATOM),
        ]);

        // 2) Build envelope from body (headers/to/cc/bcc/replyTo/etc)
        $beat(10, 'preparing envelope');
        $envelope = $this->buildEnvelopeFromBody($body);

        // 3) Send via MailSender (e.g., PhpMailerMailSender)
        $beat(35, 'connecting to SMTP');
        $res = $this->sender->send($msg, $envelope);

        // 4) Finalize
        $ok  = (bool)($res['ok'] ?? false);
        $mid = $res['message_id'] ?? null;
        $err = $res['error'] ?? null;

        $dt = (int)round(microtime(true) - $t0);

        if ($ok) {
            $this->markMessageSent($msg, $mid);
            $this->setMailStatus($key, [
                'status'      => 'sent',
                'progress'    => 100,
                'messageId'   => $mid,
                'durationSec' => $dt,
                'sentAt'      => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DATE_ATOM),
            ]);

            return new SendOutcome(201, [
                'status'   => 'sent',
                'entryId'  => $messageId,
                'result'   => ['ok' => true, 'message_id' => $mid],
            ]);
        }

        $this->markMessageFailed($msg, (string)$err);
        $this->setMailStatus($key, [
            'status'   => 'error',
            'progress' => 100,
            'message'  => (string)$err,
            'failedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DATE_ATOM),
        ]);

        return new SendOutcome(502, [
            'status'  => 'error',
            'entryId' => $messageId,
            'error'   => (string)$err,
        ]);
    }

    /* ================= Redis status helpers (like segments) ================= */

    private function mailStatusKey(int $companyId, int $messageId): string
    {
        return sprintf('mail:status:%d:%d', $companyId, $messageId);
    }

    private function setMailStatus(string $key, array $payload): void
    {
        $now  = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DATE_ATOM);
        $json = json_encode(array_merge(['updatedAt' => $now], $payload), JSON_UNESCAPED_SLASHES);

        if ($this->redis instanceof \Redis) {
            // SETEX key ttl payload
            $this->redis->setex($key, $this->statusTtlSec, $json);
        } else {
            // Predis
            $this->redis->executeRaw(['SETEX', $key, (string)$this->statusTtlSec, $json]);
        }
    }

    public function lastMailStatus(int $companyId, int $messageId): ?array
    {
        $key = $this->mailStatusKey($companyId, $messageId);
        $raw = $this->redis instanceof \Redis
            ? $this->redis->get($key)
            : $this->redis->executeRaw(['GET', $key]);

        if (!is_string($raw) || $raw === '') return null;
        $dec = json_decode($raw, true);
        return is_array($dec) ? $dec : null;
    }

    private static function makeRedisFromEnv(): \Redis|Predis
    {
        $scheme = getenv('REDIS_SCHEME') ?: 'tcp';
        $host   = getenv('REDIS_HOST')   ?: '127.0.0.1';
        $port   = (int)(getenv('REDIS_PORT') ?: 6379);
        $db     = (int)(getenv('REDIS_DB')   ?: 0);
        $user   = getenv('REDIS_USERNAME') ?: '';
        $pass   = getenv('REDIS_AUTH')     ?: (getenv('REDIS_PASSWORD') ?: '');
        $tls    = ($scheme === 'tls' || $scheme === 'rediss' || getenv('REDIS_TLS') === '1');

        // Prefer Predis if present
        if (class_exists(Predis::class)) {
            $params = [
                'scheme'   => $tls ? 'tls' : 'tcp',
                'host'     => $host,
                'port'     => $port,
                'database' => $db,
            ];
            if ($user !== '') $params['username'] = $user;
            if ($pass !== '') $params['password'] = $pass;

            $options = ['read_write_timeout' => 0];
            if ($tls) {
                $options['ssl'] = [
                    'verify_peer'      => false,
                    'verify_peer_name' => false,
                ];
            }

            $client = new Predis($params, $options);
            // Touch connection early to surface errors
            $client->executeRaw(['PING']);
            return $client;
        }

        // Fallback: phpredis
        if (!class_exists(\Redis::class)) {
            throw new \RuntimeException('No Redis client available (Predis/phpredis not installed).');
        }

        $ctx = null;
        if ($tls) {
            $ctx = stream_context_create([
                'ssl' => [
                    'verify_peer'      => false,
                    'verify_peer_name' => false,
                ],
            ]);
        }

        $r = new \Redis();
        $ok = @$r->connect($host, $port, 2.5, null, 0, 0, $ctx);
        if (!$ok) throw new \RuntimeException("Redis connect failed to {$host}:{$port}");

        if ($pass !== '') {
            $authOk = $user !== '' ? @$r->auth([$user, $pass]) : @$r->auth($pass);
            if (!$authOk) throw new \RuntimeException('Redis AUTH failed');
        }
        if ($db > 0 && !@$r->select($db)) {
            throw new \RuntimeException("Redis SELECT {$db} failed");
        }
        $r->setOption(\Redis::OPT_READ_TIMEOUT, -1);
        return $r;
    }

    /* ================= Your existing internals (stubs to integrate) ================= */

    private function createMessageEntityFromBody(array $body, Company $company, Domain $domain)
    {
        // TODO: hydrate Message + set company/domain bindings, headers, body, tracking, attachments, etc.
        // return $msg;
    }

    private function persistMessage(Message $msg): void
    {
        // TODO: repos->persist($msg) + flush, etc.
    }

    private function markMessageSent(Message $msg, ?string $providerMsgId): void
    {
        // TODO: set status, provider id, sent_at, persist
    }

    private function markMessageFailed(Message $msg, string $error): void
    {
        // TODO: set status, error, persist
    }

    private function buildEnvelopeFromBody(array $body): array
    {
        // Extract from/replyTo/to/cc/bcc/headers safely. Normalize strings/arrays.
        return [
            'from'    => $body['from']['email'] ?? null,
            'fromName'=> $body['from']['name']  ?? null,
            'replyTo' => $body['replyTo']       ?? null,
            'to'      => array_values((array)($body['to']  ?? [])),
            'cc'      => array_values((array)($body['cc']  ?? [])),
            'bcc'     => array_values((array)($body['bcc'] ?? [])),
            'headers' => (array)($body['headers'] ?? []),
        ];
    }

}