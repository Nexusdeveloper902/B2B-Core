# ADR-020: Card pairing endpoint — two-step arm-then-pair, 45-second window

## Status
Accepted (2026-09-05, TASK-010-card-pairing-endpoint)

## Context

The reader-firmware repository (B2B-Firmware, TASK-001-reader-firmware-mvp)
needs its PAIRING MODE to link a freshly scanned card's UID to a student
record in the backend. The platform previously had NO pairing capability:
the seeder fabricated cards, and `docs/API.md` documented pairing as a
gap. The owner authorized exactly this one addition to b2b-core (firmware
master protocol Section 0.3) — nothing else may change.

Design questions to settle:
1. One-step (client picks the student in the pair request) vs. two-step
   (dashboard arms, device pairs)?
2. How does the device authenticate — and whose identity is trusted?
3. How long should the armed window live?
4. What happens to a credential that is already linked to a card?

## Decision

**Two-step arm-then-pair**, per the authorized spec:

```text
POST /api/v1/admin/students/{id}/arm-pairing   (auth: admin session/PAT)
  → creates a pending_pairings row (45 s window)
POST /api/v1/admin/cards/pair                   (auth: Bearer <reader.api_key>)
  → consumes the most recent unconsumed, unexpired pending pairing,
    creates the cards row, stamps reader_id + consumed_at
```

1. **Why two-step**: a single-step pair request would require the device
   (or whoever drives it) to know WHICH student the card belongs to —
   either a client-supplied `student_id` on the device plane (an
   untrusted identity input, violating the platform's trust model) or a
   student-selection UI on the reader (firmware scope creep). The two-step
   flow keeps the trust split clean: the DESK decides who (admin-authed),
   the DEVICE proves only where the scan happened (reader-key-authed),
   and the short window is the synchronization between the two.
2. **Auth follows the existing dual convention exactly**: arm uses
   `auth:sanctum` + `role:admin` (same as reader-mode relabeling); pair
   uses the `reader.auth` Bearer-key middleware (same as tap/classify) —
   the path lives under `/admin/` for discoverability, but the identity
   is the reader, never a client-supplied value.
3. **45-second window** (config `presence.pairing_window_seconds`,
   env-overridable `PAIRING_WINDOW_SECONDS`): long enough for an operator
   to arm at the desk and walk to the reader; short enough that a
   forgotten arming cannot hijack a later unrelated scan. Within the
   30–60 s range the spec suggested. Simultaneous arming resolves to the
   most-recent row (the desk flow is sequential by nature; no
   per-student invalidation complexity).
4. **Existing credentials are never reassigned**: if the scanned
   `credential_uid` already has a `cards` row (ANY status — active, lost,
   or revoked), the pair returns 422 "Card already paired" and the
   pending pairing stays armed so the operator can scan a different
   fresh card immediately. A replacement card is a NEW credential by
   design. Races are prevented with the same transaction + `lockForUpdate`
   convention the redemption endpoint uses (PointsService).

Pairing consumption is one-shot: `consumed_at` is stamped on success, and
the successful response returns the student's full name in
`paired_student_name` (the firmware displays/logs it; the tap endpoint's
`student_first_name` stays first-name-only for display brevity — both are
documented in docs/API.md).

## Consequences

- A new transient table, `pending_pairings` (see
  ARCHITECTURE/card-pairing-flow.md for the schema relationships).
- The Hardware Abstraction Principle is preserved: the pair endpoint is a
  plain authenticated HTTP POST that Postman/curl/tests exercise today and
  the ESP32 calls tomorrow with zero further backend changes.
- A dashboard "Pair new card" button (calling arm-pairing) is deliberately
  NOT built here — the endpoints being callable is the required bar; the
  button is recorded as follow-up work.
- Deferred to follow-ups: mass-pairing workflows, reader-scoped arming
  (pairing at a specific reader only), and cleanup jobs for expired
  pending rows (they are inert and tiny; SQLite scale makes them a
  non-issue).
