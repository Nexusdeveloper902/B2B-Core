<?php

namespace App\Support\Tenancy;

use App\Models\Reader;
use App\Models\School;
use App\Models\User;

/**
 * TASK-045 (ADR-064) — "which organization is this request acting for?",
 * answered in ONE place.
 *
 * Every organization-scoped query and every organization-owned insert
 * asks this object instead of trusting the client. There are exactly
 * three sources of truth, in this order:
 *
 *  1. An explicit override (`actAs`) — seeders, console commands and
 *     tests that deliberately build data for a named school.
 *  2. The authenticated user (`users.school_id`).
 *  3. The authenticated DEVICE (the reader resolved by the reader.auth
 *     middleware — the Bearer/Pulse-HMAC key IS the reader identity,
 *     ADR-002/ADR-062, so its school is as trustworthy as the key).
 *
 * A request with none of those is SYSTEM-WIDE: console commands,
 * migrations, seeders and the pre-authentication web tier keep seeing
 * the whole database exactly as they did before this feature existed.
 *
 * The system administrator (ADR-064): an ADMIN whose own school_id is
 * NULL belongs to no organization and is therefore unrestricted. That
 * is the one deliberate cross-organization capability, and it is
 * granted by the account's own row — never by a request parameter.
 * Every other role with a NULL school is restricted to the NULL-school
 * data set (the pre-feature rows), so an unassigned teacher can never
 * fall open into "the whole database".
 */
final class CurrentSchool
{
    private ?int $overrideId = null;

    private bool $overrideActive = false;

    private bool $suspended = false;

    /** Act as the given school (or a bare id) for the duration of $callback. */
    public function actAs(School|int|null $school, ?callable $callback = null): mixed
    {
        $previousId = $this->overrideId;
        $previousActive = $this->overrideActive;

        $this->overrideId = $school instanceof School ? (int) $school->id : $school;
        $this->overrideActive = true;

        if ($callback === null) {
            return null;
        }

        try {
            return $callback();
        } finally {
            $this->overrideId = $previousId;
            $this->overrideActive = $previousActive;
        }
    }

    /** Drop an `actAs` override set without a callback. */
    public function forgetOverride(): void
    {
        $this->overrideId = null;
        $this->overrideActive = false;
    }

    /**
     * Run $callback with organization scoping OFF (system-wide).
     *
     * For maintenance work that legitimately spans organizations —
     * seeders, the unpair console command, cross-school reporting.
     * Never reachable from a request parameter.
     */
    public function withoutScoping(callable $callback): mixed
    {
        $previous = $this->suspended;
        $this->suspended = true;

        try {
            return $callback();
        } finally {
            $this->suspended = $previous;
        }
    }

    /**
     * True when no organization filter applies (system admin, console,
     * seeder, or an explicitly suspended scope).
     */
    public function isSystemWide(): bool
    {
        if ($this->suspended) {
            return true;
        }

        if ($this->overrideActive) {
            return false;
        }

        $user = $this->authenticatedUser();

        if ($user !== null) {
            // The system administrator: an admin that belongs to no
            // organization operates across all of them (ADR-064).
            return $user->isAdmin() && $user->school_id === null;
        }

        return $this->readerSchoolId() === false;
    }

    /**
     * The organization id this request acts for, or null.
     *
     * NOTE: `null` is ambiguous on its own — it is both "no filter"
     * and "the NULL-school data set". Always pair it with
     * `isSystemWide()`, which is what the query scopes do.
     */
    public function id(): ?int
    {
        if ($this->isSystemWide()) {
            return null;
        }

        if ($this->overrideActive) {
            return $this->overrideId;
        }

        $user = $this->authenticatedUser();

        if ($user !== null) {
            return $user->school_id !== null ? (int) $user->school_id : null;
        }

        $readerSchoolId = $this->readerSchoolId();

        return $readerSchoolId === false ? null : $readerSchoolId;
    }

    /**
     * Apply the organization filter to a RAW query builder.
     *
     * For the handful of reads that legitimately bypass Eloquent (the
     * realtime channel readers and the leaderboard's grouped join),
     * where the model-level global scope cannot reach.
     */
    public function applyTo(mixed $query, string $column): mixed
    {
        if ($this->isSystemWide()) {
            return $query;
        }

        $schoolId = $this->id();

        return $schoolId === null
            ? $query->whereNull($column)
            : $query->where($column, $schoolId);
    }

    /** The School row this request acts for (null when system-wide or unassigned). */
    public function school(): ?School
    {
        $id = $this->id();

        return $id === null ? null : School::find($id);
    }

    /**
     * The authenticated user WITHOUT booting a guard that is not
     * already resolved.
     *
     * Deliberately defensive: this object is consulted from global
     * query scopes, which fire during the guard's own user lookup on
     * every other model. `auth()->user()` is safe here only because
     * the User model carries no global scope (see ADR-064) — but a
     * guard-less context (console, queue) must never construct one.
     */
    private function authenticatedUser(): ?User
    {
        if (! app()->bound('auth')) {
            return null;
        }

        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * The school of the device authenticated on this request, or
     * `false` when there is no device context at all.
     */
    private function readerSchoolId(): int|null|false
    {
        if (! app()->bound('request')) {
            return false;
        }

        $reader = request()->attributes->get('reader');

        if (! $reader instanceof Reader) {
            return false;
        }

        return $reader->school_id !== null ? (int) $reader->school_id : null;
    }
}
