<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * TASK-045 (ADR-064) — the organization wall for tables that do NOT
 * own a `school_id` column and never should.
 *
 * Cards, ledger rows, deposits, redemptions, pending pairings and
 * pending captures each hang off exactly one organization-owned parent
 * (a student, an event, a reader). Storing the owner a second time on
 * the child would create a fact that can drift; instead the child is
 * filtered through its parent's id set.
 *
 * The parent key is always NOT NULL in the schema, so there is no
 * "orphan" row this subquery could hide by accident.
 */
class SchoolInheritedScope implements Scope
{
    public function __construct(
        private readonly string $foreignKey,
        private readonly string $parentTable,
    ) {}

    public function apply(Builder $builder, Model $model): void
    {
        $context = app(CurrentSchool::class);

        if ($context->isSystemWide()) {
            return;
        }

        $schoolId = $context->id();

        $builder->whereIn(
            $model->qualifyColumn($this->foreignKey),
            function ($query) use ($schoolId) {
                $query->select('id')->from($this->parentTable);

                $schoolId === null
                    ? $query->whereNull('school_id')
                    : $query->where('school_id', $schoolId);
            },
        );
    }
}
