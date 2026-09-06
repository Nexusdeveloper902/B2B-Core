# ADR-036: Mockup-driven redesign — design system "Datum" replaces the Signal value-match

- **Date**: 2026-09-07 (TASK-026, RUN-2026-09-07-core-023)
- **Status**: Accepted
- **Supersedes** (for Core only): the value-match half of ADR-013 and
  ADR-028; ADR-027 was already retired by ADR-028.

## Context

The owner supplied eight complete HTML mockup pages — Sign In, Parent
Timeline, Teacher Dashboard, Admin Dashboard, Pairing Desk, EcoStation
Hub, Rewards Store, Leaderboard — with two rules: "keep functionality
intact" and "document the parts that need functionality that doesn't
exist yet." The mockups define a light sage-ground Material-3-tonal
design (near-black primary, gold points accent, sharp 2px geometry) —
incompatible with the dark "Signal" system Core shared with the
marketplace storefront (ADR-013's literal value-match).

## Decision

1. **The mockup bundle is Core's design source of truth.** Core ships
   the palette/type/spacing verbatim (light "Datum" system); the
   marketplace value-match contract is RETIRED for Core. The two
   products now share a component family, not literal hexes.
2. **Functionality is frozen by contract, not by faith**: every route,
   endpoint, JS id/class hook and test-asserted string survives
   byte-identical where scripts depend on it (realtime.js and
   motion.js are untouched files). The proof is the suite: 274
   tests / 33 e2e checks green after the redesign.
3. **Local-first stays**: the mockups' Google-hosted fonts (Epilogue,
   Manrope) and icon font (Material Symbols Outlined) are downloaded
   once, vendored, and the icon font is subsetted + axis-instanced
   (3.97 MB → 283 KB, ligature-safe). Zero runtime external requests.
   IBM Plex Mono remains the value-match for the mockups' JetBrains
   Mono.
4. **Honesty floor for aspirational UI (extends TASK-014)**: mockup
   elements with no data source are OMITTED — never faked, never dead
   buttons. Every omission is cataloged with its required backend
   surface in `docs/FRONTEND.md` (EN) and `docs/FRONTEND.es.md` —
   the gap ledger the owner asked for. Example: the footer's "All
   Systems Operational" chip shows the real environment instead; the
   parent profile card uses a monogram + real student id, not a fake
   photo/VERIFIED badge.
5. **Read-only additions are in scope, new behavior is not**: two mockup
   pages map to data that already exists, so they ship as thin
   read-only views — `/student/leaderboard` (LeaderboardService) and
   `/admin/ecostation` (deposits + config rates + readers) — with role
   guards and feature tests. Client-side filter/search (parent
   timeline, pairing roster, teacher ledger) is presentation of real
   server-rendered rows.
6. **Grammar tests follow design changes, not the reverse**: the five
   CSS-grammar pins from the Signal era (token value-match, dark
   ground, scarlet focus, stamp/chip Signal roles, tone-strip padding
   literals) are re-pinned onto Datum equivalents with the same
   intent — palette discipline, full-bleed tone strip, edge-to-edge
   countdown meter. The re-pins live in DashboardTest and
   AdminPairingDeskTest with TASK-026 comments.

## Consequences

- `public/css/tokens.css` and `public/css/app.css` are rewritten; view
  markup is restructured; `public/css/fonts.css` adds the new families.
  `realtime.js` and `motion.js` are untouched.
- Icon usage now requires the vendored font: adding an icon means
  re-running the subset recipe (documented in docs/FRONTEND.md §5).
- Future design work should extend the gap ledger rather than
  silently approximating mockup elements with fabricated data.
