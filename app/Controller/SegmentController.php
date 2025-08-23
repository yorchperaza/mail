<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Company;
use App\Entity\Segment;
use App\Entity\Contact;
use App\Entity\ListGroup;
use App\Entity\ListContact;
use App\Service\CompanyResolver;
use MonkeysLegion\Http\Message\JsonResponse;
use MonkeysLegion\Repository\RepositoryFactory;
use MonkeysLegion\Router\Attributes\Route;
use MonkeysLegion\Query\QueryBuilder;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

final class SegmentController
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

    private function shape(Segment $s, bool $withCounts = true): array {
        $out = [
            'id'                  => $s->getId(),
            'name'                => $s->getName(),
            'definition'          => $s->getDefinition(),
            'materialized_count'  => $s->getMaterialized_count(),
            'last_built_at'       => $s->getLast_built_at()?->format(\DateTimeInterface::ATOM),
            'created_at'          => null, // add if you later add a field
        ];
        return $withCounts ? $out : $out;
    }

    /* ----------------------------- CRUD ----------------------------- */

    #[Route(methods: 'GET', path: '/companies/{hash}/segments')]
    public function list(ServerRequestInterface $r): JsonResponse {
        $uid = $this->auth($r);
        $co  = $this->company((string)$r->getAttribute('hash'), $uid);

        /** @var \App\Repository\SegmentRepository $repo */
        $repo = $this->repos->getRepository(Segment::class);

        $q       = $r->getQueryParams();
        $search  = trim((string)($q['search'] ?? ''));
        $page    = max(1, (int)($q['page'] ?? 1));
        $perPage = max(1, min(200, (int)($q['perPage'] ?? 25)));

        $rows = $repo->findBy(['company_id' => $co->getId()]);
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $rows = array_values(array_filter($rows, fn(Segment $s) =>
            str_contains(mb_strtolower((string)$s->getName()), $needle)
            ));
        }

        $total = count($rows);
        $slice = array_slice($rows, ($page-1)*$perPage, $perPage);

        return new JsonResponse([
            'meta'  => ['page'=>$page,'perPage'=>$perPage,'total'=>$total,'totalPages'=>(int)ceil($total/$perPage)],
            'items' => array_map(fn(Segment $s) => $this->shape($s), $slice),
        ]);
    }

    #[Route(methods: 'POST', path: '/companies/{hash}/segments')]
    public function create(ServerRequestInterface $r): JsonResponse {
        $uid = $this->auth($r);
        $co  = $this->company((string)$r->getAttribute('hash'), $uid);

        $body = json_decode((string)$r->getBody(), true) ?: [];
        $name = trim((string)($body['name'] ?? ''));
        if ($name === '') throw new RuntimeException('Name is required', 400);

        $def  = isset($body['definition']) && is_array($body['definition']) ? $body['definition'] : [];

        /** @var \App\Repository\SegmentRepository $repo */
        $repo = $this->repos->getRepository(Segment::class);

        $s = (new Segment())
            ->setCompany($co)
            ->setName($name)
            ->setDefinition($def)
            ->setMaterialized_count(0)
            ->setLast_built_at(null);

        $repo->save($s);
        return new JsonResponse($this->shape($s), 201);
    }

    #[Route(methods: 'GET', path: '/companies/{hash}/segments/{id}')]
    public function get(ServerRequestInterface $r): JsonResponse {
        $uid = $this->auth($r);
        $co  = $this->company((string)$r->getAttribute('hash'), $uid);
        $id  = (int)$r->getAttribute('id');

        /** @var \App\Repository\SegmentRepository $repo */
        $repo = $this->repos->getRepository(Segment::class);
        $s = $repo->find($id);
        if (!$s || $s->getCompany()?->getId() !== $co->getId()) throw new RuntimeException('Segment not found', 404);

        return new JsonResponse($this->shape($s));
    }

    #[Route(methods: 'PATCH', path: '/companies/{hash}/segments/{id}')]
    public function update(ServerRequestInterface $r): JsonResponse {
        $uid = $this->auth($r);
        $co  = $this->company((string)$r->getAttribute('hash'), $uid);
        $id  = (int)$r->getAttribute('id');

        /** @var \App\Repository\SegmentRepository $repo */
        $repo = $this->repos->getRepository(Segment::class);
        $s = $repo->find($id);
        if (!$s || $s->getCompany()?->getId() !== $co->getId()) throw new RuntimeException('Segment not found', 404);

        $body = json_decode((string)$r->getBody(), true) ?: [];
        if (array_key_exists('name', $body)) {
            $name = trim((string)$body['name']);
            if ($name === '') throw new RuntimeException('Name cannot be empty', 400);
            $s->setName($name);
        }
        if (array_key_exists('definition', $body)) {
            $s->setDefinition(is_array($body['definition']) ? $body['definition'] : []);
            // if definition changes, invalidate materialization
            $s->setMaterialized_count(0)->setLast_built_at(null);
        }

        $repo->save($s);
        return new JsonResponse($this->shape($s));
    }

    #[Route(methods: 'DELETE', path: '/companies/{hash}/segments/{id}')]
    public function delete(ServerRequestInterface $r): JsonResponse {
        $uid = $this->auth($r);
        $co  = $this->company((string)$r->getAttribute('hash'), $uid);
        $id  = (int)$r->getAttribute('id');

        /** @var \App\Repository\SegmentRepository $repo */
        $repo = $this->repos->getRepository(Segment::class);
        $s = $repo->find($id);
        if (!$s || $s->getCompany()?->getId() !== $co->getId()) throw new RuntimeException('Segment not found', 404);

        if (method_exists($repo, 'delete')) $repo->delete($s);
        elseif (method_exists($repo, 'remove')) $repo->remove($s);
        else $this->qb->delete('segment')->where('id','=', $id)->execute();

        return new JsonResponse(null, 204);
    }

    /* ------------- build / preview recipients from definition ------------- */

    /**
     * POST /build: Compute and store materialized_count + last_built_at.
     * Body (optional): { dryRun?: bool }
     */
    #[Route(methods: 'POST', path: '/companies/{hash}/segments/{id}/build')]
    public function build(ServerRequestInterface $r): JsonResponse {
        $uid = $this->auth($r);
        $co  = $this->company((string)$r->getAttribute('hash'), $uid);
        $id  = (int)$r->getAttribute('id');

        /** @var \App\Repository\SegmentRepository $repo */
        $repo = $this->repos->getRepository(Segment::class);
        $s = $repo->find($id);
        if (!$s || $s->getCompany()?->getId() !== $co->getId()) throw new RuntimeException('Segment not found', 404);

        $body = json_decode((string)$r->getBody(), true) ?: [];
        $dry  = (bool)($body['dryRun'] ?? false);

        // Very simple “engine”: filter contacts by definition rules
        $def = $s->getDefinition() ?? [];
        [$matches, $sample] = $this->evaluateDefinition($co->getId(), $def, 20);

        if (!$dry) {
            $s->setMaterialized_count($matches)->setLast_built_at($this->now());
            $repo->save($s);
        }

        return new JsonResponse([
            'segment'  => $this->shape($s),
            'matches'  => $matches,
            'sample'   => $sample, // up to 20 contacts with id/email/name
            'dryRun'   => $dry,
        ]);
    }

    /**
     * GET /preview: same evaluation but read-only and paginated.
     * Query: page, perPage
     */
    #[Route(methods: 'GET', path: '/companies/{hash}/segments/{id}/preview')]
    public function preview(ServerRequestInterface $r): JsonResponse {
        $uid = $this->auth($r);
        $co  = $this->company((string)$r->getAttribute('hash'), $uid);
        $id  = (int)$r->getAttribute('id');

        /** @var \App\Repository\SegmentRepository $repo */
        $repo = $this->repos->getRepository(Segment::class);
        $s = $repo->find($id);
        if (!$s || $s->getCompany()?->getId() !== $co->getId()) throw new RuntimeException('Segment not found', 404);

        $q       = $r->getQueryParams();
        $page    = max(1, (int)($q['page'] ?? 1));
        $perPage = max(1, min(200, (int)($q['perPage'] ?? 25)));

        $def = $s->getDefinition() ?? [];

        // naive evaluation: fetch all ids then slice (replace with SQL if needed)
        [$allCount, $allRows] = $this->evaluateDefinitionFull($co->getId(), $def);
        $slice = array_slice($allRows, ($page-1)*$perPage, $perPage);

        return new JsonResponse([
            'meta'  => ['page'=>$page,'perPage'=>$perPage,'total'=>$allCount,'totalPages'=>(int)ceil($allCount/$perPage)],
            'items' => $slice,
        ]);
    }

    /* ------------------------ definition evaluator ------------------------ */

    /**
     * NOTE: This is a minimal evaluator. Replace with SQL built via QueryBuilder
     * for large datasets. Supported examples:
     *   - ["status" => "subscribed"]
     *   - ["email_contains" => "@example.com"]
     *   - ["in_list_ids" => [1,2,3]]
     *   - ["not_in_list_ids" => [4]]
     *   - ["gdpr_consent" => true]
     */
    private function evaluateDefinition(int $companyId, array $def, int $sampleSize=20): array {
        /** @var \App\Repository\ContactRepository $cRepo */
        $cRepo = $this->repos->getRepository(Contact::class);
        $contacts = $cRepo->findBy(['company_id' => $companyId]);

        $inListIds      = array_map('intval', $def['in_list_ids'] ?? []);
        $notInListIds   = array_map('intval', $def['not_in_list_ids'] ?? []);
        $statusEq       = isset($def['status']) ? (string)$def['status'] : null;
        $emailContains  = isset($def['email_contains']) ? mb_strtolower((string)$def['email_contains']) : null;
        $gdprConsentReq = array_key_exists('gdpr_consent', $def) ? (bool)$def['gdpr_consent'] : null;

        // Preload memberships only when needed
        $byContactLists = [];
        if ($inListIds || $notInListIds) {
            /** @var \App\Repository\ListContactRepository $lcRepo */
            $lcRepo = $this->repos->getRepository(ListContact::class);
            $allLc = $lcRepo->findBy(['company_id' => $companyId]); // if your repo supports this; else fetch all and filter by list->company

            foreach ($allLc as $lc) {
                $cid = $lc->getContact()?->getId() ?? null;
                $lg  = $lc->getListGroup();
                if (!$cid || !$lg || $lg->getCompany()?->getId() !== $companyId) continue;
                $byContactLists[$cid] = $byContactLists[$cid] ?? [];
                $byContactLists[$cid][] = $lg->getId();
            }
        }

        $matched = [];
        foreach ($contacts as $c) {
            $ok = true;

            if ($statusEq !== null && (string)$c->getStatus() !== $statusEq) $ok = false;
            if ($ok && $emailContains !== null) {
                $em = mb_strtolower((string)$c->getEmail());
                if ($em === '' || !str_contains($em, $emailContains)) $ok = false;
            }
            if ($ok && $gdprConsentReq !== null) {
                $has = $c->getGdpr_consent_at() !== null;
                if ($gdprConsentReq !== $has) $ok = false;
            }
            if ($ok && ($inListIds || $notInListIds)) {
                $cid  = $c->getId();
                $sets = $byContactLists[$cid] ?? [];
                if ($inListIds && count(array_intersect($inListIds, $sets)) === 0) $ok = false;
                if ($ok && $notInListIds && count(array_intersect($notInListIds, $sets)) > 0) $ok = false;
            }

            if ($ok) {
                $matched[] = [
                    'id'    => $c->getId(),
                    'email' => $c->getEmail(),
                    'name'  => $c->getName(),
                    'status'=> $c->getStatus(),
                ];
            }
        }

        $count  = count($matched);
        $sample = array_slice($matched, 0, $sampleSize);
        return [$count, $sample];
    }

    private function evaluateDefinitionFull(int $companyId, array $def): array {
        [$count, $sample] = $this->evaluateDefinition($companyId, $def, PHP_INT_MAX);
        return [$count, $sample]; // “sample” here is actually full list
    }
}
