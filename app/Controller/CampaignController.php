<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Company;
use App\Entity\Campaign;
use App\Entity\ListGroup;
use App\Entity\Segment;
use App\Entity\Domain;
use App\Entity\Template;
use App\Entity\Contact;
use App\Entity\ListContact;
use App\Service\CompanyResolver;
use MonkeysLegion\Http\Message\JsonResponse;
use MonkeysLegion\Repository\RepositoryFactory;
use MonkeysLegion\Router\Attributes\Route;
use MonkeysLegion\Query\QueryBuilder;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

final class CampaignController
{
    public function __construct(
        private RepositoryFactory $repos,
        private CompanyResolver   $companyResolver,
        private QueryBuilder      $qb,
    ) {}

    /* ---------------------------- helpers ---------------------------- */

    private function auth(ServerRequestInterface $r): int {
        $uid = (int)$r->getAttribute('user_id', 0);
        if ($uid <= 0) throw new RuntimeException('Unauthorized', 401);
        return $uid;
    }

    private function company(string $hash, int $uid): Company {
        $c = $this->companyResolver->resolveCompanyForUser($hash, $uid);
        if (!$c) throw new RuntimeException('Company not found or access denied', 404);
        return $c;
    }

    private function now(): \DateTimeImmutable {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private function shape(Campaign $c): array {
        return [
            'id'          => $c->getId(),
            'name'        => $c->getName(),
            'subject'     => $c->getSubject(),
            'send_mode'   => $c->getSend_mode(),       // 'immediate' | 'scheduled'
            'scheduled_at'=> $c->getScheduled_at()?->format(\DateTimeInterface::ATOM),
            'target'      => $c->getTarget(),          // 'list' | 'segment'
            'status'      => $c->getStatus(),          // 'draft' | 'scheduled' | 'sending' | 'paused' | 'completed' | 'cancelled'
            'created_at'  => $c->getCreated_at()?->format(\DateTimeInterface::ATOM),

            'template_id' => $c->getTemplate()?->getId(),
            'domain_id'   => $c->getDomain()?->getId(),
            'listGroup_id'=> $c->getListGroup()?->getId(),
            'segment_id'  => $c->getSegment()?->getId(),

            'metrics'     => [
                'sent'       => $c->getSent() ?? 0,
                'delivered'  => $c->getDelivered() ?? 0,
                'opens'      => $c->getOpens() ?? 0,
                'clicks'     => $c->getClicks() ?? 0,
                'bounces'    => $c->getBounces() ?? 0,
                'complaints' => $c->getComplaints() ?? 0,
            ],
        ];
    }

    /* ----------------------------- CRUD ----------------------------- */

    #[Route(methods: 'GET', path: '/companies/{hash}/campaigns')]
    public function list(ServerRequestInterface $r): JsonResponse {
        $uid = $this->auth($r);
        $co  = $this->company((string)$r->getAttribute('hash'), $uid);

        /** @var \App\Repository\CampaignRepository $repo */
        $repo = $this->repos->getRepository(Campaign::class);

        $q       = $r->getQueryParams();
        $status  = (string)($q['status'] ?? '');
        $search  = trim((string)($q['search'] ?? ''));
        $page    = max(1, (int)($q['page'] ?? 1));
        $perPage = max(1, min(200, (int)($q['perPage'] ?? 25)));

        $rows = $repo->findBy(['company_id' => $co->getId()]);
        if ($status !== '') {
            $rows = array_values(array_filter($rows, fn(Campaign $c) => (string)$c->getStatus() === $status));
        }
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $rows = array_values(array_filter($rows, fn(Campaign $c) =>
                str_contains(mb_strtolower((string)$c->getName()), $needle) ||
                str_contains(mb_strtolower((string)$c->getSubject()), $needle)
            ));
        }

        $total = count($rows);
        $slice = array_slice($rows, ($page-1)*$perPage, $perPage);

        return new JsonResponse([
            'meta'  => ['page'=>$page,'perPage'=>$perPage,'total'=>$total,'totalPages'=>(int)ceil($total/$perPage)],
            'items' => array_map(fn(Campaign $c) => $this->shape($c), $slice),
        ]);
    }

