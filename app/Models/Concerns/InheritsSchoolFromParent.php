<?php

namespace App\Models\Concerns;

use App\Support\Tenancy\SchoolInheritedScope;

/**
 * TASK-045 (ADR-064) — an organization-owned CHILD table: the owner is
 * whatever its parent belongs to, so the wall is a subquery through
 * that parent rather than a duplicated column.
 *
 * Implementers declare the path once:
 *
 *   protected static function schoolOwnershipPath(): array
 *   {
 *       return ['student_id', 'students'];
 *   }
 */
trait InheritsSchoolFromParent
{
    public static function bootInheritsSchoolFromParent(): void
    {
        [$foreignKey, $parentTable] = static::schoolOwnershipPath();

        static::addGlobalScope(new SchoolInheritedScope($foreignKey, $parentTable));
    }

    /** @return array{0: string, 1: string} [foreign key, parent table] */
    abstract protected static function schoolOwnershipPath(): array;
}
