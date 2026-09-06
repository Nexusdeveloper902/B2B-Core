# TASK-017-ux-overhaul

Owner directive (2026-09-06): "Ok you implemented websockets
connection, but i want you to redesign the whole page looking online
for best practices for good ux bc the experience rn is shitty and it
should be smooth."

## Decision (ADR-027 — "Calm Ledger")

Evolve the Event Ledger into a modern soft-card system: same brand
palette/typefaces, new component layer. Research-backed
(see RUN ledger for sources): hero KPI top-left, visual hierarchy,
200-400 ms motion, fresh/stable/context/trust live feed, empty
states that teach, loading feedback on every async action, 44 px
touch targets, mobile card tables.

## Deliverables

1. `tokens.css`: radii, two-level elevation, amber/sky tones, chip
   tones, motion tokens (ADR-013 value-match superseded — recorded).
2. `app.css`: soft cards, pill nav (active state), hero stat,
   KPI cards, event chips + avatars, status cards, button loading
   spinners, stacked mobile tables (data-label), sticky topbar.
3. `realtime.js` (presentational only): rows gain initial avatars,
   event chips, "just now → N min ago" relative time for live
   arrivals (absolute stays for history — SSR-first preserved).
4. Views: layout (nav pills, user chip), login (brand band, demo
   chips click-to-fill, password reveal), admin (hero KPI, loading
   states, status-card answers), teacher (per-class summary chips,
   mobile card stack), pairing desk (countdown progress bar),
   parent timeline (KPI cards, chips).
5. Lang: every new string in EN + ES (parity test green).
6. Tests: existing pins untouched + new UX regressions (hero, chips,
   avatars, loading attrs, data-labels, login, countdown bar).

## Acceptance

- [x] Existing suite green (no test rewritten to fit the UI — the
      UI fits the pins)
- [x] New UX regressions green; lang parity green
- [x] `./run quality` + `./run e2e` PASS (24/24)
- [x] Real-browser proof: pages render smooth, motion respects
      prefers-reduced-motion, mobile viewport (390 px) card layout,
      loading states visible during slow fetch, live feed still
      LIVE (TASK-016 contract intact)
- [x] Device protocol + realtime protocol untouched
- [x] Fresh-clone green

## Out of scope (deliberately)

- Dark mode (ADR-027 rationale).
- Any API/route/migration change.
- The pairing desk's polling cadence (TASK-014-honest state
  machine keeps its semantics; only its visuals change).