    /**
     * Body:
     *  name (required), subject?, template_id?, domain_id?,
     *  target: 'list'|'segment', list_group_id? or segment_id?,
     *  send_mode: 'immediate'|'scheduled', scheduled_at?
     */
    #[Route(methods: 'POST', path: '/companies/{hash}/campaigns')]
    public function create(ServerRequestInterface $r): JsonResponse {
        $uid = $this->auth($r);
        $co  = $this->company((string)$r->getAttribute('hash'), $uid);

        $body = json_decode((string)$r->getBody(), true) ?: [];
        $name = trim((string)($body['name'] ?? ''));
        if ($name === '') throw new RuntimeException('Name is required', 400);

        /** @var \App\Repository\CampaignRepository $repo */
        $repo = $this->repos->getRepository(Campaign::class);

        $c = new Campaign()
            ->setCompany($co)
            ->setName($name)
            ->setSubject((string)($body['subject'] ?? '') ?: null)
            ->setSend_mode((string)($body['send_mode'] ?? 'immediate'))
            ->setTarget((string)($body['target'] ?? 'list'))
            ->setStatus('draft')
            ->setCreated_at($this->now());

        // relations (optional)
        if (!empty($body['template_id'])) {
            /** @var \App\Repository\TemplateRepository $tRepo */
            $tRepo = $this->repos->getRepository(Template::class);
            $tpl = $tRepo->find((int)$body['template_id']);
            if ($tpl) $c->setTemplate($tpl);
        }
        if (!empty($body['domain_id'])) {
            /** @var \App\Repository\DomainRepository $dRepo */
            $dRepo = $this->repos->getRepository(Domain::class);
            $dom = $dRepo->find((int)$body['domain_id']);
            if ($dom && $dom->getCompany()?->getId() === $co->getId()) $c->setDomain($dom);
        }

        // target
        if ($c->getTarget() === 'list') {
            $lgId = (int)($body['list_group_id'] ?? 0);
            if (!$lgId) throw new RuntimeException('list_group_id required for target=list', 400);
            /** @var \App\Repository\ListGroupRepository $lgRepo */
            $lgRepo = $this->repos->getRepository(ListGroup::class);
            $lg = $lgRepo->find($lgId);
            if (!$lg || $lg->getCompany()?->getId() !== $co->getId()) throw new RuntimeException('List not found', 404);
            $c->setListGroup($lg)->setSegment(null);
        } else {
            $segId = (int)($body['segment_id'] ?? 0);
            if (!$segId) throw new RuntimeException('segment_id required for target=segment', 400);
            /** @var \App\Repository\SegmentRepository $sRepo */
            $sRepo = $this->repos->getRepository(Segment::class);
            $s = $sRepo->find($segId);
            if (!$s || $s->getCompany()?->getId() !== $co->getId()) throw new RuntimeException('Segment not found', 404);
            $c->setSegment($s)->setListGroup(null);
        }

        if ($c->getSend_mode() === 'scheduled' && !empty($body['scheduled_at'])) {
            try { $c->setScheduled_at(new \DateTimeImmutable((string)$body['scheduled_at'])); } catch (\Throwable) {}
        }

        $repo->save($c);
        return new JsonResponse($this->shape($c), 201);
    }

    #[Route(methods: 'GET', path: '/companies/{hash}/campaigns/{id}')]
    public function get(ServerRequestInterface $r): JsonResponse {
        $uid = $this->auth($r);
        $co  = $this->company((string)$r->getAttribute('hash'), $uid);
        $id  = (int)$r->getAttribute('id');

        /** @var \App\Repository\CampaignRepository $repo */
        $repo = $this->repos->getRepository(Campaign::class);
        $c = $repo->find($id);
        if (!$c || $c->getCompany()?->getId() !== $co->getId()) throw new RuntimeException('Campaign not found', 404);

        return new JsonResponse($this->shape($c));
    }

