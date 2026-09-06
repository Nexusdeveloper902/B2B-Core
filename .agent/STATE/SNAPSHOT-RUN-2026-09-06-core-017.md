# STATE SNAPSHOT — after RUN-2026-09-06-core-017

## Repository state

- Branch: main at the TASK-019 merge commit (feature/
  TASK-019-signal-redesign merged --no-ff; see `git log` for the
  hash) — records docs commit on top
- Working tree: clean
- Test count: 223 passed / 3 skipped (was 219/3) — +4 Signal pins
- B2B-Marketplace: cloned read-only to /home/z/my-project/repos/
  (the styling reference; NOT modified)
- B2B-Firmware: untouched (main @ f325b2e, TASK-007)

## What the backend does now (delta vs RUN-016)

- **The UI is the marketplace's "Signal" system, 1:1** (TASK-019,
  ADR-028): dark shadow-grey ground, scarlet primary CTAs,
  muted-teal data color (labels, live states, focus rings),
  tiger-orange sparing accents; Space Grotesk / IBM Plex / Plex
  Mono; ruled editorial KPI columns, raised panels with 2 px
  accent rules, ledger tables with mono column heads, square
  avatars, role-mapped chips/stamps/sum-chips; blurred sticky
  topbar with underline-active nav and a no-JS `<details>` mobile
  menu; the SAME vendored anime.js + .js-motion reveal
  architecture, hard-off under reduced-motion. tokens.css carries
  the marketplace's exact token values (ADR-013 value-match
  RESTORED; ADR-027 "Calm Ledger" retired, its functional UX gains
  kept and restyled).
- Functionality byte-preserved: honest offline degrade (badge +
  "Live feed unavailable — reload the page to see the latest
  taps." EN/ES), auto-reconnect, live prepend with "just now",
  teacher live row flip, first-tap-wins, TASK-014 pairing state
  machine + countdown bar, device tap contract, realtime protocol
  (frames/token/poll) — all untouched; realtime.js itself
  unchanged (its row shape is SSR-mirrored by the new CSS).

## Confirmed facts (cumulative, still current)

- All RUN-016 facts hold: realtime feed, Colombia time, pairing
  honesty, unpair, LAN stateful, ADR-020 invariants
- The vendored anime.esm.min.js is byte-identical (md5 fbfdf1a7)
  to the marketplace's file
- Dev DB: standard demo state + 3 bench taps (Maria/Carlos/Diego
  Late 20:35-20:36) + one expired pairing window (reseed rotates
  reader keys + card UIDs; the bench must re-read them)

## Bench expectations after the owner pulls

- `git pull` + `./run serve` → the dashboards are DARK (Signal).
  Hard-refresh once (static app.css/tokens.css may be cached).
- On a phone: cards + 2-up KPI + the bars menu; logout is inside
  the mobile menu.

## Open items

- Optional follow-ups (NOT started): per-class "jump to class" nav
  when many classes exist; dark mode toggle (rejected — Signal is
  dark, marketplace has no toggle).
- Deferred (unchanged): GET /api/v1/reader/me; firmware PAIRING.md
  pointers; marketplace token catch-up is RESOLVED by this run.
