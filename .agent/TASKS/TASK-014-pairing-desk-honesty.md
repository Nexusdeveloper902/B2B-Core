# TASK-014-pairing-desk-honesty

Owner's bench report (2026-09-05): "For some strange reason the card
pairing just stops working after you have paired one person, not even
f5 solves it, like, the button on the ui says nothing."

Live-browser reproduction (RUN-012) exposed THREE stacked desk defects:
(1) FATAL — Blade's escaped echo turned the desk script's
`lastSeenUid` JSON literal into `&quot;…&quot;` after the FIRST
completed pairing → SyntaxError → dead buttons + no polling on every
reload (the literal user report); (2) the success line was overwritten
~7 s after a good pairing by a false "Window expired" that re-lied
every 3 s forever; (3) rejected taps (422 already_paired — the normal
one-test-card bench situation) were never shown at the desk.

## Deliverables

1. `resources/views/admin/pairing.blade.php` — script JSON literals via
   unescaped echo (regression-pinned), rejection note server-rendered
   (`data-rejection-note` + inline on the armed line), state machine
   rewrite: 2 s ACTIVE polling while armed / 15 s quiet IDLE watch;
   success shows once and STAYS; "expired" only for a window actively
   followed that vanished without a completion; no restart loops.
2. Migration `2026_09_05_000003_add_rejection_columns_to_pending_pairings_table`
   (`last_rejected_uid/_reason/_at`, nullable) + PendingPairing
   fillable/casts.
3. `PairingService::pair()` — stamps the rejection on the locked armed
   row inside the existing transaction (latest wins; window stays
   armed); `no_session` taps stamp nothing (no row to own them).
4. `PairingStatusController` — `pending.last_rejection`
   {card_uid, reason, at} | null; `AdminPairingController` — pre-rendered
   note for F5 mid-window.
5. Lang keys EN/ES: `pairing_rejected` (with the fresh-card /
   `./run unpair` remediation) + `pairing_reason_already_paired`.
6. Tests: +3 PairingStatusTest (stamp + feed + window-stays-armed +
   latest-wins + still-pairable; no-session path), +3
   AdminPairingDeskTest (server-rendered note EN, ES translation, and
   THE script-syntax regression test with a completed pairing present),
   JSON-structure needle for `last_rejection`; DocumentationTest needle
   `last_rejection` in API.md EN/ES.
7. Docs: API.md + API.es.md status section documents
   `pending.last_rejection` + the 409-stamps-nothing boundary;
   card-pairing-flow.md FK row + invariant 6.
8. `.agent` records: ADR-024, this task file, RUN-2026-09-05-core-012
   + ledger, STATE snapshot, PROJECT.md facts.

## Acceptance

- [x] Live-browser proof (pre-fix repro + post-fix full flow): desk
      boots with history present; success line persists ≥18 s; the
      rejected tap shows uid + reason + remediation within one poll;
      F5 keeps the note; the same window completes with a fresh card
- [x] `var lastSeenUid = "…"` renders unescaped — regression test with
      a completed pairing (the case TASK-011 tests never rendered)
- [x] 6 new tests; suite = 174 passed / 3 skipped (was 168)
- [x] `./run quality` PASS · `./run e2e` 22/22
- [x] API.md + API.es.md parity; DocumentationTest needles green
- [x] No new write paths (the 422 device answer is unchanged; the
      stamp rides the existing pair transaction); device protocol
      untouched

## Out of scope (deliberately)

- Firmware changes: the device already prints its own 422 remediation
  (TASK-004); the desk note now mirrors it where the operator is.
- A dedicated rejections feed/table: the stamp belongs to the armed
  window's lifecycle (consumed/expired rows stop reporting it).
- Streaming (SSE/websocket) status: 2 s/15 s polling is the desk's
  established cadence.
