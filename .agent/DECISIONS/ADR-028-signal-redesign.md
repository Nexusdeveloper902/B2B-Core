# ADR-028 — UI realignment: the marketplace "Signal" system (TASK-019)

- **Status:** accepted (2026-09-06, TASK-019)
- **Context:** the owner directed a complete rehaul — "keep the
  functionality (eg rn it still says this Live feed unavailable —
  reload the page to see the latest taps.) and basically re do the
  whole ui so its more intuitive but i want it to be 0 ai slop, and
  make it consistent with the whole styling on
  github.com/Nexusdeveloper902/B2B-Marketplace". The marketplace had
  meanwhile redesigned itself into **"Signal"** (its TASK-012, dark
  animejs.com-pattern storefront: shadow-grey 950 ground, scarlet
  primary, muted-teal data, tiger-orange sparing accents, Space
  Grotesk / IBM Plex / Plex Mono, ruled editorial sections, mono data
  labels, anime.js v4 scroll reveals). Core's "Calm Ledger" (ADR-027)
  is a light paper/pine system — same typefaces, different planet:
  the two products no longer read as one brand. The owner's "0 AI
  slop" constraint rules out gradient blobs, glassmorphism clichés,
  decorative emoji and rounded-everything; Signal's editorial
  rules-and-dividers language is the antithesis of all of that.
- **Decision:** Core adopts the marketplace's Signal design system
  **1:1**: `tokens.css` now ships the marketplace's exact token block
  (four palette scales + semantic roles, same literal hexes); `app.css`
  is rebuilt in Signal's component language — dark ground, blurred
  sticky topbar with underline-active nav (and the marketplace's
  no-JS `<details>` mobile menu), raised panels with 2 px accent top
  rules, editorial ruled KPI columns (2 px text rule + 1 px dividers,
  mono teal labels, display values), ledger tables with mono teal
  column heads, square avatars, mono chips/stamps/sum-chips mapped
  onto the Signal roles (present=teal/data, late=tiger-orange/spare,
  absent=scarlet/accent), the contact-form field pattern with teal
  focus rings, and the marketplace's motion architecture: the same
  vendored `anime.esm.min.js` + a trimmed `motion.js` (scroll reveals
  `[data-reveal]`, staggered groups) gated behind the inline
  `.js-motion` flag that is NEVER added under
  `prefers-reduced-motion` — progressive enhancement, SSR-first
  preserved.
- **Restores ADR-013's value-match contract** (which ADR-027 had
  superseded for Core): tokens are again value-matched 1:1 across the
  two repos. ADR-027's Calm Ledger component layer is retired; its
  *functional* UX gains (loading spinners, per-class summary chips,
  countdown bar, demo-chip login, mobile card tables, honest empty
  states) are kept — restyled, not removed.
- **ADR-027 supersession note reversed:** ADR-027 superseded ADR-013's
  value-match for Core; this ADR supersedes THAT supersession. The
  marketplace-side task was to catch up; now Core is the one catching
  up. ADR-014 (shared layout components) remains historical.
- **Consequences:** Core goes dark — a deliberate brand-level
  decision, not per-page theming; there is no light/dark toggle (the
  marketplace has none either). All JS-facing selectors, ids, honest
  states and protocols are untouched: realtime.js, the pairing desk
  state machine (TASK-014), the device tap contract, the honest
  offline degrade strings, first-tap-wins. Existing UI regression
  pins (stat-strip hero sizing, chip tone mapping, data-stack mobile
  cards, countdown sibling, login affordances) all still hold —
  zero existing tests rewritten; +4 new pins lock the value-match,
  the dark ground + focus floor, the motion gating, and the
  palette-owned tone mapping.
