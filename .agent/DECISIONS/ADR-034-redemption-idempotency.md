# ADR-034

## Date
2026-09-07

## Context
The spec §20 requires double-submit protection on redemptions. A pure
time-window ("same student + reward within N seconds") heuristic blocks
legitimate repeat purchases (three raffle entries in a minute) while
only approximating the actual failure mode (a retried HTTP request).

## Decision
Two-tier protection. (1) Clients MAY send a request_id (idempotency
key, unique on reward_redemptions): a replay returns the ORIGINAL
answer — same redemption_id, same ledger movement, replayed=true — and
never charges again. (2) Keyless clients fall back to the window
heuristic (configurable, default 10s; reason-shaped 422 'duplicate'),
and the heuristic is skipped entirely when a request_id is present
(distinct keys are intentional purchases by definition).

## Alternatives Considered
- Window-only — rejected: false positives on repeat purchases (the
  stock test caught exactly this before the fix).
- request_id-only — rejected: desk UIs today post without keys; the
  fallback keeps the API safe for them.

## Reasoning
True idempotency keys model the actual retry semantics (same request →
same answer), the exact pattern devices and double-clicking browsers
produce. The heuristic remains only as a safety net for callers that
cannot yet send keys.

## Consequences
- The unique request_id index is the concurrency guard for keyed
  clients; the balance lockForUpdate remains the guard for both.
- Stock decrements roll back on rejected duplicates (no side effects on
  failure).
- API consumers are encouraged (docs) to send a UUID per button press.

## Status
ACTIVE
