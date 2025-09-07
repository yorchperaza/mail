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
        $this->elog('ctor');
    }

    /* ======================== Utilities ======================== */

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /** Small helper to keep a consistent prefix in logs (still uses error_log). */
    private function elog(string $msg): void
    {
        error_log('[SEG][SVC] ' . $msg);
    }

    /* =================== Matching (in-memory) =================== */

    /**
     * Evaluate the segment definition and return a flat array of contact rows:
     * [
     *   ['id'=>int,'email'=>string,'name'=>?string,'status'=>?string],
     *   ...
     * ]
     */
    public function evaluateSegmentMatches(int $companyId, Segment $segment): array
    {
        $t0 = microtime(true);
        $def = $segment->getDefinition() ?? [];

        $this->elog(sprintf(
            'evaluateSegmentMatches start company_id=%d segment_id=%d filters=%s',
            $companyId,
            (int)$segment->getId(),
            json_encode([
                'status'          => $def['status'] ?? null,
                'email_contains'  => $def['email_contains'] ?? null,
                'gdpr_consent'    => array_key_exists('gdpr_consent', $def) ? (bool)$def['gdpr_consent'] : null,
                'in_list_ids'     => $def['in_list_ids'] ?? [],
                'not_in_list_ids' => $def['not_in_list_ids'] ?? [],
            ], JSON_UNESCAPED_SLASHES)
        ));

        /** @var \App\Repository\ContactRepository $cRepo */
        $cRepo = $this->repos->getRepository(Contact::class);
        /** @var Contact[] $contacts */
        $contacts = $cRepo->findBy(['company_id' => $companyId]);
        $this->elog('contacts loaded n=' . count($contacts));

        // Optional list filters
        $inListIds     = array_values(array_unique(array_map('intval', $def['in_list_ids'] ?? [])));
        $notInListIds  = array_values(array_unique(array_map('intval', $def['not_in_list_ids'] ?? [])));

        $byContactLists = [];
        if ($inListIds || $notInListIds) {
            $this->elog(sprintf(
                'loading list memberships (in=%s not_in=%s)',
                json_encode($inListIds), json_encode($notInListIds)
            ));
            /** @var \App\Repository\ListContactRepository $lcRepo */
            $lcRepo = $this->repos->getRepository(ListContact::class);
            $allLc = method_exists($lcRepo, 'findBy') ? $lcRepo->findBy([]) :
                (method_exists($lcRepo, 'findAll') ? $lcRepo->findAll() : []);
            $this->elog('listcontact rows n=' . count($allLc));

            foreach ($allLc as $lc) {
                $contact = $lc->getContact();
                $list    = $lc->getListGroup();
                if (!$contact || !$list) continue;
                $listCo = $list->getCompany();
                if (!$listCo || (int)$listCo->getId() !== $companyId) continue;

                $byContactLists[(int)$contact->getId()][] = (int)$list->getId();
            }
        }

        $statusEq      = isset($def['status']) ? (string)$def['status'] : null;
        $emailContains = isset($def['email_contains']) ? mb_strtolower((string)$def['email_contains']) : null;
        $gdprConsent   = array_key_exists('gdpr_consent', $def) ? (bool)$def['gdpr_consent'] : null;

        $out = [];
        $skippedInvalidEmail = $skippedBounced = $skippedUnsub = 0;

        foreach ($contacts as $c) {
            if ((int)($c->getCompany()?->getId() ?? 0) !== $companyId) continue;

            $email = trim((string)$c->getEmail());
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $skippedInvalidEmail++; continue; }
            if ($c->getBounced_at() !== null) { $skippedBounced++; continue; }
            if ($c->getUnsubscribed_at() !== null) { $skippedUnsub++; continue; }

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
        $this->elog(sprintf(
            'evaluateSegmentMatches done matches=%d dt_ms=%d skipped_invalid=%d bounced=%d unsub=%d',
            count($out), $dt, $skippedInvalidEmail, $skippedBounced, $skippedUnsub
        ));

        return $out;
    }

    /* ===================== Build & Materialize ===================== */

    /**
     * Create a SegmentBuild row and (optionally) materialize members.
     * Returns [build:SegmentBuild, stats: array].
     *
     * $materialize = true → upsert rows in segmentmembers (diff-based), update segment counters.
     */
    public function buildSegment(Company $company, Segment $segment, bool $materialize = true): array
    {
        $this->elog(sprintf(
            'buildSegment start company_id=%d segment_id=%d materialize=%s',
            (int)$company->getId(), (int)$segment->getId(), $materialize ? '1' : '0'
        ));

        if ((int)($segment->getCompany()?->getId() ?? 0) !== (int)$company->getId()) {
            $this->elog('buildSegment ERR: segment does not belong to company');
            throw new RuntimeException('Segment does not belong to company', 403);
        }

        $t0 = microtime(true);
        $matches = $this->evaluateSegmentMatches($company->getId(), $segment);
        $count   = count($matches);
        $this->elog('matches computed n=' . $count);

        // Create SegmentBuild record
        /** @var \App\Repository\SegmentBuildRepository $sbRepo */
        $sbRepo = $this->repos->getRepository(SegmentBuild::class);

        $build = (new SegmentBuild())
            ->setSegment($segment)
            ->setMatches($count)
            ->setBuilt_at($this->now())
            ->setHash(bin2hex(random_bytes(16)));
        $sbRepo->save($build);
        $this->elog('segmentbuild saved id=' . (int)$build->getId());

        $stats = ['added' => 0, 'removed' => 0, 'kept' => 0];

        if ($materialize) {
            $this->elog('materializeMembers start');
            $stats = $this->materializeMembers($segment, $matches);
            $this->elog(sprintf('materializeMembers done stats=%s', json_encode($stats)));

            // Update segment counters
            /** @var \App\Repository\SegmentRepository $segRepo */
            $segRepo = $this->repos->getRepository(Segment::class);
            $segment->setMaterialized_count($count)->setLast_built_at($this->now());
            $segRepo->save($segment);
            $this->elog('segment counters updated materialized_count=' . $count);
        }

        $dt = (int)round((microtime(true) - $t0) * 1000);
        $this->elog(sprintf('buildSegment done matches=%d dt_ms=%d', $count, $dt));

        return ['build' => $build, 'stats' => $stats, 'matches' => $count];
    }

    /**
     * Compute diff with current materialization and upsert rows in segmentmembers.
     * Returns stats.
     */
    public function materializeMembers(Segment $segment, array $matches): array
    {
        $t0 = microtime(true);
        /** @var \App\Repository\SegmentMembersRepository $smRepo */
        $smRepo = $this->repos->getRepository(SegmentMembers::class);

        // Load existing member contact ids for this segment
        $pdo = $this->qb->pdo();
        $this->elog('load existing segmentmembers...');
        $stmt = $pdo->prepare("SELECT contact_id FROM segmentmembers WHERE segment_id = :sid");
        $stmt->bindValue(':sid', $segment->getId(), \PDO::PARAM_INT);
        $stmt->execute();
        $existingRows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $existing = [];
        foreach ($existingRows as $r) $existing[(int)$r['contact_id']] = true;
        $this->elog('existing members n=' . count($existing));

        // New set from matches
        $newSet = [];
        foreach ($matches as $row) $newSet[(int)$row['id']] = true;

        $toAdd    = array_diff_key($newSet, $existing);   // present in new but not in existing
        $toRemove = array_diff_key($existing, $newSet);   // present in existing but not in new
        $toKeep   = array_intersect_key($existing, $newSet);

        $this->elog(sprintf(
            'diff computed add=%d remove=%d keep=%d',
            count($toAdd), count($toRemove), count($toKeep)
        ));

        // Bulk insert adds
        if ($toAdd) {
            $this->elog('bulk insert adds start...');
            try {
                $now = $this->now()->format('Y-m-d H:i:s');
                $values = [];
                foreach (array_keys($toAdd) as $cid) {
                    $values[] = sprintf('(%d,%d,%s)', (int)$segment->getId(), (int)$cid, $pdo->quote($now));
                }
                $sql = "INSERT INTO segmentmembers (segment_id, contact_id, build_at) VALUES " . implode(',', $values);
                $pdo->exec($sql);
                $this->elog('bulk insert adds ok rows=' . count($toAdd));
            } catch (\Throwable $e) {
                $this->elog('bulk insert adds ERR: ' . $e->getMessage());
                throw $e;
            }
        }

        // Bulk delete removes
        if ($toRemove) {
            $this->elog('bulk delete removes start...');
            try {
                $ids = implode(',', array_map('intval', array_keys($toRemove)));
                $sql = "DELETE FROM segmentmembers WHERE segment_id = " . (int)$segment->getId() . " AND contact_id IN ($ids)";
                $pdo->exec($sql);
                $this->elog('bulk delete removes ok rows=' . count($toRemove));
            } catch (\Throwable $e) {
                $this->elog('bulk delete removes ERR: ' . $e->getMessage());
                throw $e;
            }
        }

        $dt = (int)round((microtime(true) - $t0) * 1000);
        $this->elog(sprintf('materializeMembers done dt_ms=%d', $dt));

        return [
            'added'   => count($toAdd),
            'removed' => count($toRemove),
            'kept'    => count($toKeep),
        ];
    }

    /* ==================== Query past builds ==================== */

    /** Paginated list of past builds for a segment. */
    public function listBuilds(Segment $segment, int $page = 1, int $perPage = 25): array
    {
        $this->elog(sprintf('listBuilds start segment_id=%d page=%d perPage=%d',
            (int)$segment->getId(), $page, $perPage));

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

        $this->elog(sprintf('listBuilds done total=%d returned=%d', $total, count($rows ?: [])));

        return [
            'meta' => [
                'page'       => $page,
                'perPage'    => $perPage,
                'total'      => $total,
                'totalPages' => (int)ceil($total / $perPage),
            ],
            'items' => array_map(fn($r) => [
                'id'      => (int)$r['id'],
                'hash'    => (string)$r['hash'],
                'matches' => (int)$r['matches'],
                'builtAt' => (string)$r['built_at'],
            ], $rows ?: []),
        ];
    }

    /** Helper to shape a SegmentBuild as array. */
    public function shapeBuild(SegmentBuild $b): array
    {
        return [
            'id'      => $b->getId(),
            'hash'    => $b->getHash(),
            'matches' => $b->getMatches(),
            'builtAt' => $b->getBuilt_at()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
