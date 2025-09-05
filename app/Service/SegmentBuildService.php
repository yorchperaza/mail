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
    )
    {
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
        $def = $segment->getDefinition() ?? [];

        /** @var \App\Repository\ContactRepository $cRepo */
        $cRepo = $this->repos->getRepository(Contact::class);
        /** @var Contact[] $contacts */
        $contacts = $cRepo->findBy(['company_id' => $companyId]);

        // Optional list filters
        $inListIds = array_values(array_unique(array_map('intval', $def['in_list_ids'] ?? [])));
        $notInListIds = array_values(array_unique(array_map('intval', $def['not_in_list_ids'] ?? [])));

        $byContactLists = [];
        if ($inListIds || $notInListIds) {
            /** @var \App\Repository\ListContactRepository $lcRepo */
            $lcRepo = $this->repos->getRepository(ListContact::class);
            $allLc = method_exists($lcRepo, 'findBy') ? $lcRepo->findBy([]) :
                (method_exists($lcRepo, 'findAll') ? $lcRepo->findAll() : []);

            foreach ($allLc as $lc) {
                $contact = $lc->getContact();
                $list = $lc->getListGroup();
                if (!$contact || !$list) continue;
                $listCo = $list->getCompany();
                if (!$listCo || (int)$listCo->getId() !== $companyId) continue;

                $byContactLists[(int)$contact->getId()][] = (int)$list->getId();
            }
        }

        $statusEq = isset($def['status']) ? (string)$def['status'] : null;
        $emailContains = isset($def['email_contains']) ? mb_strtolower((string)$def['email_contains']) : null;
        $gdprConsent = array_key_exists('gdpr_consent', $def) ? (bool)$def['gdpr_consent'] : null;

        $out = [];
        foreach ($contacts as $c) {
            if ((int)($c->getCompany()?->getId() ?? 0) !== $companyId) continue;

            $email = trim((string)$c->getEmail());
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
            if ($c->getBounced_at() !== null) continue;
            if ($c->getUnsubscribed_at() !== null) continue;

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
                $cid = (int)$c->getId();
                $sets = array_values(array_map('intval', $byContactLists[$cid] ?? []));
                if ($inListIds && count(array_intersect($inListIds, $sets)) === 0) $ok = false;
                if ($ok && $notInListIds && count(array_intersect($notInListIds, $sets)) > 0) $ok = false;
            }

            if ($ok) {
                $out[] = [
                    'id' => (int)$c->getId(),
                    'email' => (string)$c->getEmail(),
                    'name' => $c->getName(),
                    'status' => $c->getStatus(),
                ];
            }
        }

        return $out;
    }

    /**
     * Create a SegmentBuild row and (optionally) materialize members.
     * Returns [build:SegmentBuild, stats: array].
     *
     * $materialize = true → upsert rows in segment_members (diff-based), update segment counters.
     */
    public function buildSegment(Company $company, Segment $segment, bool $materialize = true): array
    {
        if ((int)($segment->getCompany()?->getId() ?? 0) !== (int)$company->getId()) {
            throw new RuntimeException('Segment does not belong to company', 403);
        }

        $matches = $this->evaluateSegmentMatches($company->getId(), $segment);
        $count = count($matches);

        // Create SegmentBuild record
        /** @var \App\Repository\SegmentBuildRepository $sbRepo */
        $sbRepo = $this->repos->getRepository(SegmentBuild::class);

        $build = (new SegmentBuild())
            ->setSegment($segment)
            ->setMatches($count)
            ->setBuilt_at($this->now())
            ->setHash(bin2hex(random_bytes(16)));
        $sbRepo->save($build);

        $stats = ['added' => 0, 'removed' => 0, 'kept' => 0];

        if ($materialize) {
            $stats = $this->materializeMembers($segment, $matches);
            // Update segment counters
            /** @var \App\Repository\SegmentRepository $segRepo */
            $segRepo = $this->repos->getRepository(Segment::class);
            $segment->setMaterialized_count($count)->setLast_built_at($this->now());
            $segRepo->save($segment);
        }

        return ['build' => $build, 'stats' => $stats, 'matches' => $count];
    }

    /**
     * Compute diff with current materialization and upsert rows in segment_members.
     * Returns stats.
     */
    public function materializeMembers(Segment $segment, array $matches): array
    {
        /** @var \App\Repository\SegmentMembersRepository $smRepo */
        $smRepo = $this->repos->getRepository(SegmentMembers::class);

        // Load existing member contact ids for this segment
        $pdo = $this->qb->pdo();
        $stmt = $pdo->prepare("SELECT contact_id FROM segment_members WHERE segment_id = :sid");
        $stmt->bindValue(':sid', $segment->getId(), \PDO::PARAM_INT);
        $stmt->execute();
        $existingRows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $existing = [];
        foreach ($existingRows as $r) $existing[(int)$r['contact_id']] = true;

        // New set from matches
        $newSet = [];
        foreach ($matches as $row) $newSet[(int)$row['id']] = true;

        $toAdd = array_diff_key($newSet, $existing);   // contacts present in new but not in existing
        $toRemove = array_diff_key($existing, $newSet);   // contacts present in existing but not in new
        $toKeep = array_intersect_key($existing, $newSet);

        // Bulk insert adds
        if ($toAdd) {
            $now = $this->now()->format('Y-m-d H:i:s');
            $values = [];
            foreach (array_keys($toAdd) as $cid) {
                $values[] = sprintf('(%d,%d,%s)', (int)$segment->getId(), (int)$cid, $pdo->quote($now));
            }
            $sql = "INSERT INTO segment_members (segment_id, contact_id, build_at) VALUES " . implode(',', $values);
            $pdo->exec($sql);
        }

        // Bulk delete removes
        if ($toRemove) {
            $ids = implode(',', array_map('intval', array_keys($toRemove)));
            $sql = "DELETE FROM segment_members WHERE segment_id = " . (int)$segment->getId() . " AND contact_id IN ($ids)";
            $pdo->exec($sql);
        }

        return [
            'added' => count($toAdd),
            'removed' => count($toRemove),
            'kept' => count($toKeep),
        ];
    }

    /** Paginated list of past builds for a segment. */
    public function listBuilds(Segment $segment, int $page = 1, int $perPage = 25): array
    {
        /** @var \App\Repository\SegmentBuildRepository $sbRepo */
        $sbRepo = $this->repos->getRepository(SegmentBuild::class);

        // If your repo has no pagination helpers, do it via QB
        $qb = $this->qb->duplicate();
        $totalRow = $qb->select(['COUNT(*) AS c'])
            ->from('segmentbuild')
            ->where('segment_id', '=', $segment->getId())
            ->fetch();
        $total = (int)($totalRow->c ?? 0);

        $offset = ($page - 1) * $perPage;
        $rows = $this->qb->duplicate()
            ->select(['id', 'hash', 'matches', 'built_at'])
            ->from('segmentbuild')
            ->where('segment_id', '=', $segment->getId())
            ->orderBy('built_at', 'DESC')
            ->limit($perPage)
            ->offset($offset)
            ->fetchAll();

        return [
            'meta' => ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'totalPages' => (int)ceil($total / $perPage)],
            'items' => array_map(fn($r) => [
                'id' => (int)$r['id'],
                'hash' => (string)$r['hash'],
                'matches' => (int)$r['matches'],
                'builtAt' => (string)$r['built_at'],
            ], $rows ?: []),
        ];
    }

    /** Helper to shape a SegmentBuild as array. */
    public function shapeBuild(SegmentBuild $b): array
    {
        return [
            'id' => $b->getId(),
            'hash' => $b->getHash(),
            'matches' => $b->getMatches(),
            'builtAt' => $b->getBuilt_at()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
