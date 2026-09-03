# TASK-005 — Core Platform UI Passover

Task ID: TASK-005-core-platform-ui-passover
Status: IN PROGRESS
Run: RUN-2026-09-02-ui-passover-001 (protocol date; executed 2026-09-03)
Branch: feature/TASK-005-core-platform-ui-passover

## Objective

Full visual/UI passover of every page in the core platform app so that it
(a) stops looking like default scaffolding / generic CRUD and looks like a
genuinely designed product, and (b) is visually consistent with the design
system established in the marketplace repository
(https://github.com/Nexusdeveloper902/B2B-Marketplace.git, direction
"The Event Ledger", ADR-002 there).

UI-only: no business logic, routes, schema, or API contract changes.

## Task number determination

`.agent/TASKS/` contained TASK-002 (core platform MVP), TASK-003 (run
script suite), TASK-004 (CI activation). Highest N = 4 → this task is
TASK-005, per protocol Section 0.1 (new separately-actionable objective:
a UI passover, not a continuation of any prior feature task).

## Pre-flight findings (2026-09-03)

- Repo: main @ 065ee59, clean tree, in sync with origin/main.
- Existing views: layouts/app.blade.php (shared layout already exists —
  to be rebuilt, not created), auth/login, teacher/dashboard,
  admin/dashboard, parent/timeline, welcome.blade.php (dead scaffold —
  route `/` redirects to login/dashboard, file unreachable).
- Current styling: public/css/app.css — plain CSS, no build step
  (established TASK-002 approach; blue/dark-navy palette, system font
  stack, rounded corners — functional but generic).
- Tests assert on TEXT content only (names, statuses, labels), never on
  CSS classes → restyling is safe if strings and JS element wiring
  (nl-query-form, nl-question, nl-answer, redeem-form, redeem-student,
  redeem-reward, redeem-result, .mode-form, .mode-select, data-endpoint,
  csrf meta) are preserved.
- Baseline: `./run test` → 108 passed, 1 skipped (live-LLM opt-in).
  Baseline screenshots captured (login, admin, teacher, parent, mobile)
  before any change.
- Reference repo: ACCESSIBLE — cloned read-only at commit ecde2d5.

## Commits

## Commit — (pending)

Pending first commit: ADR-013 + design tokens.

## Remaining work

- Everything (run just started).
