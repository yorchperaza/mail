<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Company;
use App\Entity\Contact;
use App\Entity\ListContact;
use App\Entity\Segment;
use App\Entity\SegmentBuild;
use App\Entity\SegmentMembers;
use DateTimeImmutable;
use DateTimeZone;
use MonkeysLegion\Repository\RepositoryFactory;
use MonkeysLegion\Query\QueryBuilder;
use RuntimeException;

final class SegmentBuildService
{
    public function __construct(
        private RepositoryFactory $repos,
        private QueryBuilder      $qb,
    ) {
        error_log('[SEG][SVC] ctor');
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * Evaluate the segment definition and return a flat array of contact rows:
     * [
     *   ['id'=>int,'email'=>string,'name'=>?string,'status'=>?string],
     *   ...
     * ]
     */
    public function evaluateSegmentMatches(int $companyId, Segment $segment): array
    {
        $t0  = microtime(true);
        $sid = (int)($segment->getId() ?? 0);
        error_log("[SEG][SVC][EVAL] start company_id={$companyId} segment_id={$sid}");

        $def = $segment->getDefinition() ?? [];
        error_log('[SEG][SVC][EVAL] def=' . json_encode($def, JSON_UNESCAPED_SLASHES));

        try {
            /** @var \App\Repository\ContactRepository $cRepo */
            $cRepo = $this->repos->getRepository(Contact::class);
        } catch (\Throwable $e) {
            error_log('[SEG][SVC][EVAL][ERR] getRepository(Contact): ' . $e->getMessage());
            throw $e;
        }

        /** @var Contact[] $contacts */
        $contacts = $cRepo->findBy(['company_id' => $companyId]);
        error_log('[SEG][SVC][EVAL] contacts fetched count=' . count($contacts));

        // Optional list filters
        $inListIds    = array_values(array_unique(array_map('intval', $def['in_list_ids'] ?? [])));
        $notInListIds = array_values(array_unique(array_map('intval', $def['not_in_list_ids'] ?? [])));
        error_log('[SEG][SVC][EVAL] in_list_ids=' . json_encode($inListIds) . ' not_in_list_ids=' . json_encode($notInListIds));

        $byContactLists = [];
        if ($inListIds || $notInListIds) {
            try {
                /** @var \App\Repository\ListContactRepository $lcRepo */
                $lcRepo = $this->repos->getRepository(ListContact::class);
                $allLc = method_exists($lcRepo, 'findBy') ? $lcRepo->findBy([]) :
                    (method_exists($lcRepo, 'findAll') ? $lcRepo->findAll() : []);
                $lcCount = is_countable($allLc) ? count($allLc) : -1;
                error_log("[SEG][SVC][EVAL] list-contact rows fetched count={$lcCount}");
            } catch (\Throwable $e) {
                error_log('[SEG][SVC][EVAL][ERR] getRepository(ListContact): ' . $e->getMessage());
                throw $e;
            }

            foreach ($allLc as $lc) {
                $contact = $lc->getContact();
                $list    = $lc->getListGroup();
                if (!$contact || !$list) continue;
                $listCo = $list->getCompany();
                if (!$listCo || (int)$listCo->getId() !== $companyId) continue;

                $byContactLists[(int)$contact->getId()][] = (int)$list->getId();
            }
            error_log('[SEG][SVC][EVAL] byContactLists built uniqueContacts=' . count($byContactLists));
        }

        $statusEq      = isset($def['status']) ? (string)$def['status'] : null;
        $emailContains = isset($def['email_contains']) ? mb_strtolower((string)$def['email_contains']) : null;
        $gdprConsent   = array_key_exists('gdpr_consent', $def) ? (bool)$def['gdpr_consent'] : null;
        error_log('[SEG][SVC][EVAL] filters status=' . var_export($statusEq, true)
            . ' email_contains=' . var_export($emailContains, true)
            . ' gdpr_consent=' . var_export($gdprConsent, true));

        $out = [];
        $checked = 0;
        foreach ($contacts as $c) {
            ++$checked;
            if ((int)($c->getCompany()?->getId() ?? 0) !== $companyId) continue;

            $email = trim((string)$c->getEmail());
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
//            if ($c->getBounced_at() !== null) continue;
//            if ($c->getUnsubscribed_at() !== null) continue;

            $ok = true;

            if ($statusEq !== null && (string)$c->getStatus() !== $statusEq) $ok = false;

            if ($ok && $emailContains !== null) {
                $em = mb_strtolower((string)$c->getEmail());
                if ($em === '' || !str_contains($em, $emailContains)) $ok = false;
            }

            if ($ok && $gdprConsent !== null) {
                $has = $c->getGdpr_consent_at() !== null;
                if ($gdprConsent !== $has) $ok = false;
            }

            if ($ok && ($inListIds || $notInListIds)) {
                $cid  = (int)$c->getId();
                $sets = array_values(array_map('intval', $byContactLists[$cid] ?? []));
                if ($inListIds && count(array_intersect($inListIds, $sets)) === 0) $ok = false;
                if ($ok && $notInListIds && count(array_intersect($notInListIds, $sets)) > 0) $ok = false;
            }

            if ($ok) {
                $out[] = [
                    'id'     => (int)$c->getId(),
                    'email'  => (string)$c->getEmail(),
                    'name'   => $c->getName(),
                    'status' => $c->getStatus(),
                ];
            }
        }

        $dt = (int)round((microtime(true) - $t0) * 1000);
        error_log("[SEG][SVC][EVAL] done matches=" . count($out) . " checked={$checked} dt_ms={$dt}");
        return $out;
    }

    /**
     * Create a SegmentBuild row and (optionally) materialize members.
     * Returns [build:SegmentBuild, stats: array].
     *
     * $materialize = true → upsert rows in segmentmembers (diff-based), update segment counters.
     */
    public function buildSegment(Company $company, Segment $segment, bool $materialize = true): array
    {
        $t0 = microtime(true);
        $cid = (int)$company->getId();
        $sid = (int)($segment->getId() ?? 0);
        error_log("[SEG][SVC][BUILD] start company_id={$cid} segment_id={$sid} materialize=" . ($materialize ? '1' : '0'));

        if ((int)($segment->getCompany()?->getId() ?? 0) !== $cid) {
            error_log('[SEG][SVC][BUILD][ERR] segment-company mismatch');
            throw new RuntimeException('Segment does not belong to company', 403);
        }

        $matches = $this->evaluateSegmentMatches($cid, $segment);
        $count   = count($matches);
        error_log("[SEG][SVC][BUILD] evaluateSegmentMatches count={$count}");

        // Create SegmentBuild record
        try {
            /** @var \App\Repository\SegmentBuildRepository $sbRepo */
            $sbRepo = $this->repos->getRepository(SegmentBuild::class);
        } catch (\Throwable $e) {
            error_log('[SEG][SVC][BUILD][ERR] getRepository(SegmentBuild): ' . $e->getMessage());
            throw $e;
        }

        $build = (new SegmentBuild())
            ->setSegment($segment)
            ->setMatches($count)
            ->setBuilt_at($this->now())
            ->setHash(bin2hex(random_bytes(16)));
        $sbRepo->save($build);
        error_log('[SEG][SVC][BUILD] build row saved id=' . (int)$build->getId());

        $stats = ['added' => 0, 'removed' => 0, 'kept' => 0];

        if ($materialize) {
            $stats = $this->materializeMembers($segment, $matches);
            error_log('[SEG][SVC][BUILD] materializeMembers ' . json_encode($stats));

            // Update segment counters
            try {
                /** @var \App\Repository\SegmentRepository $segRepo */
                $segRepo = $this->repos->getRepository(Segment::class);
                $segment->setMaterialized_count($count)->setLast_built_at($this->now());
                $segRepo->save($segment);
                error_log('[SEG][SVC][BUILD] segment counters updated materialized_count=' . $count);
            } catch (\Throwable $e) {
                error_log('[SEG][SVC][BUILD][ERR] update segment counters: ' . $e->getMessage());
                throw $e;
            }
        }

        $dt = (int)round((microtime(true) - $t0) * 1000);
        error_log("[SEG][SVC][BUILD] done dt_ms={$dt}");
        return ['build' => $build, 'stats' => $stats, 'matches' => $count];
    }

    /**
     * Compute diff with current materialization and upsert rows in segmentmembers.
     * Returns stats.
     */
    public function materializeMembers(Segment $segment, array $matches): array
    {
        $t0 = microtime(true);
        $sid = (int)($segment->getId() ?? 0);
        error_log("[SEG][SVC][MATERIALIZE] start segment_id={$sid} matches=" . count($matches));

        /** @var \App\Repository\SegmentMembersRepository $smRepo */
        $smRepo = $this->repos->getRepository(SegmentMembers::class);

        // Load existing member contact ids for this segment
        try {
            $pdo  = $this->qb->pdo();
            $stmt = $pdo->prepare("SELECT contact_id FROM segmentmembers WHERE segment_id = :sid");
            $stmt->bindValue(':sid', $sid, \PDO::PARAM_INT);
            $stmt->execute();
            $existingRows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('[SEG][SVC][MATERIALIZE][ERR] load existing members: ' . $e->getMessage());
            throw $e;
        }

        $existing = [];
        foreach ($existingRows as $r) $existing[(int)$r['contact_id']] = true;
        error_log('[SEG][SVC][MATERIALIZE] existing_count=' . count($existing));

        // New set from matches
        $newSet = [];
        foreach ($matches as $row) $newSet[(int)$row['id']] = true;

        $toAdd    = array_diff_key($newSet, $existing);   // present in new but not in existing
        $toRemove = array_diff_key($existing, $newSet);   // present in existing but not in new
        $toKeep   = array_intersect_key($existing, $newSet);

        error_log('[SEG][SVC][MATERIALIZE] toAdd=' . count($toAdd) . ' toRemove=' . count($toRemove) . ' toKeep=' . count($toKeep));

        // Bulk insert adds
        if ($toAdd) {
            try {
                $now    = $this->now()->format('Y-m-d H:i:s');
                $values = [];
                foreach (array_keys($toAdd) as $cid) {
                    $values[] = sprintf('(%d,%d,%s)', $sid, (int)$cid, $pdo->quote($now));
                }
                $sql = "INSERT INTO segmentmembers (segment_id, contact_id, build_at) VALUES " . implode(',', $values);
                $aff = $pdo->exec($sql);
                error_log('[SEG][SVC][MATERIALIZE] inserted=' . (int)$aff);
            } catch (\Throwable $e) {
                error_log('[SEG][SVC][MATERIALIZE][ERR] insert adds: ' . $e->getMessage());
                throw $e;
            }
        }

        // Bulk delete removes
        if ($toRemove) {
            try {
                $ids = implode(',', array_map('intval', array_keys($toRemove)));
                $sql = "DELETE FROM segmentmembers WHERE segment_id = {$sid} AND contact_id IN ($ids)";
                $aff = $pdo->exec($sql);
                error_log('[SEG][SVC][MATERIALIZE] removed=' . (int)$aff);
            } catch (\Throwable $e) {
                error_log('[SEG][SVC][MATERIALIZE][ERR] delete removes: ' . $e->getMessage());
                throw $e;
            }
        }

        $dt = (int)round((microtime(true) - $t0) * 1000);
        error_log("[SEG][SVC][MATERIALIZE] done dt_ms={$dt}");

        return [
            'added'   => count($toAdd),
            'removed' => count($toRemove),
            'kept'    => count($toKeep),
        ];
    }

    /** Paginated list of past builds for a segment. */
    public function listBuilds(Segment $segment, int $page = 1, int $perPage = 25): array
    {
        $t0  = microtime(true);
        $sid = (int)($segment->getId() ?? 0);
        error_log("[SEG][SVC][LIST] start segment_id={$sid} page={$page} perPage={$perPage}");

        /** @var \App\Repository\SegmentBuildRepository $sbRepo */
        $sbRepo = $this->repos->getRepository(SegmentBuild::class);

        // If your repo has no pagination helpers, do it via QB
        $qb = $this->qb->duplicate();
        $total = 0;
        try {
            $totalRow = $qb->select(['COUNT(*) AS c'])
                ->from('segmentbuild')
                ->where('segment_id', '=', $sid)
                ->fetch();
            $total = (int)($totalRow->c ?? 0);
        } catch (\Throwable $e) {
            error_log('[SEG][SVC][LIST][ERR] count: ' . $e->getMessage());
            throw $e;
        }

        $offset = ($page - 1) * $perPage;
        try {
            $rows = $this->qb->duplicate()
                ->select(['id', 'hash', 'matches', 'built_at'])
                ->from('segmentbuild')
                ->where('segment_id', '=', $sid)
                ->orderBy('built_at', 'DESC')
                ->limit($perPage)
                ->offset($offset)
                ->fetchAll();
        } catch (\Throwable $e) {
            error_log('[SEG][SVC][LIST][ERR] fetch rows: ' . $e->getMessage());
            throw $e;
        }

        $dt = (int)round((microtime(true) - $t0) * 1000);
        $n  = is_countable($rows ?? null) ? count($rows) : 0;
        error_log("[SEG][SVC][LIST] done total={$total} items_this_page={$n} dt_ms={$dt}");

        return [
            'meta' => ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'totalPages' => (int)ceil($total / $perPage)],
            'items' => array_map(fn($r) => [
                'id'       => (int)$r['id'],
                'hash'     => (string)$r['hash'],
                'matches'  => (int)$r['matches'],
                'builtAt'  => (string)$r['built_at'],
            ], $rows ?: []),
        ];
    }

    /** Helper to shape a SegmentBuild as array. */
    public function shapeBuild(SegmentBuild $b): array
    {
        $arr = [
            'id'       => $b->getId(),
            'hash'     => $b->getHash(),
            'matches'  => $b->getMatches(),
            'builtAt'  => $b->getBuilt_at()?->format(\DateTimeInterface::ATOM),
        ];
        // Keep this lightweight—shapeBuild can be called a lot.
        return $arr;
    }
}
