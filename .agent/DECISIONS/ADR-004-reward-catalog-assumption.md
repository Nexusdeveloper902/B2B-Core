# ADR-004

## Date
2026-09-02

## Context
Phase D (redemption) requires a reward catalog, but the actual catalog was not
finalized before the task was written.

## Decision
Seed an ASSUMED default reward catalog and flag it explicitly for the project
owner to confirm or replace:
- Canteen discount voucher — 50 pts
- Raffle entry — 20 pts
- Leaderboard shout-out — 5 pts
- Early lunch pass — 15 pts
(the task text proposed the first three; the fourth is a same-shaped addition
to widen the price ladder for demo/testing purposes).

## Alternatives Considered
- Block Phase D until the owner decides — rejected: the earn+spend loop is the
  explicit differentiator and must be demonstrable now.
- Make rewards env-configurable — rejected: they are data, not config; a DB
  table is the right home.

## Reasoning
The spend mechanism is architectural; the catalog is content. Content can be
replaced in the DB at any time without code changes, so an assumed default
unblocks the architecture demo at zero structural risk.

## Consequences
- The catalog is easy to replace (rows in `rewards`).
- ⚠️ OWNER ACTION: confirm/replace the seeded catalog before treating Phase D
  as content-final.

## Status
ACTIVE

## Supersedes
none
