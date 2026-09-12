# TASK-035 — readers table off the admin dashboard

## Date opened
2026-09-12

## Origin
Owner (chat, 2026-09-12): "remove the readers part from the admin
dashboard, no need for it now."

## Work
- [x] `admin/dashboard.blade.php`: readers hardware panel (table +
  mode forms + result box) removed; NL query panel goes full width;
  mode-form submit JS + `reader_updated` roster repaint listener
  removed; header + KPI comments updated.
- [x] `AdminDashboardController`: dead `readers` query dropped
  (the mode API endpoint itself stays — e2e.sh exercises it, and
  the readers desk's save flow uses the PUT endpoint).
- [x] Dead CSS removed: `.mode-form` / `.mode-select` rules +
  mobile selectors + the selector-inventory comment (dashboard was
  the last consumer). `.tool-form` rules untouched.
- [x] Subtitle updated EN+ES (no more "reader hardware").
- [x] Tests: DashboardTest stats test renamed (controls →
  DontSee), RealtimePassoverTest KPI test renamed (dropped
  `data-reader-row` + roster asserts), stacking test asserts NO
  table on /admin. One self-caught overreach: `assertDontSee`
  on reader names fails — names legitimately appear in live-feed
  contexts; pin the removed artifacts (mode-form, result box,
  data-reader-row) instead of the names.
- [x] Docs: FRONTEND.md + .es.md (coverage table + readers-desk
  section). Full pyramid + quality green.

## Explicitly out of scope
- The mode-only API endpoint (contract + e2e stay).
- `change_mode` / `mode_updated` lang keys (harmless, may serve API
  messages; parity unaffected).

## Follow-up (same day): NL panel spacing

- Owner: "the spacing on that panel looks inconsistent." Measured:
  the bare `<section>` wrapping the full-width NL panel carried no
  margin — 0px joints above and below vs 16px everywhere else (the
  grid class used to supply the rhythm).
- Fix: one `.section-gap` utility (`margin-block: sp-md`, collapse-
  safe) on the section. Re-measured 24/16/16/16 down the page +
  screenshot-verified. Pinned (`section-gap` on /admin HTML).

## Status
DONE — delivered 2026-09-12 (uncommitted tree; commit per owner call)
