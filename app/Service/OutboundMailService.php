<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Company;
use App\Entity\Domain;
use App\Entity\Message;
use App\Repository\MessageRepository;
use App\Service\Ports\MailQueue;
use App\Service\Ports\MailSender;
use MonkeysLegion\Repository\RepositoryFactory;

final class OutboundMailService
{
    public function __construct(
        private RepositoryFactory $repos,
        private MailQueue         $queue,
        private MailSender        $smtp,
    ) {}

    /** Persist Message as queued/preview and push to Redis when queue=true & not dryRun */
    public function createAndEnqueue(array $payload, Company $company, Domain $domain): array
    {
        $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $fromEmail = trim((string)($payload['from']['email'] ?? ''));
        if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('A valid from.email is required', 422);
        }

        $fromName  = isset($payload['from']['name']) ? trim((string)$payload['from']['name']) : null;
        $replyTo   = isset($payload['replyTo']) ? trim((string)$payload['replyTo']) : null;
        if ($replyTo && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('replyTo must be a valid email', 422);
        }

        $subject = isset($payload['subject']) ? trim((string)$payload['subject']) : null;
        $text    = isset($payload['text']) ? (string)$payload['text'] : null;
        $html    = isset($payload['html']) ? (string)$payload['html'] : null;

        $to  = $this->normalizeEmails($payload['to']  ?? []);
        $cc  = $this->normalizeEmails($payload['cc']  ?? []);
        $bcc = $this->normalizeEmails($payload['bcc'] ?? []);
        if (empty($to) && empty($cc) && empty($bcc)) {
            throw new \RuntimeException('At least one recipient (to/cc/bcc) is required', 422);
        }

        $headers  = isset($payload['headers']) && is_array($payload['headers']) ? $this->sanitizeHeaders($payload['headers']) : null;
        $tracking = isset($payload['tracking']) && is_array($payload['tracking']) ? $payload['tracking'] : [];

        $attachments = $this->normalizeAttachments($payload['attachments'] ?? []);

        $dryRun = (bool)($payload['dryRun'] ?? false);
        $queue  = (bool)($payload['queue']  ?? false);

        /** @var MessageRepository $messageRepo */
        $messageRepo = $this->repos->getRepository(Message::class);

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
            ->setOpen_tracking(isset($tracking['opens']) ? (bool)$tracking['opens'] : null)
            ->setClick_tracking(isset($tracking['clicks']) ? (bool)$tracking['clicks'] : null)
            ->setAttachments(!empty($attachments) ? $attachments : null)
            ->setCreated_at($nowUtc)
            ->setQueued_at($nowUtc)
            ->setFinal_state($dryRun ? 'preview' : ($queue ? 'queued' : 'queued'));

        $messageRepo->save($msg);

        if ($dryRun) {
            return [
                'status'  => 'preview',
                'message' => $this->shapeMessage($msg),
                'envelope'=> ['fromEmail'=>$fromEmail,'fromName'=>$fromName,'replyTo'=>$replyTo,'to'=>$to,'cc'=>$cc,'bcc'=>$bcc,'headers'=>$headers],
            ];
        }

        // Always queue for high-volume path
        $job = [
            'message_id' => $msg->getId(),
            'company_id' => $company->getId(),
            'domain_id'  => $domain->getId(),
            'envelope'   => [
                'fromEmail'   => $fromEmail,
                'fromName'    => $fromName,
                'replyTo'     => $replyTo,
                'to'          => $to,
                'cc'          => $cc,
                'bcc'         => $bcc,
                'headers'     => $headers ?? [],
                'attachments' => $attachments,
            ],
            'created_at' => $nowUtc->format(DATE_ATOM),
        ];

        $entryId = $this->queue->enqueue($job);
        if (!$entryId) {
            $msg->setFinal_state('queue_failed');
            $messageRepo->save($msg);
            return ['status' => 'queue_failed', 'message' => $this->shapeMessage($msg)];
        }

        return ['status' => 'queued', 'queue' => $this->queue->getStream(), 'entryId' => $entryId, 'message' => $this->shapeMessage($msg)];
    }

    /** Worker path: load Message, send via SMTP, update state */
    public function processJob(array $job): void
    {
        $id = (int)($job['message_id'] ?? 0);
        if ($id <= 0) return;

        /** @var MessageRepository $repo */
        $repo = $this->repos->getRepository(Message::class);
        $msg = $repo->find($id);
        if (!$msg) return;

        $res = $this->smtp->send($msg, (array)($job['envelope'] ?? []));

        $msg->setSent_at(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $msg->setFinal_state($res['ok'] ? 'sent' : 'failed');
        if (!empty($res['message_id'])) $msg->setMessage_id((string)$res['message_id']);
        $repo->save($msg);
    }

    /* ----------------------- helpers ----------------------- */

    private function normalizeEmails(array $list): array
    {
        $out = [];
        foreach ($list as $v) {
            $e = is_array($v) ? ($v['email'] ?? '') : $v;
            $e = trim((string)$e);
            if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) $out[] = $e;
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
}
