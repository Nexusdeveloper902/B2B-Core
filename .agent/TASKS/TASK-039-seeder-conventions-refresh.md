# TASK-039 — Seeder Conventions Refresh

## Objective
Bring DemoSeeder/PilotSeeder (untouched since the early phases) under
the account conventions the platform grew since: the settings-driven
account preset, the real email allocator, display-once honesty for
printed credentials, and the demo-vs-provisioned rotation split.

## Findings (all fixed in the seeders themselves)
1. Hardcoded `presence.test` domain (DemoSeeder slug + print) — a
   school overriding `STUDENT_EMAIL_DOMAIN` got fixtures outside its
   own convention. Now resolved through settings (effective values,
   same as the desks).
2. Hand-rolled first-name slug (DemoSeeder) instead of
   `StudentAccountService::emailForName` — no suffix path, logic fork.
   Now allocates through the real allocator.
3. `User::firstOrCreate(['email' => …])` (DemoSeeder) could hand a
   student somebody else's login on collision. Now idempotent on the
   1:1 link first (keep existing, never re-issue — ADR-044).
4. `Student::firstOrCreate(['name' => …])` ignored the
   `students_name_class_unique` invariant shape. Now matches
   (name, class_id).
5. Printed student emails recomputed the slug (could lie on
   collisions — the OBS-014 truth rule). Now prints the ACTUAL
   `account->email`.
6. Hardcoded `'password'` everywhere — an overridden
   `STUDENT_INITIAL_PASSWORD` left fixtures unusable and the printed
   table false. Now every fixture password equals the effective
   preset (config fallback before settings rows exist).
7. PilotSeeder printed admin + teachers but never the kitchen login it
   seeds. Now printed.
8. PilotSeeder never flushed the settings cache after seeding rows
   (DemoSeeder always did). Now flushed.

## Deliberately unchanged
- Fixtures keep `must_change_password=false` (the ADR-044 demo
  opt-out, pinned both directions by existing tests).
- PilotSeeder's 3-class/24-student deterministic scope and volumes
  (pinned exactly by PilotSeederTest).
- `served` default true (verified — pilot taps need no flag).

## Acceptance Criteria
- `SeederConventionsTest`: overridden preset flows into both seeders'
  fixtures; DemoSeeder reruns mint zero duplicate accounts.
- Full suite green, including the exact-count PilotSeederTest.