    #[Route(methods: 'PATCH', path: '/companies/{hash}/campaigns/{id}')]
    public function update(ServerRequestInterface $r): JsonResponse {
        $uid = $this->auth($r);
        $co  = $this->company((string)$r->getAttribute('hash'), $uid);
        $id  = (int)$r->getAttribute('id');

        /** @var \App\Repository\CampaignRepository $repo */
        $repo = $this->repos->getRepository(Campaign::class);
        $c = $repo->find($id);
        if (!$c || $c->getCompany()?->getId() !== $co->getId()) throw new RuntimeException('Campaign not found', 404);

        $body = json_decode((string)$r->getBody(), true) ?: [];
        if (array_key_exists('name', $body))      $c->setName((string)($body['name'] ?? null) ?: null);
        if (array_key_exists('subject', $body))   $c->setSubject((string)($body['subject'] ?? null) ?: null);
        if (array_key_exists('send_mode', $body)) $c->setSend_mode((string)$body['send_mode'] ?: 'immediate');
        if (array_key_exists('target', $body))    $c->setTarget((string)$body['target'] ?: 'list');

        if (array_key_exists('scheduled_at', $body)) {
            $c->setScheduled_at(!empty($body['scheduled_at']) ? new \DateTimeImmutable((string)$body['scheduled_at']) : null);
        }

        // Update relations (optional)
        if (array_key_exists('template_id', $body)) {
            /** @var \App\Repository\TemplateRepository $tRepo */
            $tRepo = $this->repos->getRepository(Template::class);
            $tpl = !empty($body['template_id']) ? $tRepo->find((int)$body['template_id']) : null;
            $c->setTemplate($tpl);
        }
        if (array_key_exists('domain_id', $body)) {
            /** @var \App\Repository\DomainRepository $dRepo */
            $dRepo = $this->repos->getRepository(Domain::class);
            $dom = !empty($body['domain_id']) ? $dRepo->find((int)$body['domain_id']) : null;
            if ($dom && $dom->getCompany()?->getId() !== $co->getId()) throw new RuntimeException('Domain not in company', 400);
            $c->setDomain($dom);
        }

        if ($c->getTarget() === 'list' && array_key_exists('list_group_id', $body)) {
            /** @var \App\Repository\ListGroupRepository $lgRepo */
            $lgRepo = $this->repos->getRepository(ListGroup::class);
            $lg = !empty($body['list_group_id']) ? $lgRepo->find((int)$body['list_group_id']) : null;
            if ($lg && $lg->getCompany()?->getId() !== $co->getId()) throw new RuntimeException('List not in company', 400);
            $c->setListGroup($lg)->setSegment(null);
        }
        if ($c->getTarget() === 'segment' && array_key_exists('segment_id', $body)) {
            /** @var \App\Repository\SegmentRepository $sRepo */
            $sRepo = $this->repos->getRepository(Segment::class);
            $s = !empty($body['segment_id']) ? $sRepo->find((int)$body['segment_id']) : null;
            if ($s && $s->getCompany()?->getId() !== $co->getId()) throw new RuntimeException('Segment not in company', 400);
            $c->setSegment($s)->setListGroup(null);
        }

        $repo->save($c);
        return new JsonResponse($this->shape($c));
    }

    #[Route(methods: 'DELETE', path: '/companies/{hash}/campaigns/{id}')]
    public function delete(ServerRequestInterface $r): JsonResponse {
        $uid = $this->auth($r);
        $co  = $this->company((string)$r->getAttribute('hash'), $uid);
        $id  = (int)$r->getAttribute('id');

        /** @var \App\Repository\CampaignRepository $repo */
        $repo = $this->repos->getRepository(Campaign::class);
        $c = $repo->find($id);
        if (!$c || $c->getCompany()?->getId() !== $co->getId()) throw new RuntimeException('Campaign not found', 404);

        if (method_exists($repo, 'delete')) $repo->delete($c);
        elseif (method_exists($repo, 'remove')) $repo->remove($c);
        else $this->qb->delete('campaign')->where('id','=', $id)->execute();

        return new JsonResponse(null, 204);
    }

    /* ---------------------- lifecycle actions ---------------------- */

    // Draft → Scheduled (set schedule) or Draft → Sending (immediate)
    #[Route(methods: 'POST', path: '/companies/{hash}/campaigns/{id}/schedule')]
    public function schedule(ServerRequestInterface $r): JsonResponse {
        $uid = $this->auth($r);
        $co  = $this->company((string)$r->getAttribute('hash'), $uid);
        $id  = (int)$r->getAttribute('id');

        /** @var \App\Repository\CampaignRepository $repo */
        $repo = $this->repos->getRepository(Campaign::class);
        $c = $repo->find($id);
        if (!$c || $c->getCompany()?->getId() !== $co->getId()) throw new RuntimeException('Campaign not found', 404);

        $body = json_decode((string)$r->getBody(), true) ?: [];
        if (empty($body['scheduled_at'])) throw new RuntimeException('scheduled_at required', 400);

        $c->setSend_mode('scheduled')
            ->setScheduled_at(new \DateTimeImmutable((string)$body['scheduled_at']))
            ->setStatus('scheduled');

        $repo->save($c);
        return new JsonResponse($this->shape($c));
    }

