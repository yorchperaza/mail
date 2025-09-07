<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Company;
use App\Entity\Segment;
use App\Entity\Contact;
use App\Entity\ListGroup;
use App\Entity\ListContact;
use App\Service\CompanyResolver;
use App\Service\SegmentBuildOrchestrator;
use MonkeysLegion\Http\Message\JsonResponse;
use MonkeysLegion\Repository\RepositoryFactory;
use MonkeysLegion\Router\Attributes\Route;
use MonkeysLegion\Query\QueryBuilder;
use Predis\Client;
use Psr\Http\Message\ServerRequestInterface;
use Random\RandomException;
use RuntimeException;

final class SegmentController
{
    public function __construct(
        private RepositoryFactory $repos,
        private CompanyResolver   $companyResolver,
        private QueryBuilder      $qb,
        private Client    $redis,
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
            'created_at'          => null,
            'hash'                => $s->getHash() ?? '',
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

    /**
     * @throws RandomException
     * @throws \JsonException
     */
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
        $hash = bin2hex(random_bytes(32));
        $s = new Segment()
            ->setCompany($co)
            ->setName($name)
            ->setDefinition($def)
            ->setHash($hash)
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
        error_log('Fetching segment id=' . $id . ' for company id=' . $co->getId());

        /** @var \App\Repository\SegmentRepository $repo */
        $repo = $this->repos->getRepository(Segment::class);
        $s = $repo->findOneBy(['id' => $id]);
        error_log('Segment found: ' . ($s ? 'yes' : 'no'));
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

//    /**
//     * POST /build: Enqueue segment build job
//     * Body (optional): { dryRun?: bool, materialize?: bool }
//     */
//    #[Route(methods: 'POST', path: '/companies/{hash}/segments/{id}/build')]
//    public function build(ServerRequestInterface $r): JsonResponse {
//        try {
//            $uid = $this->auth($r);
//            $co  = $this->company((string)$r->getAttribute('hash'), $uid);
//            $id  = (int)$r->getAttribute('id');
//
//            /** @var \App\Repository\SegmentRepository $repo */
//            $repo = $this->repos->getRepository(Segment::class);
//            $s = $repo->find($id);
//            if (!$s || $s->getCompany()?->getId() !== $co->getId()) {
//                throw new RuntimeException('Segment not found', 404);
//            }
//
//            $body = json_decode((string)$r->getBody(), true) ?: [];
//            $dry  = (bool)($body['dryRun'] ?? false);
//            $materialize = (bool)($body['materialize'] ?? true);
//
//            if ($dry) {
//                // For dry run, evaluate synchronously without queuing
//                $t0 = microtime(true);
//                $def = $s->getDefinition() ?? [];
//                $result = $this->evaluateDefinition($co->getId(), $def, 20);
//                $result += [0 => 0, 1 => [], 2 => null];
//                [$matches, $sample, $checked] = $result;
//
//                return new JsonResponse([
//                    'segment' => $this->shape($s),
//                    'matches' => $matches,
//                    'sample'  => $sample,
//                    'dryRun'  => true,
//                    'status'  => 'completed',
//                    'duration_ms' => (int)round((microtime(true) - $t0) * 1000)
//                ]);
//            }
//
//            // For real builds, enqueue to Redis
//            $orchestrator = new SegmentBuildOrchestrator(
//                $this->repos,
//                $this->qb,
//                $this->redis // Make sure you have Redis instance available
//            );
//
//            $entryId = $orchestrator->enqueueBuild(
//                $co->getId(),
//                $s->getId(),
//                $materialize
//            );
//
//            error_log("[SEG][CTRL][BUILD] Enqueued job with entry ID: {$entryId}");
//
//            return new JsonResponse([
//                'segment'  => $this->shape($s),
//                'status'   => 'queued',
//                'queueId'  => $entryId,
//                'message'  => 'Build job has been queued',
//                'statusUrl' => "/companies/{$r->getAttribute('hash')}/segments/{$id}/builds/status"
//            ], 202); // 202 Accepted
//
//        } catch (\Throwable $e) {
//            error_log("[segments.build] ERROR: " . $e->getMessage());
//            $code = ($e instanceof RuntimeException && $e->getCode() >= 400 && $e->getCode() < 600)
//                ? $e->getCode()
//                : 500;
//
//            return new JsonResponse([
//                'error'   => $e->getMessage(),
//                'type'    => (new \ReflectionClass($e))->getShortName(),
//                'code'    => $code,
//                'traceId' => bin2hex(random_bytes(6)),
//            ], $code);
//        }
//    }

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
    private function evaluateDefinition(int $companyId, array $def, int $sampleSize = 20): array {
        error_log("=== evaluateDefinition start (company=$companyId) ===");
        error_log("Definition: " . json_encode($def));

        /** @var \App\Repository\ContactRepository $cRepo */
        $cRepo    = $this->repos->getRepository(Contact::class);
        $contacts = $cRepo->findBy(['company_id' => $companyId]);
        $contactsCount = is_countable($contacts) ? count($contacts) : 0;
        error_log("Loaded {$contactsCount} contacts");

        $checkedTotal = $contactsCount;

        $inListIds      = array_values(array_unique(array_map('intval', $def['in_list_ids'] ?? [])));
        $notInListIds   = array_values(array_unique(array_map('intval', $def['not_in_list_ids'] ?? [])));
        $statusEq       = isset($def['status']) ? (string)$def['status'] : null;
        $emailContains  = isset($def['email_contains']) ? mb_strtolower((string)$def['email_contains']) : null;
        $gdprConsentReq = array_key_exists('gdpr_consent', $def) ? (bool)$def['gdpr_consent'] : null;

        error_log("Filters: status=" . var_export($statusEq, true) .
            " email_contains=" . var_export($emailContains, true) .
            " gdpr_consent=" . var_export($gdprConsentReq, true) .
            " in=" . json_encode($inListIds) .
            " notIn=" . json_encode($notInListIds));

        // Preload memberships only if lists are part of the definition
        $byContactLists = [];
        if ($inListIds || $notInListIds) {
            /** @var \App\Repository\ListContactRepository $lcRepo */
            $lcRepo = $this->repos->getRepository(ListContact::class);

            // If your repo can't filter by company, fetch all and filter in PHP.
            $allLc = method_exists($lcRepo, 'findBy')
                ? $lcRepo->findBy([])   // conservative: fetch all memberships
                : (method_exists($lcRepo, 'findAll') ? $lcRepo->findAll() : []);

            $allLcCount = is_countable($allLc) ? count($allLc) : (is_array($allLc) ? count($allLc) : 0);
            error_log("Loaded {$allLcCount} ListContact rows (pre-filter)");

            foreach ($allLc as $lc) {
                $contact = $lc->getContact();
                $list    = $lc->getListGroup();

                if (!$contact) {
                    error_log("Skipping LC with null contact id=" . ($lc->getId() ?? '??'));
                    continue;
                }
                if (!$list) {
                    error_log("Skipping LC with null list id=" . ($lc->getId() ?? '??'));
                    continue;
                }
                $listCompany = $list->getCompany();
                if (!$listCompany) {
                    error_log("Skipping LC {$lc->getId()} — list has no company");
                    continue;
                }
                if ((int)$listCompany->getId() !== $companyId) {
                    continue; // skip memberships from other companies
                }

                $cid = (int)$contact->getId();
                $lid = (int)$list->getId();

                // IMPORTANT: store as a FLAT LIST of IDs (not a map lid => true)
                $byContactLists[$cid] ??= [];
                $byContactLists[$cid][] = $lid;
            }

            error_log("Memberships built for " . count($byContactLists) . " contacts");
        }

        // Evaluate
        $matched = [];
        foreach ($contacts as $c) {
            $ok  = true;
            $cid = (int)$c->getId();

            if ($statusEq !== null && (string)$c->getStatus() !== $statusEq) {
                $ok = false;
            }

            if ($ok && $emailContains !== null) {
                $em = mb_strtolower((string)$c->getEmail());
                if ($em === '' || !str_contains($em, $emailContains)) {
                    $ok = false;
                }
            }

            if ($ok && $gdprConsentReq !== null) {
                $has = $c->getGdpr_consent_at() !== null;
                if ($gdprConsentReq !== $has) {
                    $ok = false;
                }
            }

            if ($ok && ($inListIds || $notInListIds)) {
                // ensure $sets is a flat int array
                $sets = array_values(array_map('intval', $byContactLists[$cid] ?? []));

                // must be in ANY of these
                if ($inListIds && count(array_intersect($inListIds, $sets)) === 0) {
                    $ok = false;
                }
                // must NOT be in any of these
                if ($ok && $notInListIds && count(array_intersect($notInListIds, $sets)) > 0) {
                    $ok = false;
                }
            }

            if ($ok) {
                $matched[] = [
                    'id'     => $c->getId(),
                    'email'  => $c->getEmail(),
                    'name'   => $c->getName(),
                    'status' => $c->getStatus(),
                ];
            }
        }

        $count  = count($matched);
        $sample = array_slice($matched, 0, $sampleSize);

        error_log("Matched $count / $checkedTotal contacts (returning sample size " . count($sample) . ")");
        error_log("=== evaluateDefinition end ===");

        // Always return 3 elements
        return [$count, $sample, $checkedTotal];
    }


    private function evaluateDefinitionFull(int $companyId, array $def): array {
        [$count, $sample] = $this->evaluateDefinition($companyId, $def, PHP_INT_MAX);
        return [$count, $sample]; // “sample” here is actually full list
    }

    private function humanizeDefinition(int $companyId, array $def): array {
        $out = [];

        if (isset($def['status']) && $def['status'] !== '') {
            $out[] = "Status is “{$def['status']}”";
        }
        if (isset($def['email_contains']) && $def['email_contains'] !== '') {
            $out[] = "Email contains “{$def['email_contains']}”";
        }
        if (array_key_exists('gdpr_consent', $def)) {
            $out[] = $def['gdpr_consent'] ? 'Has GDPR consent' : 'Does NOT have GDPR consent';
        }

        // Replace list ids with names when possible
        $nameMap = $this->listNamesById($companyId, array_merge(
            $def['in_list_ids'] ?? [],
            $def['not_in_list_ids'] ?? []
        ));

        if (!empty($def['in_list_ids'])) {
            $names = array_map(fn($id) => $nameMap[(int)$id] ?? "#$id", $def['in_list_ids']);
            $out[] = 'In ANY of lists: ' . implode(', ', $names);
        }
        if (!empty($def['not_in_list_ids'])) {
            $names = array_map(fn($id) => $nameMap[(int)$id] ?? "#$id", $def['not_in_list_ids']);
            $out[] = 'NOT in lists: ' . implode(', ', $names);
        }

        if (!$out) $out[] = 'No filters (matches all contacts)';

        return $out;
    }

    private function listNamesById(int $companyId, array $ids): array {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) return [];
        /** @var \App\Repository\ListGroupRepository $lgRepo */
        $lgRepo = $this->repos->getRepository(ListGroup::class);
        $rows = $lgRepo->findBy(['id' => $ids]);
        $map = [];
        foreach ($rows as $lg) {
            if ($lg->getCompany()?->getId() !== $companyId) continue;
            $map[(int)$lg->getId()] = (string)$lg->getName();
        }
        return $map;
    }

}
