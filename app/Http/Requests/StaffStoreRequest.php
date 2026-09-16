<?php

namespace App\Http\Requests;

use App\Models\School;
use App\Models\SchoolClass;
use App\Rules\OwnedByCurrentSchool;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * TASK-038 — staff account creation from the /admin/staff desk (the
 * owner hit the hole directly: teacher/kitchen/admin logins existed
 * only via seeders — there was no way to create one from the GUI).
 *
 * Staff roles only (admin/teacher/kitchen): student logins are the 1:1
 * account layer minted by the students desk (ADR-033/ADR-044) — a bare
 * student-role user without a student_id would fail closed on every
 * data wall, so the desk refuses to mint one.
 */
class StaffStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role gate happens via role middleware
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // No `unique`/`lowercase` rules here: the address is
            // lowercased in the controller and every duplicate (exact
            // or case variant) flows through the advisory check +
            // email-invariant catch, so the desk always renders the
            // same honest bilingual 422 (the StudentController 3.2
            // rule — and portable: SQLite UNIQUE is case-sensitive,
            // MariaDB utf8mb4_unicode_ci is not).
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', 'string', Rule::in(['admin', 'teacher', 'kitchen'])],
            // Admin-chosen temporary password (minimum 8, confirmed):
            // rotation — not entropy — is the control (ADR-044), and
            // the forced first-login change owns it.
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            // Homeroom assignment only makes sense for teachers; the
            // controller 422s it for any other role (client bug, not a
            // valid assignment).
            'class_ids' => ['sometimes', 'array'],
            'class_ids.*' => ['integer', new OwnedByCurrentSchool(SchoolClass::class)],
            // TASK-045 (ADR-064) — organization placement. A school admin
            // never sends this (and it is IGNORED if they do: the
            // controller derives the school from their own account). Only
            // the system administrator, who belongs to no organization,
            // may name one — the explicit cross-organization capability
            // §15 asks to preserve, granted by the account's own row.
            'school_id' => ['sometimes', 'nullable', 'integer', Rule::exists(School::class, 'id')],
        ];
    }
}
