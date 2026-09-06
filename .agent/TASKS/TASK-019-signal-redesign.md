# TASK-019-signal-redesign

Owner directive (2026-09-06): "make a complete rehaul of the thing,
keep the functionality (eg rn it still says this Live feed
unavailable — reload the page to see the latest taps.) and basically
re do the whole ui so its more intuitive but i want it to be 0 ai
slop, and make it consistent with the whole styling on
https://github.com/Nexusdeveloper902/B2B-Marketplace"

## Decision (ADR-028 — Signal realignment)

Clone B2B-Marketplace, read its live design system ("Signal", its
TASK-012: dark, editorial, animejs.com-pattern), and rebuild Core's
UI on it 1:1: same token scales (literal hexes), same component
grammar (ruled sections, raised panels, mono data labels, scarlet
CTAs), same motion architecture (vendored anime.js + .js-motion
gated reveals). "0 AI slop" is enforced by construction: Signal has
no gradients, no glassmorphism, no decorative emoji, no
rounded-everything — it is rules, dividers, typography and one
accent.

## Deliverables

1. `public/css/tokens.css`: the marketplace's exact `:root` block
   (scarlet / muted-teal / shadow-grey / tiger-orange scales +
   semantic roles) + Core-only `--shell` / `--tap`. ADR-013's
   value-match restored.
2. `public/css/app.css`: full Signal rewrite — dark ground, blurred
   topbar + underline nav + `<details>` mobile menu, editorial KPI
   columns, ledger tables (mono teal heads), square avatars, role-
   mapped chips/stamps/sum-chips, contact-form field pattern, teal
   focus rings, pairing countdown on Signal roles, mobile card
   tables, reveal states, reduced-motion hard-off. Every JS-facing
   selector kept 1:1.
3. `public/js/motion.js` + `public/js/vendor/anime.esm.min.js`
   (byte-identical to the marketplace's): scroll reveals + staggered
   stat entrance; exits without `.js-motion`; nothing animates under
   reduced-motion.
4. Views: layout gains the motion gate + module + mobile menu;
   sections gain `data-reveal` attributes (progressive enhancement
   only). No structural change anywhere else.
5. README design-system section rewritten for Signal.

## Acceptance

- [x] Existing suite green, ZERO existing tests rewritten (223/3,
      +4 new pins: value-match, dark ground + focus floor, motion
      gating, palette-owned tones)
- [x] `./run quality` PASS (shellcheck enforced) · `./run e2e` 24/24
- [x] Real-browser proof: dark Signal UI on every page; live tap
      arrives without reload (avatar + chip + "just now" + flash);
      teacher row flips live; countdown drains to scarlet is-low;
      mobile 390 px card-stack with ZERO h-scroll (one mode-form
      overflow caught + fixed); reduced-motion: no flag, everything
      visible, no pulses; Spanish renders; offline badge + the
      exact honest hint string; auto-reconnect to Live; zero
      console errors
- [x] Functionality preserved: the honest "Live feed unavailable —
      reload the page to see the latest taps." degrade (EN + ES),
      all TASK-014 pairing semantics, TASK-016 realtime contract,
      device tap protocol — untouched
- [x] Fresh-clone green

## Out of scope (deliberately)

- Light/dark toggle (Signal is dark; the marketplace has no toggle).
- Any route, controller, migration, lang-key or protocol change.
- Marketplace-side changes (that repo is the reference, not the
  target).
