# STATE SNAPSHOT — after RUN-2026-09-06-core-015

## Repository state

- Branch: main at the TASK-017 merge commit (feature/
  TASK-017-ux-overhaul merged --no-ff; see `git log` for the
  hash) — records docs commit on top
- Working tree: clean
- Test count: 219 passed / 3 skipped (was 211/3) — +8 UX regressions
- B2B-Firmware: untouched (main @ f325b2e, TASK-007)

## What the backend does now (delta vs RUN-014)

- **The whole UI is redesigned** (TASK-017, ADR-027 "Calm
  Ledger"): same brand palette and typefaces, modern soft-card
  layer — 10 px radii, two-level elevation, sticky blurred topbar,
  filled pill navigation, initials user chip, hero attendance KPI
  (top-left, larger, gradient wash) with 5 stroke-SVG KPI icons,
  per-class summary chips on the teacher dashboard, color-coded
  event chips + initials avatars in the live feed (tone mapping
  lives ONLY in CSS `data-event-type` attribute selectors), amber
  Late stamps, status-card answer boxes, per-button loading
  spinners on every async action (mode change, NL ask, redeem,
  pairing arm), login brand band + password reveal + click-to-fill
  demo chips, pairing countdown progress bar (sibling of
  #pairing-state; drains, red ≤10 s, hides on expiry), mobile
  card-stacked tables (data-label pseudo-labels, no horizontal
  scroll) and 44-48 px touch targets.
- Live feed rows: history stays absolute (SSR-first); live
  arrivals show "just now → N min ago" (client-side arrival
  clock, falls back to wall time after 10 min); rows slide+fade in
  (280 ms). Motion is hard-disabled under prefers-reduced-motion.
- ADR-013's marketplace 1:1 token value-match is superseded for
  Core (brand values kept; radii/shadows/tones/motion are
  Core-only) — recorded in ADR-027.
- Device tap endpoint, realtime protocol (frames/token/poll),
  pairing desk state machine (TASK-014): byte-identical semantics;
  only view-data (pairingWindowSeconds), markup and CSS/JS changed.

## Confirmed facts (cumulative, still current)

- Realtime WebSocket feed (TASK-016/ADR-026) — re-proven live on
  the new markup (badge, prepend, row flip, zero console errors)
- Colombia school time (TASK-015/ADR-025), pairing desk honesty
  (TASK-014/ADR-024), unpair (TASK-013/ADR-023), LAN stateful
  (TASK-012/ADR-022), ADR-020 invariants: all unchanged
- Dev DB re-seeded post-merge → standard demo state (NOTE: reseed
  rotates reader keys + card UIDs; the bench must re-read them)

## Bench expectations after the owner pulls

- `git pull` + `./run serve` → everything looks new immediately
  (hard-refresh once: assets are static files, browsers may cache
  app.css/realtime.js for a page or two).
- On a phone: dashboards stack as cards, nav pills are 44 px,
  buttons 48 px — no more sideways-scrolling tables.

## Open items

- Optional follow-ups (NOT started): dark mode (rejected for now,
  ADR-027); per-class "jump to class" nav when many classes exist;
  marketplace token catch-up (ADR-027 supersession note).
- Deferred (unchanged): GET /api/v1/reader/me; firmware PAIRING.md
  pointers.
