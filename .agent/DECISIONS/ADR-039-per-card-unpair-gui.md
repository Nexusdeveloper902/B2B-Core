# ADR-039

## Date
2026-09-08

## Context
Gap D1: unpairing a card was a bulk-only server CLI (`./run unpair`
deletes EVERY card). The pairing desk needed one-click unbind of a
single credential (owner item 12). The freshness semantics of ADR-023
("fresh = the row does not exist") must survive at single-card
granularity, and the destructive reach (tap history dies) must be an
explicit operator decision.

## Decision
`DELETE /api/v1/admin/cards/{card}` (admin-only, session/PAT auth,
CSRF-guarded by the stateful middleware). One transaction in the same
order a DB cascade would apply: delete the card's PresenceEvents,
null the `pending_pairings.card_id` links (history rows SURVIVE as the
audit trail), delete the cards row. Never null `student_id` — a
nulled row would still block re-pairing. The response reports
credential_uid, student_name, events_deleted, history_links_cleared.
GUI: each roster credential renders as a chip (UID + quiet Unpair
button); the click asks for confirmation with copy that says the tap
history is deleted, then DELETEs and repaints the chip ONLY from the
confirmed server answer.

## Alternatives Considered
- Soft-unpair (status=revoked, keep the row) — rejected: violates the
  ADR-023 freshness contract the bench loop relies on (pair → unpair →
  re-pair the SAME credential immediately).
- Unpair without cascade (keep orphaned events) — rejected: events
  reference a dead credential; card_id FK dangles where the sqlite
  pragma may be off; the bulk CLI already cascades.
- Teacher access to unpair — rejected: the card link is an admin
  decision (the desk is admin-only, same as arming).

## Reasoning
Mirroring the bulk command's semantics at single-card granularity
keeps ONE mental model of "fresh"; surviving pending_pairings rows
preserve the pairing audit trail while the credential becomes
immediately re-pairable. The confirm dialog is the human gate; the
role wall is the system gate.

## Consequences
- The bench loop (pair → unpair → re-pair) is now fully drivable from
  the desk (pinned by CardUnpairTest::the_unpaired_credential_can_be_
  paired_again_immediately).
- The bulk CLI stays for testing resets (unchanged, still tested).
- `./run unpair` hint copy in lang files remains accurate as the
  reset-everything escape hatch.

## Status
ACTIVE
