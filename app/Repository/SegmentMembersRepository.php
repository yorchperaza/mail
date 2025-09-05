<?php
declare(strict_types=1);

namespace App\Repository;

use MonkeysLegion\Repository\EntityRepository;
use App\Entity\SegmentMembers;

/**
 * @extends EntityRepository<SegmentMembers>
 */
class SegmentMembersRepository extends EntityRepository
{
    /** @var non-empty-string */
    protected string $table       = 'segmentmembers';
    protected string $entityClass = SegmentMembers::class;

    // ──────────────────────────────────────────────────────────
    //  Typed convenience wrappers (optional)
    //  Keep them if you like the stricter return types; otherwise
    //  feel free to delete them and rely on the parent methods.
    // ──────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $criteria
     * @return SegmentMembers[]
     */
    public function findAll(
        array $criteria = [],
        bool  $loadRelations = true
    ): array {
        /** @var SegmentMembers[] $rows */
        $rows = parent::findAll($criteria, $loadRelations);
        return $rows;
    }

    /**
     * @param array<string,mixed> $criteria
     */
    public function findOneBy(
        array $criteria,
        bool  $loadRelations = true
    ): ?SegmentMembers {
        /** @var ?SegmentMembers $row */
        $row = parent::findOneBy($criteria, $loadRelations);
        return $row;
    }
}
