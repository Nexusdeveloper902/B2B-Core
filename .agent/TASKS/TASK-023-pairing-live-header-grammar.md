# TASK-023-pairing-live-header-grammar

Owner directive (2026-09-06, interactive session, follow-up to TASK-021):
"please fix only the looks of the pairing status card bc it looks
really inconsistent with the rest of the page, the pairing status
title is like in the very edge, the pairing window sub title has
like nbo styling, it looks really inconsistent from the rest of
the page"

## Diagnosis

TASK-021 fixed the status strip / countdown / idle-note gutters but
left two defects in the same panel's header:

1. `.live-panel { padding: 0; overflow: hidden }` (TASK-016 grammar)
   while `.panel-label` (the "Pairing status" title rendered by the
   `x-panel` component) assumes a padded panel — no horizontal
   padding of its own, `margin: 0 0 16px`. Inside the padding-0
   panel the title sat flush against the panel edges. Same latent
   bug in the dashboard live-feed panel (same component + class).
2. `.live-panel-sub` ("Pairing window") was bare 13px muted body
   text — no label styling, unlike every other panel header on
   the page (mono uppercase label grammar, ADR-028).

## Decision

- CSS-only, scoped to the shared live grammar (one guard in the
  shared path, not one per caller): `.live-panel .panel-label`
  takes the same 18px gutters as every other live row
  (`margin: 0; padding: 14px 18px 13px`, fusing with `.live-head`
  instead of floating on a 16px gap). This heals the pairing card
  AND the dashboard live-feed panel, which shared the bug.
- `.live-panel-sub` joins the Signal label family: 500 12px mono,
  0.06em letter-spacing, uppercase, meta color. Equal specificity
  to the `muted small` utility classes in the Blade, later in the
  file, so no Blade change is needed.
- No new ADR: applying the existing ADR-028 label rule to a
  TASK-020-era element (same reasoning as TASK-021).

## Acceptance

- [x] "Pairing status" title inset at 18px gutters, fused with the head
- [x] "Pairing window" sub styled (mono uppercase), consistent with page
- [x] Zero Blade / JS / string / behavior changes (presentation only)
- [x] Grammar pins added to the existing AdminPairingDeskTest test
- [x] AdminPairingDeskTest + DashboardTest green, Pint clean
