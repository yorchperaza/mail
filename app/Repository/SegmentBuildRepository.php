<?php
declare(strict_types=1);

namespace App\Repository;

use MonkeysLegion\Repository\EntityRepository;
use App\Entity\SegmentBuild;

/**
 * @extends EntityRepository<SegmentBuild>
 */
class SegmentBuildRepository extends EntityRepository
{
    /** @var non-empty-string */
    protected string $table       = 'segmentbuild';
    protected string $entityClass = SegmentBuild::class;

    // ──────────────────────────────────────────────────────────
    //  Typed convenience wrappers (optional)
    //  Keep them if you like the stricter return types; otherwise
    //  feel free to delete them and rely on the parent methods.
    // ──────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $criteria
     * @return SegmentBuild[]
     */
    public function findAll(
        array $criteria = [],
        bool  $loadRelations = true
    ): array {
        /** @var SegmentBuild[] $rows */
        $rows = parent::findAll($criteria, $loadRelations);
        return $rows;
    }

    /**
     * @param array<string,mixed> $criteria
     */
    public function findOneBy(
        array $criteria,
        bool  $loadRelations = true
    ): ?SegmentBuild {
        /** @var ?SegmentBuild $row */
        $row = parent::findOneBy($criteria, $loadRelations);
        return $row;
    }
}
