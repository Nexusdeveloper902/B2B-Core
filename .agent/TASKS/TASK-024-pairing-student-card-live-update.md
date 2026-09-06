# TASK-024-pairing-student-card-live-update (OPEN — not started)

Discovered during the TASK-023 session while tracing the "realtime
websockets in the pair cards window" request (2026-09-06).

## Finding

The WebSocket pairing channel the request asks for ALREADY EXISTS
(TASK-020 / ADR-029, verified in the repo — no implementation
needed there): `realtime:serve` broadcasts `pairing` frames on
arm / consume / reject (signature over `pending_pairings` row
data), the desk boots `realtime.js` from `#pairing-realtime
[data-realtime]`, and `applyStatus()` renders the backend payload
(status strip, countdown, recent-history table). The paired-card
success line already trusts backend data only (WS frame or the
status-endpoint poll fallback) — no optimistic "paired" paint
exists in the page script.

## Remaining gap

The desk's STUDENTS table (`current_card` column) is SSR-frozen:
after a card is paired, the success line + history update
immediately, but the student's row still shows the old card state
until F5. "After a card is paired to a student it shows
immediately" is not fully true for the student↔card link itself.

## Suggested shape (for the implementing agent — verify first)

- Extend `PairingService::statusPayload()` `recent_pairings[]`
  (and `last_pairing`) with `student_id` — backend truth needed
  to target `tr[data-student-row="<id>"]`. Consumers of the shape
  to re-check: `RealtimePairing::payload()`, `PairingStatusTest`,
  `RealtimePairingTest`, `RealtimeServerTest`, `FullJourneyTest`.
- In the desk script, on a backend-confirmed new completion only,
  rewrite that row's current-card cell from the payload UID.
  Never render a client-invented UID (backend-trust rule).
- Keep WS primary + poll fallback unchanged (ADR-029).

## Acceptance (suggested)

- [ ] Pair a card via the device endpoint → the student's
      `current_card` cell shows the new UID with no reload
- [ ] Cell content always comes from backend payload (WS or poll)
- [ ] Shape change covered by payload tests; full suite green
