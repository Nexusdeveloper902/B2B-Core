# TASK-021-pairing-status-grammar

Owner directive (2026-09-06, after the TASK-020 merge was pushed):
"small fix but you still need the whole protocol for this, im gonna
need you to please fix the Pairing status / Pairing window Live /
Armed for Maria González — 10 s left Now tap a FRESH card on the
reader. little card bc for some reason it looks inconsistent with
the rest of the app"

## Diagnosis

The pairing status panel is a `.live-panel` — an UNPADDED full-bleed
container (TASK-016 grammar: `padding: 0; overflow: hidden`, rows
edge-to-edge with 18px gutters). But the state box TASK-020 inherited
from the TASK-014/TASK-017 era was an `.nl-answer` — an inset,
bordered, rounded, tinted card with `12px 16px` padding and
`margin-top: 14px`. That grammar belongs inside PADDED panels
(the dashboards' NL/redeem answer boxes; the marketplace's
`.form-alert` inside `.contact-form`). Put inside a padding-0
live-panel it produced three competing inset systems in ONE panel:

- `.live-head` text inset: 18px
- the status card text inset: 16px + 1px border (17px), floating
  below the head's rule with a 14px gap — a "card in a card"
- the draining countdown bar: `margin: 14px 0 8px` — side margins
  ZERO, so the 6px bar touched the panel's left/right borders
- the idle note (`p.muted`): default `<p>` margins, no horizontal
  padding — text flush against the panel edges

Measured live (agent-browser): head w=518 @x=651; state box w=518
@x=651 pad 12/16 radius 6 margin-top 14; bar w=518 margin 14/0/8.
Exactly the "little card [that] looks inconsistent" — three gutter
systems where the live feed uses one.

## Decision

- Apply the Signal grammar (ADR-028, no new decision needed — this
  is ADR-028's rule reaching a TASK-020-era element): inside a
  full-bleed live-panel, state = a full-bleed tone STRIP, the same
  pattern as `.live-hint` and the marketplace's `.tap-visual`
  (18px gutters, edge-to-edge tint field, single 1px rule line,
  no personal radius). The draining window meters the panel's full
  width (a battery meter fused under the strip, `is-low` scarlet
  unchanged).
- CSS-ONLY re-presentation, scoped `.live-panel .nl-answer`,
  `.live-panel .answer-ok|error`, `.live-panel .countdown`: the JS
  class contract (`nl-answer answer-ok|answer-error` rewritten by
  `setState()`) and every pinned ID/structure survive byte-identical
  (sibling bar, `data-total`, `hidden` toggles, aria attributes).
- Dashboards' answer boxes (padded panels) keep the inset card
  grammar — scope prevents drift.
- Idle note joins the `.live-empty` row grammar (24px 18px, mono
  13px, muted) via a standalone rule + class in the Blade.

## Acceptance

- [x] Live geometry re-measured: head/state/bar all w=518 @x=651,
      state pad 13/18 margin 0 radius 0, bar margin 0 radius 0,
      stateTopGap 0, barTopGap 0 — ONE gutter system
- [x] All four states screenshotted + VLM-reviewed (armed teal,
      is-low scarlet, expired error strip, idle) — no glitches
- [x] VLM before/after comparison: after judged "one cohesive
      block, consistent with the STUDENTS panel"; before "looks
      like a layout bug or unfinished UI"
- [x] Mobile 390px: no horizontal scroll (390 vs 390), panel fits
- [x] Spanish renders (Ventana de emparejamiento / idle note)
- [x] Zero console errors; realtime badge still Live
- [x] `./run quality` PASS (Pint, shellcheck, docs parity)
- [x] `./run test` 233 passed / 3 skipped (+1 grammar pin test)
- [x] `./run e2e` 24/24
- [x] CI green on GitHub after push (all 13 jobs)
