# ADR-032

## Date
2026-09-07

## Context
The spec §24–§29 requires realtime frames for recycling state changes
(capture created, validation started, validated, points awarded, reward
redeemed, leaderboard updated), pushed only from committed DB state.
The feed already has two proven channels: `events` rows (tap frames) and
the pairing signature. A broadcast that reflects pre-commit state would
lie to dashboards when a transaction rolls back.

## Decision
Add an append-only `recycling_updates` table. Every state-changing
transaction (award, redemption, capture, association) writes its frame
rows INSIDE the same DB transaction via RecyclingUpdateLog; the rows
become visible exactly at commit. realtime:serve polls rows newer than
its head (third channel, same poll beat) and pushes `recycling` frames
to every authenticated connection; the hello frame carries the recent
snapshot. Payloads are precomputed by the writer; the socket server
joins and computes nothing. Frames carry student names/points — the same
exposure level as tap frames; no card UIDs, so all roles may receive
them (unlike admin-only pairing frames).

## Alternatives Considered
- Broadcasting from the controllers after commit — rejected: couples the
  web tier to the socket process; any process sharing the DB (a test,
  artisan) would not broadcast.
- Polling deposits/ledger directly — rejected: no per-event rows for
  'validation started' or 'leaderboard updated', and payloads would be
  recomputed per connection.

## Reasoning
The events-table channel is the repo's proven decoupling: rows ARE the
broadcast; every writer is a broadcaster; the socket stays read-only and
crash-safe. Recycling frames inherit the entire argument, plus commit-
only visibility by construction.

## Consequences
- recycling_updates grows monotonically (audit trail of feed events).
- Frame payloads are frozen at write time (a name change later does not
  rewrite history) — same semantics as tap frames.
- Leaderboard_updated frames carry a small top-3 snapshot computed
  inside the transaction (school-scale data; documented cost).

## Status
ACTIVE
