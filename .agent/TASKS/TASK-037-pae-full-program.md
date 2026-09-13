# TASK-037 — PAE Full Program

## Objective
Complete the PAE (school feeding) subsystem so it functions as a coherent, operational
program rather than only a tap-to-record meal counter: per-meal enrollment, a real
meal-serving engine (auto meal detection, serving windows, attendance prerequisite,
duplicates, weekday rules), a dedicated kitchen role + realtime glanceable interface,
admin-configurable settings, a reporting system with PDF/CSV export, parent visibility,
natural-language query parity, full bilingual EN/ES coverage, and complete removal of
ENTRY/EXIT functionality.

## Requirements
1. Per-meal enrollment (breakfast + lunch independent flags; admin UI checkboxes; CSV
   bulk import; fresh-reseed migration is acceptable).
2. Meal serving engine: breakfast/lunch only, Mon–Fri America/Bogota, auto meal
   selection from configurable windows, outside-window rejection (flagged, localized),
   prior same-day CLASS_ATTENDANCE prerequisite, per-meal enrollment check
   (meal-specific messages), duplicate detection (flagged, counted once), invalid
   attempts auditable without inflating served-meal metrics.
3. ENTRY/EXIT complete removal (event types, readers type, sessions logic, NL
   functions, lang keys, seeders, docs, tests).
4. Kitchen user role: normal login, restricted to kitchen workflow, /kitchen page with
   realtime tap feed and fullscreen glanceable green/red accept/reject states.
5. Admin settings UI for safe presence/PAE knobs (meal windows, late cutoff, pairing
   window, student email domain, initial password) — persisted and used at runtime.
6. Reporting: general daily/monthly reports with graphics, per-student reports, missed
   meals (current + trends), flagged/out-of-window attempts, PDF + CSV export.
7. Parent visibility: meal badges on the student timeline, distinct from other events.
8. NL query parity with the completed PAE data model (counts, lists, trends, missed
   meals, enrollment, per-student activity, flagged attempts) using the same services.
9. Bilingual EN/ES for every new surface (admin, kitchen, reports, device messages,
   validation messages, badges, seeder output, docs).
10. Documentation: docs/API.md(+es), docs/DATABASE.md(+es), README(+es), .agent records.
11. Comprehensive automated tests for every rule and flow above.

## Constraints
- Laravel 13 + Blade SSR-first + no-build JS doctrine (no npm build step for app code).
- SQLite test engine (:memory:), MariaDB prod — SQL must stay portable across both.
- Realtime = the hand-rolled WS server polling committed rows (no new transport).
- Bilingual parity is test-enforced (LangParity via BilingualJourneyTest + needles).
- DocumentationTest pins API/DATABASE doc coverage — docs must move with code.
- Append-only .agent discipline (RUNS/STATE/DECISIONS append, never rewrite).
- gitleaks + leak tripwires scan every commit (no secrets in tree).

## Acceptance Criteria
See the PAE Full Program specification sections 1–12 (this task's chat brief): the
Definition of Done checklist there is the binding acceptance list.

## Explicitly Out of Scope
- Real hardware changes (B2B-Firmware reader code is a separate repo/task; the tap
  contract stays device-compatible).
- Holiday calendar (weekday-only rule is sufficient per spec).
- Parent self-service authentication (parent view stays admin/teacher stand-in).
- Student photos (explicitly not available — kitchen UI must not depend on them).
- Migrating old `pae_enrolled` data (fresh reseed acceptable per spec).
