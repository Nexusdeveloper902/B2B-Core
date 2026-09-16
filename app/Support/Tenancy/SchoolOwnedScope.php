<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * TASK-045 (ADR-064) — the organization wall for tables that own a
 * `school_id` column.
 *
 * A GLOBAL scope on purpose: isolation that has to be remembered in
 * twenty controllers is isolation that will be forgotten in the
 * twenty-first. Applying it at the model means index, show, update,
 * delete, search, filter, bulk operations, CSV imports, route-model
 * binding and every future query inherit the wall for free — and a
 * cross-organization id answers 404 instead of leaking a row.
 *
 * Escape hatches are explicit and un-spoofable from a request:
 * `Model::withoutGlobalScope(SchoolOwnedScope::class)` in code, or
 * `CurrentSchool::withoutScoping()` for a whole maintenance block.
 */
class SchoolOwnedScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(CurrentSchool::class);

        if ($context->isSystemWide()) {
            return;
        }

        $column = $model->qualifyColumn('school_id');
        $schoolId = $context->id();

        // NULL is a real data set ("assigned to no organization"), not
        // a wildcard: `where(col, null)` would match nothing and
        // silently blank every pre-feature row.
        $schoolId === null
            ? $builder->whereNull($column)
            : $builder->where($column, $schoolId);
    }
}
