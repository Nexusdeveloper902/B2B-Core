# ADR-027 — UX overhaul: "Calm Ledger" design system (TASK-017)

- **Status:** accepted (2026-09-06, TASK-017)
- **Context:** the owner directed "redesign the whole page looking
  online for best practices for good UX bc the experience rn is
  shitty and it should be smooth." Web research (recorded in the RUN
  ledger): dashboard best practice is a hero KPI top-left with a
  strict visual hierarchy of 5-7 KPIs; real-time UX stands on
  freshness + stability + context + trust with 200-400 ms
  transitions; empty states should teach (NN/g); every async action
  needs a visible loading state; mobile needs 44 px touch targets
  and card layouts, not squeezed tables. The current "Event Ledger"
  pass (ADR-013) is honest but flat: equal visual weight everywhere,
  hairline-only surfaces, text-dump status boxes, horizontally
  scrolling tables on phones, dead empty states, zero loading
  feedback on the admin tools.
- **Decision:** evolve the system into **"Calm Ledger"** — same
  brand palette and typefaces (paper/pine/ink + IBM Plex/Space
  Grotesk stay), but a modern soft-card component layer: 10 px card
  radii with a two-level elevation (resting + hover/live), pill
  navigation with an active state, a hero attendance KPI top-left,
  color-coded event chips + initial avatars in the live feed, status
  cards with icons replacing raw text dumps, per-button loading
  spinners on every fetch, empty states that explain and direct,
  mobile card-stacked tables (data-label pattern) instead of
  horizontal scroll, a pairing countdown progress bar, click-to-fill
  demo credentials and a password reveal on login. All motion is
  150-300 ms and fully disabled under `prefers-reduced-motion`.
- **Supersedes (partially) ADR-013:** the tokens are no longer
  value-matched 1:1 with B2B-Marketplace — new tokens (radii,
  shadows, amber/sky tones, motion scale) are Core-only. The shared
  brand values (paper/pine/ink/steel family) are kept; the
  marketplace catching up is a marketplace-side task, not a reason
  to freeze this repo's UX.
- **Rejected — a CSS framework (Tailwind CDN, Bulma):** the stack is
  deliberately no-build, no-NPM, hermetic (ADR-013/ADR-026 ethos);
  a CDN adds a network dependency to a LAN product. Hand-rolled CSS
  stays, now with a fuller token system.
- **Rejected — a full redesign of the realtime protocol:** the feed
  payload, token flow and DOM contract (`.live-list`,
  `#live-badge`, `data-realtime`, `realtime:tap`) are untouched;
  the upgrade is purely presentational on top (chips/avatars are
  derived client-side from the existing payload fields).
- **Rejected — dark mode:** scope discipline; the school-day
  surface is bright classrooms. A dark theme would double the
  token surface for marginal gain now.
- **Consequences:**
  - `public/css/tokens.css` + `public/css/app.css` grow the system:
    radii (6/10 px), elevations, chip tones (attendance=green,
    PAE=amber, recycling=blue, paired=neutral — one mapping, in
    CSS attribute selectors on `data-event-type`, shared by SSR and
    JS-built rows), motion tokens.
  - JS-facing class names are load-bearing and kept: `.nl-answer`
    + `.answer-ok/.answer-error`, `.stamp-*`, `#pairing-state` and
    its dataset keys, `.arm-btn`, `.mode-form/.mode-select`,
    `#nl-query-form/#nl-question`, `#redeem-*`. New UI rides on
    additional elements/classnames, never renamed ones.
  - Loading states are honest: buttons disable + spin only for the
    duration of the actual fetch.
  - Mobile tables become stacked cards below 620 px with
    `data-label` pseudo-labels; touch targets ≥ 44 px.
  - Test pins (text + ids) still pass; new regression tests pin the
    hero KPI, chips/avatars, loading-state attributes, mobile
    data-labels, login affordances and the countdown bar.