    // Move to sending now
    #[Route(methods: 'POST', path: '/companies/{hash}/campaigns/{id}/send')]
    public function sendNow(ServerRequestInterface $r): JsonResponse {
        $uid = $this->auth($r);
        $co  = $this->company((string)$r->getAttribute('hash'), $uid);
        $id  = (int)$r->getAttribute('id');

        /** @var \App\Repository\CampaignRepository $repo */
        $repo = $this->repos->getRepository(Campaign::class);
        $c = $repo->find($id);
        if (!$c || $c->getCompany()?->getId() !== $co->getId()) throw new RuntimeException('Campaign not found', 404);

        $c->setSend_mode('immediate')->setScheduled_at(null)->setStatus('sending');
        $repo->save($c);

        // TODO enqueue a job to actually deliver
        return new JsonResponse($this->shape($c));
    }

    #[Route(methods: 'POST', path: '/companies/{hash}/campaigns/{id}/pause')]
    public function pause(ServerRequestInterface $r): JsonResponse {
        $uid = $this->auth($r);
        $co  = $this->company((string)$r->getAttribute('hash'), $uid);
        $id  = (int)$r->getAttribute('id');

        /** @var \App\Repository\CampaignRepository $repo */
        $repo = $this->repos->getRepository(Campaign::class);
        $c = $repo->find($id);
        if (!$c || $c->getCompany()?->getId() !== $co->getId()) throw new RuntimeException('Campaign not found', 404);

        if ($c->getStatus() !== 'sending' && $c->getStatus() !== 'scheduled') {
            throw new RuntimeException('Only scheduled/sending can be paused', 400);
        }
        $c->setStatus('paused'); $repo->save($c);
        return new JsonResponse($this->shape($c));
    }

    #[Route(methods: 'POST', path: '/companies/{hash}/campaigns/{id}/resume')]
    public function resume(ServerRequestInterface $r): JsonResponse {
        $uid = $this->auth($r);
        $co  = $this->company((string)$r->getAttribute('hash'), $uid);
        $id  = (int)$r->getAttribute('id');

        /** @var \App\Repository\CampaignRepository $repo */
        $repo = $this->repos->getRepository(Campaign::class);
        $c = $repo->find($id);
        if (!$c || $c->getCompany()?->getId() !== $co->getId()) throw new RuntimeException('Campaign not found', 404);

        if ($c->getStatus() !== 'paused') throw new RuntimeException('Only paused can resume', 400);
        // resume to previous planned state (simple rule: scheduled if has time else sending)
        $c->setStatus($c->getScheduled_at() ? 'scheduled' : 'sending');
        $repo->save($c);
        return new JsonResponse($this->shape($c));
    }

    #[Route(methods: 'POST', path: '/companies/{hash}/campaigns/{id}/cancel')]
    public function cancel(ServerRequestInterface $r): JsonResponse {
        $uid = $this->auth($r);
        $co  = $this->company((string)$r->getAttribute('hash'), $uid);
        $id  = (int)$r->getAttribute('id');

        /** @var \App\Repository\CampaignRepository $repo */
        $repo = $this->repos->getRepository(Campaign::class);
        $c = $repo->find($id);
        if (!$c || $c->getCompany()?->getId() !== $co->getId()) throw new RuntimeException('Campaign not found', 404);

        if (in_array($c->getStatus(), ['completed','cancelled'], true)) {
            throw new RuntimeException('Cannot cancel a completed/cancelled campaign', 400);
        }
        $c->setStatus('cancelled'); $repo->save($c);
        return new JsonResponse($this->shape($c));
    }

    /* ---------------- recipients preview (list/segment) ---------------- */

