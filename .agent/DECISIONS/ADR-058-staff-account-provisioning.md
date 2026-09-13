# ADR-058 — staff account provisioning from the admin GUI

## Date
2026-09-14

## Context
TASK-027 shipped the students desk (create + CSV import, later plus
automatic logins) and TASK-029 added class creation, but staff logins
never got a writer: admin/teacher/kitchen accounts exist only via
`DemoSeeder`/`PilotSeeder` (or hand SQL). The owner hit the hole
directly: onboarding a real teacher or kitchen operator needs an admin
GUI path with everything an account implies — not just the users row.

## Decision
1. **New desk `GET /admin/staff`** (`AdminStaffController::page`,
   `role:admin`): staff list (admin/teacher/kitchen with homeroom
   classes) + creation form. Nav-linked (desktop + mobile — the
   TASK-037 nav-pin rule: a page the owner can't click didn't ship).
   Student logins are deliberately NOT listed here (students desk
   owns the 1:1 layer, ADR-033/ADR-044).
2. **New endpoint `POST /api/v1/admin/staff`** (`StaffController::store`,
   `auth:sanctum` + `role:admin`, `StaffStoreRequest`): `{name, email
   (lowercased, unique), role ∈ admin|teacher|kitchen, password min-8
   confirmed, class_ids?}`.
3. **Admin-chosen temporary password + forced rotation**
   (`must_change_password=true`): unlike students (one shared,
   classroom-shoutable initial password), staff must NOT share a
   single documented password — the admin picks one per account and
   the existing `EnsurePasswordChanged` wall enforces rotation
   (rotation, not entropy, is the control — ADR-044, unchanged).
4. **Display-once** (the reader-API-key rule): the temporary password
   appears ONLY as the minting response's `account` +
   `account_notice`; never in the staff object, logs, or frames.
5. **Teacher homerooms in the same transaction**: `class_ids` sets
   `classes.teacher_user_id` atomically with the user row (an account
   can never commit without its classes; overwrite is explicit — the
   desk warns that a class keeps exactly one homeroom teacher).
   `class_ids` on a non-teacher role is `422 classes_teacher_only`
   (a client bug, the ClassStoreRequest teacher-guard precedent).
6. **Race honesty** (the StudentController 3.2 rule): advisory
   case-insensitive pre-check + `23000` catch re-checked against the
   email invariant → the same honest 422 `duplicate`; any other
   integrity failure rethrows, never mislabeled.
7. **Create-only** (the ClassController precedent): no update/delete —
   editing staff and re-homing classes are a separate task, stated
   honestly in the docs instead of half-shipped.

## Alternatives Considered
- Reusing the shared student initial password for staff: rejected —
  one documented password across ALL staff logins is a standing
  credential leak with no classroom-shoutability upside (staff are
  onboarded 1:1, not 30 at a time).
- Server-generated random temp passwords (reader-key style):
  rejected — the admin must hand the credential to a human standing
  next to them; a typable chosen password + forced rotation is the
  usable posture at this scale.
- Allowing `role: student` here with an optional `student_id`:
  rejected — it forks the provisioning paths and risks bare
  student-role rows that fail closed on every data wall; one minting
  path per account kind (students desk ↔ staff desk).

## Reasoning
Smallest change that closes the onboarding gap: one request class,
one API controller, one web controller, one view, lang keys, docs —
zero auth-system changes (login, landing, role walls, teacher data
scope, kitchen restriction and realtime scoping all already derive
from `users.role` / `classes.teacher_user_id` and needed no edits).

## Consequences
- Seeder staff accounts remain the demo path (`must_change_password=
  false`); GUI-created staff always rotate — pinned by tests both
  directions (the ADR-044 demo-vs-provisioned split, extended).
- Residuals (honest, out of scope): no staff edit/delete; no staff
  roster realtime frames; API (PAT) access not gated by the rotation
  flag (web-only wall, per ADR-044).

## Status
ACTIVE
