# TASK-038 — Staff Accounts Desk

## Objective
Close the last hand-written-SQL onboarding hole: teacher, kitchen and
admin logins exist only via seeders today (the admin GUI deliberately
has no user/teacher/kitchen provisioning surface). Give admins a
`/admin/staff` desk + `POST /api/v1/admin/staff` that create any staff
login — including teacher homeroom assignment — with everything that
comes with an account: unique email, temporary password, forced
first-login rotation, role-correct landing and data walls.

## Requirements
1. Admin-only web desk `GET /admin/staff`: staff list (name, email,
   role, homeroom classes) + creation form (name, email, role,
   temporary password + confirmation, homeroom class checkboxes).
2. Admin-only API `POST /api/v1/admin/staff`: roles `admin`,
   `teacher`, `kitchen` (student logins stay on the students desk —
   the 1:1 spine, ADR-033/ADR-044).
3. Admin-chosen temporary password (min 8, confirmed) +
   `must_change_password=true` (the existing rotation wall owns
   enforcement — no new auth machinery).
4. Teachers optionally take homeroom classes (`class_ids`) in the SAME
   transaction (user + `classes.teacher_user_id` commit atomically);
   the desk lists ONLY classes without a teacher (one class, one
   teacher — no full-grade dump); `class_ids` on any other role is an
   honest 422.
5. Display-once credentials: the temporary password appears ONLY in
   the minting response (`account` + `account_notice` — the
   reader-API-key rule, ADR-044); never in logs, frames, or other
   endpoints.
6. Duplicate emails are 422 with a bilingual message, never a silent
   skip and never a 500 on a lost race (the email invariant owns the
   truth — the StudentController 3.2 rule).
7. Bilingual EN/ES for every new surface + lang-key parity; docs move
   with code (API.md/es, README.md/es, postman collection, the
   DocumentationTest needle).
8. Tests: API creation per role, class assignment, duplicate/role/
   classes-guards, role walls, rotation enforcement; web desk render +
   walls. `.agent` records (this file + ADR-058).

## Constraints
- Laravel 13 + Blade SSR-first + no-build JS doctrine (hand-rolled
  fetch, same statefulApi pattern as the students desk).
- SQLite test engine (:memory:), MariaDB prod — SQL must stay portable
  (case-insensitive email check: SQLite UNIQUE is case-sensitive,
  MariaDB utf8mb4_unicode_ci is not).
- Append-only .agent discipline; gitleaks-clean (no secrets in tree —
  the temp password lives only in the HTTP response, never in code).
- Deliberately create-only (the ClassController precedent): editing
  staff and re-homing classes stay a separate task.

## Acceptance Criteria
- An admin creates teacher/kitchen/admin logins from `/admin/staff`
  with zero SQL; the new login works and is forced through
  `/password/change` on first login.
- A teacher created with classes sees exactly those homerooms
  (dashboard scope + realtime scope unchanged — both already derive
  from `classes.teacher_user_id`).
- `POST /api/v1/admin/staff` with a taken email → 422 `duplicate`;
  with `role: student` → 422 validation; with `class_ids` on kitchen
  → 422 `classes_teacher_only`; as teacher/kitchen/guest → 403/login.

## Explicitly Out of Scope
- Editing or deleting staff accounts; re-homing classes outside
  creation; bulk import of staff.
- Student login creation here (students desk owns it).
- New realtime frames for staff (the desk prepends its own row —
  there is no staff roster channel).