    #[Route(methods: 'GET', path: '/companies/{hash}/campaigns/{id}/recipients')]
    public function recipientsPreview(ServerRequestInterface $r): JsonResponse {
        $uid = $this->auth($r);
        $co  = $this->company((string)$r->getAttribute('hash'), $uid);
        $id  = (int)$r->getAttribute('id');

        /** @var \App\Repository\CampaignRepository $repo */
        $repo = $this->repos->getRepository(Campaign::class);
        $c = $repo->find($id);
        if (!$c || $c->getCompany()?->getId() !== $co->getId()) throw new RuntimeException('Campaign not found', 404);

        $q       = $r->getQueryParams();
        $page    = max(1, (int)($q['page'] ?? 1));
        $perPage = max(1, min(200, (int)($q['perPage'] ?? 25)));

        $items = [];
        $total = 0;

        if ($c->getTarget() === 'list') {
            $lg = $c->getListGroup();
            if (!$lg) return new JsonResponse(['meta'=>['page'=>1,'perPage'=>0,'total'=>0,'totalPages'=>0],'items'=>[]]);

            /** @var \App\Repository\ListContactRepository $lcRepo */
            $lcRepo = $this->repos->getRepository(ListContact::class);
            $rows = $lcRepo->findBy(['listGroup_id' => $lg->getId()]);
            $total = count($rows);
            $slice = array_slice($rows, ($page-1)*$perPage, $perPage);
            foreach ($slice as $lc) {
                $ct = $lc->getContact();
                if ($ct) $items[] = ['id'=>$ct->getId(),'email'=>$ct->getEmail(),'name'=>$ct->getName(),'status'=>$ct->getStatus()];
            }
        } else {
            $seg = $c->getSegment();
            if (!$seg) return new JsonResponse(['meta'=>['page'=>1,'perPage'=>0,'total'=>0,'totalPages'=>0],'items'=>[]]);

            // naive resolve using SegmentController logic (duplicate for simplicity)
            $def = $seg->getDefinition() ?? [];
            [$allCount, $rows] = $this->evaluateSegment($co->getId(), $def);
            $total = $allCount;
            $items = array_slice($rows, ($page-1)*$perPage, $perPage);
        }

        return new JsonResponse([
            'meta'  => ['page'=>$page,'perPage'=>$perPage,'total'=>$total,'totalPages'=>(int)ceil($total/$perPage)],
            'items' => $items,
        ]);
    }

    private function evaluateSegment(int $companyId, array $def): array {
        // Very small helper mirroring SegmentController::evaluateDefinitionFull
        /** @var \App\Repository\ContactRepository $cRepo */
        $cRepo = $this->repos->getRepository(Contact::class);
        $contacts = $cRepo->findBy(['company_id'=>$companyId]);

        $emailContains = isset($def['email_contains']) ? mb_strtolower((string)$def['email_contains']) : null;
        $statusEq      = $def['status'] ?? null;

        $rows = [];
        foreach ($contacts as $c) {
            if ($statusEq !== null && (string)$c->getStatus() !== $statusEq) continue;
            if ($emailContains !== null && !str_contains(mb_strtolower((string)$c->getEmail()), $emailContains)) continue;
            $rows[] = ['id'=>$c->getId(),'email'=>$c->getEmail(),'name'=>$c->getName(),'status'=>$c->getStatus()];
        }
        return [count($rows), $rows];
    }

    /* ----------------------------- stats ----------------------------- */

    #[Route(methods: 'GET', path: '/companies/{hash}/campaigns/{id}/stats')]
    public function stats(ServerRequestInterface $r): JsonResponse {
        $uid = $this->auth($r);
        $co  = $this->company((string)$r->getAttribute('hash'), $uid);
        $id  = (int)$r->getAttribute('id');

        /** @var \App\Repository\CampaignRepository $repo */
        $repo = $this->repos->getRepository(Campaign::class);
        $c = $repo->find($id);
        if (!$c || $c->getCompany()?->getId() !== $co->getId()) throw new RuntimeException('Campaign not found', 404);

        // If you track events per message elsewhere, aggregate here.
        return new JsonResponse([
            'metrics' => [
                'sent'       => $c->getSent() ?? 0,
                'delivered'  => $c->getDelivered() ?? 0,
                'opens'      => $c->getOpens() ?? 0,
                'clicks'     => $c->getClicks() ?? 0,
                'bounces'    => $c->getBounces() ?? 0,
                'complaints' => $c->getComplaints() ?? 0,
            ],
            'status'  => $c->getStatus(),
        ]);
    }
}
