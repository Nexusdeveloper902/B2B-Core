<?php

namespace App\Models\Concerns;

use App\Models\School;
use App\Support\Tenancy\CurrentSchool;
use App\Support\Tenancy\SchoolOwnedScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TASK-045 (ADR-064) — an organization-owned root table.
 *
 * Two guarantees, both automatic:
 *
 *  - READS are walled (SchoolOwnedScope).
 *  - WRITES inherit (§15 creation inheritance): a row created while
 *    acting for an organization is stamped with it. The client never
 *    supplies `school_id` — it is derived from the authenticated
 *    user/device, so "create a student with someone else's
 *    organization id" is not a request that can be expressed.
 *
 * An explicitly-set school_id is respected (seeders, system-admin
 * tooling) — the inheritance fills a blank, it never overrides intent.
 */
trait BelongsToSchool
{
    public static function bootBelongsToSchool(): void
    {
        static::addGlobalScope(new SchoolOwnedScope);

        static::creating(function ($model): void {
            if ($model->school_id !== null) {
                return;
            }

            $context = app(CurrentSchool::class);

            if ($context->isSystemWide()) {
                return; // console/seeder/system admin: leave it explicit
            }

            $model->school_id = $context->id();
        });
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Explicit organization filter for queries that must name their
     * school rather than inherit the request's (reports, the realtime
     * server, cross-organization tooling).
     */
    public function scopeForSchool(Builder $query, School|int|null $school): Builder
    {
        $id = $school instanceof School ? (int) $school->id : $school;

        return $id === null
            ? $query->whereNull($this->qualifyColumn('school_id'))
            : $query->where($this->qualifyColumn('school_id'), $id);
    }
}
