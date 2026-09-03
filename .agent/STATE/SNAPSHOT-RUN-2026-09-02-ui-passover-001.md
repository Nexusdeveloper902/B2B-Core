# STATE SNAPSHOT — after RUN-2026-09-02-ui-passover-001

## Overall Status
TASK-005 COMPLETED — UI passover shipped to main; CI green on the merge.

## Completed
- Reference design system extracted from B2B-Marketplace @ ecde2d5
  (read-only) with literal values → ADR-013
- Design-token source of truth: public/css/tokens.css (+ fonts.css,
  9 self-hosted woff2, favicon) — consumed by every page
- Shared layout rebuilt (layouts/app.blade.php) + 5 anonymous
  components (panel/stat/stamp/empty/field) → ADR-014
- All four pages migrated: login, teacher, admin, parent timeline
- Dead Laravel welcome scaffold deleted (route / always redirected)
- Mobile audited + fixed at 390px (scrollable ledger tables, topbar
  fit, touch targets)
- WCAG contrast audit 22/22; skip link; focus-visible floor;
  prefers-reduced-motion
- Bilingual README design-system sections (EN/ES)

## In Progress
- Nothing for TASK-005.

## Blocked
- Nothing. (Live NL query from THIS sandbox remains geo-blocked —
  pre-existing OBS-002, unaffected; passes from GitHub runners.)

## Known Problems
- resources/css/app.css is dead Tailwind scaffold (never built; no
  build step exists) — follow-up cleanup, cosmetic only.

## Important Current Facts
- Design tokens sourced from: marketplace repo @ ecde2d5 (value-matched
  1:1; see ARCHITECTURE/value-matched-design-tokens.md for the
  cross-repo maintenance contract)
- Shared layout location: resources/views/layouts/app.blade.php
  (components: resources/views/components/{panel,stat,stamp,empty,field})
- Load-bearing JS class contract: nl-answer / answer-ok / answer-error /
  .hidden (admin dashboard inline script rewrites className)
- UI direction: "The Event Ledger" — paper/pine/ink, hairline rules,
  mono data, 2px radii; do NOT revert to rounded-card kits
- Mobile: .ledger-table min-width 540px inside .ledger-wrap (overflow-x
  scroll); page-level overflow zero at 390px

## Current Main Commit
ee419ee (code + records; this snapshot commit follows it)

## Current Main Status
BUILDABLE — verified on main itself: 108 tests passed (1916
assertions), every dashboard route rendered with all three custom
fonts loaded (document.fonts.check true), GitHub Actions push runs
33708087179 + 33708087625 on ee419ee completed with `success`.

## Active Branches
- main (integration tip)
- feature/TASK-005-core-platform-ui-passover (pushed, == pre-record tip)
- feature/TASK-002-core-platform-mvp (historical)
