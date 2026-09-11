# ADR-044 — automatic student account provisioning + forced first-login password change

## Date
2026-09-11

## Context
TASK-027 shipped the students desk (single create + CSV import) but
deliberately did NOT create login accounts ("a separate, deliberate step
— the seeder's pattern"). The Pulse product rundown requires the
opposite: student creation/import must automatically provision the login
(email convention + predefined initial password + forced change on first
login), so onboarding needs zero manual SQL and credentials never linger
on a shared default. Verified pre-change: `StudentController` contains
zero `User` references; no password-change flow exists anywhere
(no column, no routes, no views).

## Decision
1. **Convention (established, from DemoSeeder)**: email =
   `{ascii-lower-first-name}@presence.test`, initial password =
   `config('presence.student_initial_password')` (default `password`,
   overridable via `STUDENT_INITIAL_PASSWORD`). Uniqueness by numeric
   suffix (`maria2@…`); empty-slug fallback `student`.
2. **Provision inside the same transactions** as student create/import
   (committed-only accounts, like the roster channel's rule), via a new
   `StudentAccountService`. Idempotent: a student that already has an
   account keeps it (`already: true`, no temp password re-issued).
3. **Display-once, like reader API keys**: the creation/import/account
   responses carry the email + temporary password exactly once; roster
   WS frames carry only the email (admin-only channel); nothing else
   (logs, other endpoints, frames) ever carries credential material.
4. **Force rotation**: `users.must_change_password` (default false);
   provisioned accounts set it true. `EnsurePasswordChanged` (`password.changed`
   alias) on the authenticated web group redirects flagged users to
   `GET /password/change` (PUT to rotate: current-password check +
   min-8 confirmed, clears the flag). JSON requests get a bilingual 403
   (`api.password_change_required`) instead of a redirect. Logout,
   locale and the password routes stay reachable.
5. **Demo seeder opts OUT** (`must_change_password=false`): demo logins
   keep working with one tap; real enrollments opt in. Documented
   honestly, not silently.

## Alternatives Considered
- Manual "create account" step per student (TASK-027 status quo):
  rejected — it is exactly the manual-SQL-class chore the product
  rundown kills, and shared-password accounts with no rotation are a
  standing credential leak (cf. OBS-015 residual ledger).
- Random per-student initial passwords: rejected — the school must be
  able to tell a classroom of students their first password out loud;
  one documented value + forced rotation is the usable posture at this
  scale. Rotation enforcement (not password entropy) is the control.
- Full-name-slug emails (`maria.gonzalez@…`): rejected — breaks the
  established convention the seeder, docs and demo chips already teach.
  Suffix disambiguation preserves first-try convention.

## Reasoning
Smallest change that closes the product gap: one service, one column,
one middleware, two web routes, one API endpoint, desk display. No
auth-system replacement, no teacher-provisioning scope creep (separate
task), no API-token semantics change.

## Consequences
- New students can log in immediately; first login forces rotation.
- `StudentManagementTest` import fixtures now also mint users
  (same-slug collisions exercise the suffix path for free).
- Demo accounts differ from provisioned ones by exactly one flag —
  pinned by tests both directions.
- Residual: API (PAT) access is not gated by the flag (web-only wall);
  single-school LAN plaintext and static device keys remain per OBS-015.

## Status
ACTIVE
