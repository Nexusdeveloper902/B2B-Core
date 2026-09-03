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

## Commit — 07f8505

Date: 2026-09-03
Branch: feature/TASK-005-core-platform-ui-passover

Summary:
Kickoff: reference design tokens extracted and encoded.

Changes:
- .agent/TASKS/TASK-005-core-platform-ui-passover.md (this record)
- .agent/DECISIONS/ADR-013-design-tokens-value-matched.md (literal token
  values from B2B-Marketplace @ ecde2d5)
- public/css/tokens.css (single design-token source of truth)
- public/css/fonts.css + public/fonts/ (self-hosted Space Grotesk /
  IBM Plex Sans / IBM Plex Mono woff2, copied 1:1 from the reference)
- public/favicon.svg (adopted marketplace favicon)

Verification:
- Reference repo cloned read-only; remote sanitized immediately.

Notes:
- Value-matching approach (no shared package) per task constraints.

## Commit — a8dc712

Date: 2026-09-03
Branch: feature/TASK-005-core-platform-ui-passover

Summary:
app.css rebuilt on the Event Ledger grammar, consuming tokens.css.

Changes:
- public/css/app.css — full rewrite: paper ground, hairline rules, ledger
  tables (mono data), stamps, panels with ink emphasis rules, 2px-radius
  buttons/inputs, skip link, :focus-visible floor, empty states, stat
  strip, 940/620 responsive breakpoints, reduced-motion support.

Verification:
- CSS not yet rendered (pages migrate in later commits); JS-facing class
  names (.nl-answer/.answer-ok/.answer-error/.hidden) preserved.

## Commit — 2bd6725

Date: 2026-09-03
Branch: feature/TASK-005-core-platform-ui-passover

Summary:
Shared layout rebuilt + anonymous Blade components + chrome strings.

Changes:
- resources/views/layouts/app.blade.php (topbar with wordmark/tap mark,
  is-active nav, langswitch, ink footer, skip link)
- resources/views/components/{panel,stat,stamp,empty,field}.blade.php
- lang/en/app.php + lang/es/app.php: +5 symmetric keys
  (skip_to_content, primary_nav, footer_note, demo_credentials, env)

Verification:
- Blade compiled via subsequent page renders; key-parity test passes.

## Commit — be7785c

Date: 2026-09-03
Branch: feature/TASK-005-core-platform-ui-passover

Summary:
Login page migrated onto the shared layout.

Changes:
- resources/views/auth/login.blade.php — Event Ledger panel + field
  components + mono demo-credentials aside.

Verification:
- Browser login performed against it (admin@presence.test) — succeeds.
- BilingualJourneyTest login assertions still pass (108/108 suite).

## Commit — 0a995c1

Date: 2026-09-03
Branch: feature/TASK-005-core-platform-ui-passover

Summary:
Teacher dashboard migrated — ledger tables + status stamps.

Changes:
- resources/views/teacher/dashboard.blade.php — per-class panels (mono
  CLASS label, ink rule, teacher sub), ledger table, stamps, empty
  states, parent-view links without arrow suffix.

Verification:
- Full suite 108 passed; desktop screenshot VLM check PASS.

## Commit — caf0665

Date: 2026-09-03
Branch: feature/TASK-005-core-platform-ui-passover

Summary:
Admin dashboard migrated — stat spec strip + ruled tool panels.

Changes:
- resources/views/admin/dashboard.blade.php — 5-stat strip; READERS /
  NL-QUERY / REDEMPTION / STUDENTS panels; ALL JS ids/data attributes
  preserved verbatim.

Verification:
- Live browser: reader mode change → "Reader mode updated."; redemption →
  honest 422 "Insufficient points"; NL query → honest blocked state
  (sandbox geo-restriction, OBS-002). e2e 22/22.

## Commit — 6c36009

Date: 2026-09-03
Branch: feature/TASK-005-core-platform-ui-passover

Summary:
Parent timeline migrated + dead welcome scaffold removed.

Changes:
- resources/views/parent/timeline.blade.php — 3-stat header strip
  (class/PAE/points), pine scope note, mono event ledger.
- resources/views/welcome.blade.php deleted (unreachable default
  scaffold — route / always redirects).

Verification:
- Full suite 108 passed; desktop + mobile screenshots VLM PASS.

## Commit — da37df5

Date: 2026-09-03
Branch: feature/TASK-005-core-platform-ui-passover

Summary:
Mobile polish after 390px audit — scrollable ledger tables.

Changes:
- public/css/app.css — .ledger-table min-width 540px inside overflow-x
  wrapper (scroll, don't crush), topbar-user 38vw truncation, 12px touch
  targets, full-width tool-form buttons.

Verification:
- DOM check: wrapper scrollable (scrollWidth 540 > clientWidth 308),
  page scrollWidth == viewport (no page overflow), ruled list fits.
- VLM re-check: teacher + admin mobile PASS; EN/ES switcher visible.

## Remaining work

- Merge to main + push + CI verification (in progress).
