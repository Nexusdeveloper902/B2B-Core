# TASK-032 — UI controls & spacing passover

## Date opened
2026-09-12

## Origin
Owner (chat, 2026-09-12): "do a pass over the whole ui of this app
for a couple of stuff, inconsistent spacing in a lot of places, the
text boxes and dropdown selectors not being styled the same as the
alerts and pop ups, change those and keep the same style, then write
the docs per repo convention" (+ "also .agent docs btw").

## Direction (locked by the request)
- Alerts/pop-ups keep their style. Text boxes + dropdowns move TO
  them, never the reverse.
- Docs per repo convention = `docs/*.md` + `docs/*.es.md` bilingual
  twins (UI-AUDIT/FRONTEND pattern) AND `.agent` records (this file
  + run ledger + PROJECT.md facts).

## Work
- [x] One shared control surface in `public/css/app.css`: `.field`
  inputs, `.bare-*` desk controls, `.searchbox` inputs, file inputs
  (+ `textarea`, previously focus-styled but box-less) share the
  `.nl-answer`/`.notice` language — lowest fill, 1px `--border`,
  2px radius, shared hover/focus/placeholder/disabled/invalid.
  Sizes stay role-based (44px auth / 38px dense desks).
- [x] CSS chevron for dropdowns (`appearance: none` + inline-SVG,
  `padding-right: var(--sp-xl)`); native arrows differ per browser.
- [x] Styled the unstyled: `.check-line` (PAE checkbox),
  `hr.rule` (students desk separators), standalone `.live-empty`
  (pairing idle note — only the feed-row variant existed).
- [x] Spacing: 13 layout inline `style=` attributes across 7 views
  replaced by one-rule classes; `.ledger-wrap + .nl-answer` gap
  (table-led answers touched the table); `.reward-grid` gap
  `sp-lg` → `sp-md` (every other section grid); `.notices`
  undefined `var(--sm)` → `var(--sp-sm)`. Goal-meter `width: %`
  stays inline — the width IS the data.
- [x] Regression pin:
  `DashboardTest::text_boxes_and_dropdowns_share_the_alert_surface_language`
- [x] Full pyramid + quality green; docs EN+ES; agent records.

## Explicitly out of scope
- Native `confirm()` dialogs (unpair, key rotation) are browser
  chrome — a Datum-styled replacement is a product/a11y decision
  (focus trap, keyboard), recorded as follow-up in the docs.
- Alert/pop-up visuals: frozen by request.
- No new backend behavior, no layout changes, no JS-hook renames.

## Acceptance
- [x] Zero layout inline styles in views (one data-driven exception)
- [x] Suite + quality green; pre-existing CSS pins still passing
      (incl. `.live-panel .nl-answer` full-bleed grammar)

## Follow-up (same day, post-delivery owner report: "still looks the same")

- Root cause was NOT the CSS (verified correct on disk): the layout
  served unversioned asset URLs, so browsers kept the stale file.
  Fix: `?v=<filemtime>` on all four head assets in
  `layouts/app.blade.php` (each busts only when it changes) +
  version-string pins in the same DashboardTest.
- Rendered proof: local serve + real browser, admin session —
  versioned URLs, identical computed surfaces, screenshot-verified
  desks. Honest note: sign-in changes least (its controls already
  matched); the visible change lives on the desks.
- Lesson: `pkill -f <pattern>` self-matches the invoking shell —
  inspect `/proc` PIDs first, kill by PID.

## Status
DONE — delivered 2026-09-12 (uncommitted tree; commit per owner call)
