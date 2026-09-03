# RUN RUN-2026-09-02-ui-passover-001

## Task
TASK-005-core-platform-ui-passover

## Agent Role
Full-Stack / Frontend Engineer (Laravel / Blade)

## Result
COMPLETED (pending final merge-to-main CI check at push time)

## Task Number Determined
N = 4 (TASK-002, TASK-003, TASK-004 exist in .agent/TASKS/) → this task
is TASK-005, created as TASK-005-core-platform-ui-passover.md.

## Summary
Full UI passover of the core platform onto the marketplace's "Event
Ledger" design system. Reference repo cloned read-only; literal tokens
extracted and encoded as tokens.css; app.css rebuilt on the ledger
grammar; shared layout + 5 anonymous Blade components; all four pages
(login, teacher, admin, parent) migrated; dead Laravel welcome scaffold
deleted; mobile audited and fixed at 390px. All verification green.

## Reference Repo Access
ACCESSIBLE — https://github.com/Nexusdeveloper902/B2B-Marketplace.git
cloned read-only with the provided PAT (URL sanitized immediately
after clone; PAT never written to any file). Reference pinned at
commit ecde2d5. No writes of any kind to that repository.

## Extracted Design Tokens (if accessible)
- Colors: paper #F3F4F0, surface #FFFFFF, ink #101D18, pine #0A5C38,
  pine-dark #07422A, go #1D9E5F, steel #53615A, line #D7DCD5,
  wash #E9EEE9, alert #B3261E (+ literal supporting values #37453F,
  #CBD5CC, #EEF1EC, #97A29A, #DCEDE2, #E5C0BC, #FBEFEE, ink-band text
  #E8F0EA/#AEBAB2/#98A8A0/#8FA096/#97A69D, rule #263330)
- Typography: Space Grotesk 500/600/700 (display), IBM Plex Sans
  400/400i/500/600 (body 16px/1.62), IBM Plex Mono 400/500 (data);
  self-hosted woff2, no CDN
- Spacing/radius: 2px radius on controls only; shell 1120px with
  clamp(20px,5vw,40px) inline padding; topbar 76px (64px ≤940px);
  buttons 14px 24px padding, 1.5px border; hairline rules instead of
  cards; full literal set in ADR-013

## Changes Made
- Design-token source of truth (tokens.css) + fonts.css + 9 woff2
  fonts + favicon copied from reference
- app.css full rewrite (Event Ledger grammar, responsive, a11y)
- layouts/app.blade.php rebuilt (topbar/nav/langswitch/footer/skip)
- New components: panel, stat, stamp, empty, field
- 4 pages migrated; welcome.blade.php (dead scaffold) deleted
- +5 symmetric lang keys in EN and ES
- README.md / README.es.md: "Design system" section

## Files Changed
- public/css/{tokens,app,fonts}.css, public/fonts/* (9), public/favicon.svg
- resources/views/layouts/app.blade.php
- resources/views/components/{panel,stat,stamp,empty,field}.blade.php
- resources/views/auth/login.blade.php
- resources/views/teacher/dashboard.blade.php
- resources/views/admin/dashboard.blade.php
- resources/views/parent/timeline.blade.php
- resources/views/welcome.blade.php (deleted)
- lang/en/app.php, lang/es/app.php
- README.md, README.es.md
- .agent/ (this run's records: task, ADR-013, ADR-014, architecture
  note, ledger, snapshot, PROJECT.md facts)

## Commits Created
- 07f8505 — TASK-005 kickoff: extract marketplace design tokens (ADR-013), fonts, tokens.css
- a8dc712 — rebuild app.css on Event Ledger grammar, consuming tokens.css
- 2bd6725 — shared Event Ledger layout + anonymous Blade components
- be7785c — login page onto shared layout
- 0a995c1 — teacher dashboard onto shared layout (ledger tables + stamps)
- caf0665 — admin dashboard onto shared layout (stat strip + tool panels)
- 6c36009 — parent timeline onto shared layout + remove dead welcome scaffold
- da37df5 — mobile polish: scrollable ledger tables, topbar fit, touch targets
(+ records commit after this run — see task file for final hashes)

## Branches
- feature/TASK-005-core-platform-ui-passover (from main @ 065ee59)

## Merge Status
- Fast-forward merge to main planned after this record; see state
  snapshot for the final main commit.

## Verification
- Test suite: 108 passed, 1 skipped (live-LLM opt-in) — PASS
- e2e.sh real-HTTP: 22/22 — PASS
- Pint: 108 files PASS
- WCAG contrast audit (22 pairs incl. buttons/stamps/notices/footer):
  22/22 ≥ required ratios — PASS
- VLM visual review, desktop 1280px: login, admin, teacher, parent —
  PASS (layout intact, custom fonts loaded, ledger style, wordmark)
- VLM visual review, mobile 390px (after fix): teacher, admin — PASS
- DOM overflow check at 390px: page scrollWidth == viewport; ledger
  wrappers scrollable (540 > 308) — PASS
- Live interactivity (headless browser): login OK; reader mode change →
  "Reader mode updated."; redemption → honest 422 "Insufficient points:
  15 more needed"; NL query → honest blocked state (OBS-002 sandbox
  geo-restriction; works from unrestricted networks); logout OK; locale
  switch OK (EN + ES screenshots)

## Discoveries
- The admin dashboard's inline JS rewrites className on the answer
  boxes ('nl-answer' + 'answer-ok'/'answer-error'); those class names
  are load-bearing API between the script and app.css.
- resources/css/app.css (Laravel Tailwind scaffold) is dead code —
  never built, no build step exists. Left in place; removal is
  follow-up work (minimize unrelated changes).
- resources/views/welcome.blade.php was dead scaffold (route / always
  redirects) — deleted as part of this task.
- VLM screenshot review of scrollable regions shows "clipped" content
  (overlay scrollbars invisible in headless screenshots) — verify
  scrollability via DOM checks, not screenshots.

## Decisions
- ADR-013: design tokens value-matched from marketplace (literal values)
- ADR-014: shared layout + anonymous component structure
- ARCHITECTURE/value-matched-design-tokens.md: cross-app consistency
  contract and its maintenance implications

## Problems / Blockers
- None blocking. Live NL query remains geo-blocked from this sandbox
  (pre-existing OBS-002, unchanged by this task).

## Remaining Work
- Remove dead resources/css/app.css scaffold (follow-up; cosmetic).
- Optional future: shared design-token package if a third property
  joins the family (explicitly out of scope for this task).

## Next Agent Notes
- All pages extend layouts.app and MUST keep doing so; use the five
  components in resources/views/components/ instead of ad hoc markup.
- Do not restyle away from the Event Ledger grammar (ADR-013/014) and
  do not break the nl-answer/answer-*/hidden class contract.
- If the marketplace palette changes, mirror the values in
  public/css/tokens.css manually (see
  .agent/ARCHITECTURE/value-matched-design-tokens.md).
