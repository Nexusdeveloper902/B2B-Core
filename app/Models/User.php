<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Platform user (admin, teacher, or student — TASK-025 item 5 adds the
 * student account layer, a 1:1 reference to the students table). Parents
 * intentionally do NOT get accounts in this phase — see
 * .agent/TASKS/TASK-002-core-platform-mvp.md (Phase F simplification).
 */
#[Fillable(['name', 'email', 'password', 'role', 'student_id', 'must_change_password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

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
