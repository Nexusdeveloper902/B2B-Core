# ADR-031

## Date
2026-09-07

## Context
The owner's recycling spec §3 Case B / §5 / §32 requires the bottle-first
flow: an image captured BEFORE any card must be held until a card tap
resolves it, with a state machine, configurable timeouts, and expiry.
The platform already solved the identical shape for card pairing:
pending_pairings (arm → consume/reject, TTL, clock-jump guard).

## Decision
Persist bottle-first captures in a `pending_captures` table that mirrors
the pending_pairings design: transient rows, `expires_at` TTL
(configurable), the created_at <= now clock-jump guard, and a lazy sweep
that runs on the API write paths (store/associate) — never inside the
WebSocket poll, which stays read-only. Terminal states live in
PendingCaptureState (awaiting_card → validating → accepted | rejected |
failed | expired); the spec's fuller station-side state machine stays in
device code (the backend persists only what restarts must survive).
Capture endpoints ride the same Bearer reader identity as tap/classify.

## Alternatives Considered
- Reuse pending_pairings rows for captures — rejected: different
  lifecycle, different payload, coupled schemas.
- A long-lived 'recycling sessions' table — rejected: the pairing
  precedent proves transient rows + expiry are enough.
- Sweeping from the WS poll loop — rejected: violates the read-only
  socket-server rule the feed's safety argument depends on.

## Reasoning
Pattern reuse over invention; the pairing flow's expiry, clock-jump
guard, and rejection-stamping semantics are already proven by tests. The
device contract (capture → associate) maps 1:1 to the spec's Case B with
one round trip per state change, retry-safe at every step.

## Consequences
- pending_captures rows accumulate transiently; the sweep keeps the set
  bounded (expired rows remain as audit trail).
- Cross-reader association is 403 by construction (reader_id on the row).
- The cost gate (spec §4) holds by ordering: no classifier call until
  the association creates the student-bearing event (pinned by test).

## Status
ACTIVE
