# TASK-034 — Datum confirms, readers fit, grade→class, live search

## Date opened
2026-09-12

## Origin
Owner (chat, 2026-09-12), four items in one message:
1. "im still seeing the normal confirm modals, did you make new ones?
   if so, im not seeing them, if you didnt, make them" — correct,
   TASK-032 explicitly deferred them; now built.
2. "inspect visually the readers page, the right panel looks cramed
   the buttons are not being displayed properly as they are one on
   top of the other and there is no spacing."
3. Grade/class coupling: "when i select grade 4, it should let me
   select A or B and automatically be 4 A or 4 B."
4. "the search bars through the page should feel realtime instead
   of having to press enter."

## Work
- [x] Shared `x-confirm-modal` (Datum card, alert-box message, quiet
  Cancel + `.btn-danger` Confirm, Esc/backdrop cancel, Tab trap,
  focus return; inline idempotent script = zero asset-cache risk).
  Wired to readers Rotate + pairing Unpair (same copy, same result
  boxes, zero behavior change). `modal_cancel` EN+ES (parity test
  covers it). `window.confirm` absence pinned on both pages.
- [x] Readers right panel: measured 638px table in a 507px panel
  (131px of actions scrolled out — the real defect behind the
  report). Even grid split + flex label input + `.row-actions`
  flex+gap → 0px overflow (measured). Mobile 390px verified:
  stacked cards, full-width buttons with gaps.
- [x] Grade→class coupling (`data-grade` on options incl. live-added
  + renamed classes; filter + auto-first on change/load/reset;
  custom names fall back to showing all).
- [x] Live roster search (debounced 220ms fetch-swap of tbody +
  pagination against the SAME url — no new endpoint; race-guarded;
  replaceState keeps URLs shareable; GET form = no-JS fallback;
  pagination links ride the swap). Other desks' searches were
  already `input`-event live (pairing/teacher/timeline) — verified,
  untouched.
- [x] Browser-proofed every item (modal open/Esc, 16px modal gap
  after fixing a `.nl-answer` specificity tie, coupling matrix,
  search-to-one-row, mobile shot). Full pyramid + quality green.
- [x] Docs: FRONTEND.md + .es.md, this file, run ledger, PROJECT.

## Incidental fix
`.modal-message` margin lost to `.nl-answer`'s later `margin: 0`
(same specificity) — doubled the class. Lesson: check source order,
not just specificity, when composing utilities onto components.

## Status
DONE — delivered 2026-09-12 (uncommitted tree; commit per owner call)
