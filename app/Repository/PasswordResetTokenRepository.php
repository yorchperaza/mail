<?php
declare(strict_types=1);

namespace App\Repository;

use MonkeysLegion\Repository\EntityRepository;
use App\Entity\PasswordResetToken;

/**
 * @extends EntityRepository<PasswordResetToken>
 */
class PasswordResetTokenRepository extends EntityRepository
{
    /** @var non-empty-string */
    protected string $table       = 'passwordresettoken';
    protected string $entityClass = PasswordResetToken::class;

    // ──────────────────────────────────────────────────────────
    //  Typed convenience wrappers (optional)
    //  Keep them if you like the stricter return types; otherwise
    //  feel free to delete them and rely on the parent methods.
    // ──────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $criteria
     * @return PasswordResetToken[]
     */
    public function findAll(
        array $criteria = [],
        bool  $loadRelations = true
    ): array {
        /** @var PasswordResetToken[] $rows */
        $rows = parent::findAll($criteria, $loadRelations);
        return $rows;
    }

    /**
     * @param array<string,mixed> $criteria
     */
    public function findOneBy(
        array $criteria,
        bool  $loadRelations = true
    ): ?PasswordResetToken {
        /** @var ?PasswordResetToken $row */
        $row = parent::findOneBy($criteria, $loadRelations);
        return $row;
    }
}
