<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * TASK-030-A (ADR-044) — automatic student login provisioning.
 *
 * Creating or importing a student mints the 1:1 self-service account in
 * the SAME transaction: the account can never exist without its student
 * and a half-applied import can never leave orphaned logins.
 *
 * Convention (established by DemoSeeder, kept verbatim):
 *   email    = {ascii-lower-first-name}@<student_email_domain>
 *   password = <student_initial_password> (shared, documented value)
 *   flag     = must_change_password=true (forced rotation on first login)
 *
 * Uniqueness is by numeric suffix (maria@…, maria2@…, maria3@…): the
 * first-try address always follows the convention the school already
 * teaches; only true collisions disambiguate.
 *
 * Concurrency honesty (adversarial findings 1.1/1.2): the check-then-
 * create sequence cannot be atomic without serializing enrollment, so
 * unique-violation retries back every write — a lost race degrades to
 * the idempotent answer, never a 500. Bulk imports pre-allocate suffixes
 * in memory (one read) instead of N² existence probes inside the txn.
 */
class StudentAccountService
{
    /**
     * Provision (or return the existing) account for a student.
     *
     * Idempotent: a student that already has an account keeps it — the
     * temporary password is display-once and is never re-issued.
     *
     * @return array{user: User, already: bool}
     */
    public function provisionFor(Student $student): array
    {
        $existing = $student->account()->first();

        if ($existing !== null) {
            return ['user' => $existing, 'already' => true];
        }

        try {
            $user = User::create([
                'name' => $student->name,
                'email' => $this->emailForName($student->name),
                'password' => (string) config('presence.student_initial_password', 'password'),
                'role' => UserRole::Student->value,
                'student_id' => $student->id,
                'must_change_password' => true,
            ]);
        } catch (QueryException $e) {
            // Lost race (double-click backfill, parallel enrollments):
            // whoever won owns the answer — re-read, never 500.
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            $winner = $student->account()->first()
                ?? User::where('email', $this->emailForName($student->name))->first();

            if ($winner === null) {
                throw $e;
            }

            return ['user' => $winner, 'already' => true];
        }

        return ['user' => $user->fresh() ?? $user, 'already' => false];
    }

    /**
     * Provision accounts for a whole import batch with ONE pre-read:
     * suffixes allocate against an in-memory set (seeded from the
     * table, extended per row), so 500 same-slug rows stay linear.
     *
     * @param  Collection<int, Student>  $students
     * @return array<int, string> student_id => email
     */
    public function provisionMany(Collection $students): array
    {
        $domain = (string) config('presence.student_email_domain', 'presence.test');

        $taken = User::query()
            ->where('email', 'like', '%@'.$domain)
            ->pluck('email')
            ->flip()
            ->all();

        $emails = [];

        foreach ($students as $student) {
            $existing = $student->account()->first();

            if ($existing !== null) {
                $emails[$student->id] = $existing->email;
                $taken[$existing->email] = true;

                continue;
            }

            $email = $this->emailForName($student->name, $taken);
            $taken[$email] = true;

            try {
                $user = User::create([
                    'name' => $student->name,
                    'email' => $email,
                    'password' => (string) config('presence.student_initial_password', 'password'),
                    'role' => UserRole::Student->value,
                    'student_id' => $student->id,
                    'must_change_password' => true,
                ]);
            } catch (QueryException $e) {
                // External race (out-of-batch writer won between the
                // pre-read and this insert): fall back to the live
                // allocator, which re-reads the table.
                if (! $this->isUniqueViolation($e)) {
                    throw $e;
                }

                $user = $this->provisionFor($student->refresh())['user'];
                $email = $user->email;
                $taken[$email] = true;
            }

            $emails[$student->id] = $email;
        }

        return $emails;
    }

    /**
     * The login email for a display name, disambiguated until unique.
     *
     * @param  array<string, true>  $reserved  in-memory take-list (bulk path)
     */
    public function emailForName(string $name, array $reserved = []): string
    {
        $slug = (string) str(trim($name))->before(' ')->lower()->ascii();
        $slug = preg_replace('/[^a-z0-9._-]/', '', $slug) ?? '';

        if ($slug === '') {
            $slug = 'student';
        }

        $domain = (string) config('presence.student_email_domain', 'presence.test');
        $candidate = "{$slug}@{$domain}";

        $suffix = 2;
        while (isset($reserved[$candidate]) || User::where('email', $candidate)->exists()) {
            $candidate = "{$slug}{$suffix}@{$domain}";
            $suffix++;

            // Paranoia guard: the loop above always terminates on a
            // fresh address; the random tail only fires if a pathological
            // run ever stacks a thousand same-slug accounts.
            if ($suffix > 1000) {
                do {
                    $candidate = $slug.Str::random(6).'@'.$domain;
                } while (isset($reserved[$candidate]) || User::where('email', $candidate)->exists());

                break;
            }
        }

        return $candidate;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return $e->getCode() === '23000';
    }
}
