<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Support\Tenancy\CurrentSchool;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Platform user (admin, teacher, or student — TASK-025 item 5 adds the
 * student account layer, a 1:1 reference to the students table). Parents
 * intentionally do NOT get accounts in this phase — see
 * .agent/TASKS/TASK-002-core-platform-mvp.md (Phase F simplification).
 */
#[Fillable(['name', 'email', 'password', 'role', 'student_id', 'must_change_password', 'school_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * TASK-045 (ADR-064) — organization ownership.
     *
     * Unlike every other org-owned model, User carries NO global scope:
     * the session guard resolves the current user by querying this very
     * table, and a scope that asks "who is the current user?" while
     * answering that question recurses forever. User lists are scoped
     * explicitly instead (the staff desk, the homeroom-teacher picker,
     * StaffController) — the places that actually enumerate accounts.
     *
     * Creation inheritance still applies: an account created by a school
     * admin joins that admin's school without the form ever naming it.
     */
    protected static function booted(): void
    {
        static::creating(function (self $user): void {
            if ($user->school_id !== null) {
                return;
            }

            $context = app(CurrentSchool::class);

            if (! $context->isSystemWide()) {
                $user->school_id = $context->id();
            }
        });
    }

    /**
     * TASK-045 (ADR-064) — the explicit organization wall for the
     * places that ENUMERATE accounts (the staff desk, the homeroom
     * teacher picker). User carries no global scope, so every list
     * query must say this out loud.
     */
    public function scopeInCurrentSchool(Builder $query): Builder
    {
        $context = app(CurrentSchool::class);

        if ($context->isSystemWide()) {
            return $query;
        }

        $schoolId = $context->id();

        return $schoolId === null
            ? $query->whereNull($this->qualifyColumn('school_id'))
            : $query->where($this->qualifyColumn('school_id'), $schoolId);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * The platform operator: an admin that belongs to NO organization,
     * and is therefore the one account allowed to work across all of
     * them (ADR-064). A school's own admin is scoped to their school.
     */
    public function isSystemAdmin(): bool
    {
        return $this->isAdmin() && $this->school_id === null;
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin->value;
    }

    public function isTeacher(): bool
    {
        return $this->role === UserRole::Teacher->value;
    }

    /** TASK-025 item 5 — student self-service account role. */
    public function isStudent(): bool
    {
        return $this->role === UserRole::Student->value;
    }

    /** TASK-037 — kitchen (meal-service) staff role, restricted to /kitchen. */
    public function isKitchen(): bool
    {
        return $this->role === UserRole::Kitchen->value;
    }

    /**
     * The students row this account references (1:1; null for
     * admin/teacher accounts). NEVER a second identity — the student
     * row stays the single source of identity truth (spec §11/§30).
     */
    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function classes()
    {
        return $this->hasMany(SchoolClass::class, 'teacher_user_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            // TASK-030-A (ADR-044) — forced first-login rotation flag.
            'must_change_password' => 'boolean',
        ];
    }
}
