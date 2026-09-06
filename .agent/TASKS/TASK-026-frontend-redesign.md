# TASK-026 — Mockup-driven frontend redesign

- **Status**: COMPLETED (2026-09-07, RUN-2026-09-07-core-023) — local commit + merge done; push pending owner PAT (no credentials in this session).
- **Origin**: Owner directive (2026-09-07) — "mind redesigning the front end,
  keep functionality intact, i was thinking kind of like this" + a pasted
  bundle of EIGHT full HTML mockup pages:
  1. Parent View — Jerónimo's Timeline
  2. Rewards & Perks Store
  3. Admin Dashboard — School Today
  4. EcoStation & Recycling Hub
  5. Sign In — Presence Platform
  6. Leaderboard & Class Standings
  7. Teacher Dashboard — Today's Attendance
  8. Pair Cards — Pairing Desk
- **Constraint (owner's words)**: keep functionality intact; document the
  mockup parts that need functionality which does not exist yet.

## What this task IS

- A visual + structural redesign of every Blade view, the layout shell
  (topbar/footer), and the hand-written CSS design system to match the
  owner's mockups: light sage Material-3-tonal palette, Epilogue/Manrope/
  Space Grotesk/mono type scale, 2px-radius "architectural" geometry,
  bento metric cards, ledger tables with event chips, filter pills +
  client-side search, fixed blurred topbar, ops footer.
- New self-hosted webfonts (Epilogue + Manrope variable, latin subsets)
  and the Material Symbols Outlined icon font, subsetted to the icons
  actually used. ZERO runtime requests to Google — local-first stays.
- Two NEW read-only pages that the mockup set includes, built purely on
  existing data (no new backend behavior):
  - `/student/leaderboard` (mockup 6) — LeaderboardService data +
    class standings computed from it.
  - `/admin/ecostation` (mockup 4) — recycling deposits ledger, material
    rate table from config, reader network, capture-image spotlight.
- A gap ledger: docs/FRONTEND.md(+.es) documents EVERY mockup element
  with no backing functionality (photos, advisor contacts, geofence map,
  barcodes/vouchers, milestone tiers, forgot-password, QR redemption,
  simulation modal, crypto-hash footers, telemetry, marketing badges).

## What this task is NOT (rules)

- No route/endpoint changes to existing functionality; no controller
  logic changes beyond additive read-only view methods; no JS file
  rewrites (realtime.js / motion.js byte-stable).
- All test-asserted text, ids, data-* hooks, class contracts that JS
  writes to (live-*, js-tap-*, nl-answer answer-ok|error, countdown,
  mode-form, redeem-*, arm-btn, stamp-*, is-affordable, is-me …) survive.
- Honesty floor (TASK-014 culture): no fabricated data, no fake
  "verified/badges/crypto" theater. Mockup elements without a data
  source are either omitted or rendered as honest planned-state notes,
  and are listed in the gap ledger.

## Acceptance

- `./run ci` 3/3 green (test all, e2e 33/33, quality).
- New feature tests for the two new pages (render + role guards).
- Visual proof: screenshots of every page (EN + an ES sample), zero
  console errors, no horizontal scroll at 390px.
- ADR-036 (palette pivot, ADR-013 value-match retired for Core),
  docs/FRONTEND.md EN/ES with the gap ledger, PROJECT.md/RUN records.
