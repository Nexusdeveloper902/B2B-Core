# ADR-024: The pairing desk must show every device-side outcome (and never lie)

## Status
Accepted (2026-09-05, TASK-014)

## Context
Owner's bench report (2026-09-05): "For some strange reason the card
pairing just stops working after you have paired one person, not even f5
solves it, like, the button on the ui says nothing."

Reproduced live in a real browser against a seeded server (RUN-012).
THREE stacked defects, all in the desk surface introduced by TASK-011:

1. **FATAL — the desk script died after the first completed pairing.**
   `var lastSeenUid = {{ $lastCardUid ? json_encode($lastCardUid) : 'null' }}`
   rendered `&quot;62041607&quot;` once any pairing had ever completed
   (Blade's escaped echo HTML-encodes the JSON quotes) →
   `SyntaxError: Unexpected token '&'` → the IIFE never ran → arm
   buttons had NO handlers (clicks did literally nothing) and no polling
   started. Every F5 re-rendered the same broken script — exactly
   "F5 doesn't solve it, the button says nothing". The desk worked
   before the first pairing and broke permanently after it, which is
   why it looked like "pairing one person kills pairing".
2. **The success message was replaced by a lie.** The poll loop treated
   "no pending session + success already seen" as expiry: ~7 s after a
   SUCCESSFUL pairing the green line flipped to red "Window expired
   without a card — arm again.", re-asserted every 3 s forever.
3. **Rejected taps were invisible.** Tapping the burned card (the normal
   bench situation: one test card) answered 422 to the DEVICE only; the
   desk counted down in silence and then claimed expiry — the operator
   never learned why pairing was not completing.

## Decision
1. **JSON literals inside blade `<script>` blocks render UNESCAPED**
   (`{!! json_encode(...) !!}`). json_encode escapes slashes (`\/`) so
   the output cannot break out of the script tag; escaped `{{ }}` echo
   is FORBIDDEN for JS literals here. Pinned by a regression test that
   renders the desk with a completed pairing and asserts the script
   contains `var lastSeenUid = "…";` and zero `&quot;`.
2. **A rejected pair tap is stamped on the armed window**
   (`pending_pairings.last_rejected_uid / _reason / _at`, migration
   000003; stamped inside PairingService::pair()'s transaction, latest
   rejection wins) and exposed as `pending.last_rejection` by the status
   feed. The window stays armed — a genuinely fresh card can still
   complete it. A `no_session` tap (409) stamps nothing: there is no
   window to report on.
3. **The desk state machine is honest by construction:**
   - armed + countdown (green) while the window is live, with the
     rejection note riding along when one exists;
   - success shows ONCE and stays (idle cadence is silent);
   - "window expired" fires only when a window we were actively
     following disappears without a new completion;
   - idle polling is one quiet 15 s watch (cross-tab arm/success
     awareness) instead of the old eternal 3 s expired-flashing loop.
4. **Bilingual remediation in the note itself**: the operator-facing
   text names the rejected UID, the reason, and the fix ("tap a
   DIFFERENT card, or run ./run unpair") — the same remediation the
   device prints on serial (firmware TASK-004), now visible on the
   dashboard where the operator actually is.

## Rationale
- The security model (ADR-020 invariant 2: a paired credential is
  burned; replacement = new pairing) is UNCHANGED — this ADR only makes
  the model's outcomes visible. The desk teaches its own flow, the same
  principle the firmware got in TASK-004.
- Stamping on the armed row (not a separate rejections table) keeps the
  lifecycle simple: the rejection is transient state OF the window —
  when the row is consumed or expired, `pending` stops reporting it,
  exactly like the countdown.
- The regression test renders the page WITH a completed pairing — the
  state TASK-011's tests never exercised (history empty → lastCardUid
  null → the broken branch never rendered in tests, only on the bench).

## Consequences
- The desk works for pairing student #2, #3, … after #1: buttons stay
  alive across reloads, successes persist, rejections explain
  themselves with the ./run unpair remediation.
- HTTP surface: `GET /api/v1/admin/pairing/status` gains
  `pending.last_rejection` (additive, read-only — no new write path;
  the 422 path to the device is unchanged). Schema: 3 nullable columns
  on pending_pairings.
- Poll cadence changes: 2 s while armed, 15 s idle, no restart loops.
- `./run unpair` (TASK-013) is now the DESK-recommended remediation
  path, completing the bench loop: pair → rejected (visible) → unpair
  → re-pair.
