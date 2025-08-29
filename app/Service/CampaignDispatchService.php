<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Campaign;
use App\Entity\ListContact;
use App\Entity\Contact;
use App\Service\Ports\MailQueue;
use MonkeysLegion\Repository\RepositoryFactory;
use RuntimeException;

final class CampaignDispatchService
{
    public function __construct(
        private RepositoryFactory $repos,
        private MailQueue         $queue,
    )
    {
    }

    /**
     * Expand recipients and enqueue outbound jobs immediately.
     * Returns ['enqueued' => int, 'skipped' => int].
     */
    public function sendNow(Campaign $c): array
    {
        if ($c->getStatus() === 'cancelled' || $c->getStatus() === 'completed') {
            throw new RuntimeException('Campaign cannot be sent in its current status');
        }
        if (!$c->getDomain()) throw new RuntimeException('Campaign domain is required to send');
        if (!$c->getTemplate()) throw new RuntimeException('Campaign template is required to send');
        [$total, $rows] = $this->resolveRecipients($c);
        $enq = 0;
        $skip = 0;
        foreach ($rows as $r) {
            try {
                // basic row validation
                $email = (string)($r['email'] ?? '');
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $skip++; // skip invalid recipient silently (or log)
                    continue;
                }

                $ok = $this->queue->enqueue([
                    'kind'        => 'campaign',
                    'company_id'  => $c->getCompany()?->getId(),
                    'campaign_id' => $c->getId(),
                    'domain_id'   => $c->getDomain()?->getId(),
                    'template_id' => $c->getTemplate()?->getId(),
                    'subject'     => $c->getSubject(),
                    'recipient'   => [
                        'contact_id' => $r['id'] ?? null,
                        'email'      => $email,
                        'name'       => $r['name'] ?? null,
                    ],
                    'tracking'    => ['opens' => true, 'clicks' => true],
                    'created_at'  => new \DateTimeImmutable('now', new \DateTimeZone('UTC'))->format(DATE_ATOM),
                ]);

                $ok ? $enq++ : $skip++;
            } catch (\JsonException $je) {
                // JSON payload had something non-encodable; log and skip
                error_log('enqueue JSON error: ' . $je->getMessage());
                $skip++;
            } catch (\Throwable $e) {
                // Redis hiccup etc. — log and skip this contact
                error_log('enqueue error: ' . $e->getMessage());
                $skip++;
            }
        }

        // flip campaign state to "sending"
        $c->setStatus('sending')->setSend_mode('immediate')->setScheduled_at(null);
        /** @var \App\Repository\CampaignRepository $crepo */
        $crepo = $this->repos->getRepository(Campaign::class);
        $crepo->save($c);

        return ['enqueued' => $enq, 'skipped' => $skip, 'total' => $total];
    }

    /**
     * Mark as scheduled and (optionally) enqueue a scheduler hint.
     * If you have a dedicated scheduler worker, it can pick this up later.
     * @throws \DateMalformedStringException
     */
    public function scheduleAt(Campaign $c, \DateTimeImmutable $when): void
    {
        if ($c->getStatus() === 'cancelled' || $c->getStatus() === 'completed') {
            throw new RuntimeException('Campaign cannot be scheduled in its current status');
        }

        $c->setSend_mode('scheduled')
            ->setScheduled_at($when)
            ->setStatus('scheduled');

        /** @var \App\Repository\CampaignRepository $crepo */
        $crepo = $this->repos->getRepository(Campaign::class);
        $crepo->save($c);

        // Optional: push a scheduler hint (if you build a scheduler worker)
        $this->queue->enqueue([
            'kind' => 'campaign_schedule',
            'campaign_id' => $c->getId(),
            'company_id' => $c->getCompany()?->getId(),
            'run_at' => $when->format(DATE_ATOM),
            'created_at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC'))->format(DATE_ATOM),
        ]);
    }

    /**
     * Resolve recipients for list or segment targets.
     * Returns [total_count, rows[]] with rows like ['id'=>int,'email'=>string,'name'=>?string,'status'=>?string]
     */
    private function resolveRecipients(Campaign $c): array
    {
        if ($c->getTarget() === 'list') {
            $lg = $c->getListGroup();
            if (!$lg) return [0, []];

            /** @var \App\Repository\ListContactRepository $lcRepo */
            $lcRepo = $this->repos->getRepository(ListContact::class);
            $links = $lcRepo->findBy(['listGroup_id' => $lg->getId()]);

            $rows = [];
            foreach ($links as $lc) {
                $ct = $lc->getContact();
                if (!$ct) continue;
                if (!$ct->getEmail()) continue;
                $rows[] = [
                    'id' => $ct->getId(),
                    'email' => (string)$ct->getEmail(),
                    'name' => $ct->getName(),
                    'status' => $ct->getStatus(),
                ];
            }
            return [count($rows), $rows];
        }

        // Segment: reuse basic evaluation (same shape used in your controller)
        /** @var \App\Repository\ContactRepository $cRepo */
        $cRepo = $this->repos->getRepository(Contact::class);
        $contacts = $cRepo->findBy(['company_id' => $c->getCompany()?->getId()]);

        $seg = $c->getSegment();
        if (!$seg) return [0, []];
        $def = $seg->getDefinition() ?? [];

        $emailContains = isset($def['email_contains']) ? mb_strtolower((string)$def['email_contains']) : null;
        $statusEq = $def['status'] ?? null;

        $rows = [];
        foreach ($contacts as $ct) {
            if (!$ct->getEmail()) continue;
            if ($statusEq !== null && (string)$ct->getStatus() !== $statusEq) continue;
            if ($emailContains !== null && !str_contains(mb_strtolower((string)$ct->getEmail()), $emailContains)) continue;
            $rows[] = [
                'id' => $ct->getId(),
                'email' => (string)$ct->getEmail(),
                'name' => $ct->getName(),
                'status' => $ct->getStatus(),
            ];
        }
        return [count($rows), $rows];
    }
}
