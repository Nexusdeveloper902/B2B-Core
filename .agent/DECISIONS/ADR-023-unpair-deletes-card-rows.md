# ADR-023: Unpairing = deleting the cards rows (testing reset)

## Status
Accepted (2026-09-05, TASK-013)

## Context
Owner's bench (2026-09-05, pairing now works end-to-end after TASK-007 /
TASK-012): every successful pairing BURNS its credential — ADR-020
invariant 2 ("existing card rows are immutable assignments") makes
`PairingService::pair()` reject any `credential_uid` that already has a
`cards` row, whatever its status. So re-testing the arm-then-pair flow
with the same physical card requires removing that row first, which the
owner did by hand between bench passes. Request: "a little script in
b2b-core that basically unpairs every card (it'll make testing easier),
same protocol."

## Decision
1. **`unpair` means DELETE the `cards` rows**, not "clear student_id":
   a credential is pairable exactly when it has NO row (freshness = row
   non-existence). Clearing `student_id` would leave the row blocking
   re-pairing — a fake unpair. Deleting restores every credential to
   fresh, pair-any-student state.
2. **The deletes mirror the schema's FK contract, explicitly and in one
   transaction**: all `events` rows go (every event belongs to a card —
   cascadeOnDelete), `pending_pairings.card_id` links are cleared while
   history rows survive (nullOnDelete audit trail, TASK-011), and
   students/readers/users/points/recycling are untouched. Running the
   children-first deletes explicitly keeps the outcome deterministic
   even where the sqlite `foreign_keys` pragma is off, and yields honest
   counts for the output.
3. **Exposed on two guarded layers**: artisan `cards:unpair [--force]`
   (bilingual output + its own confirm) and `./run unpair`
   (reset.sh-style bilingual guard, then the artisan call with
   `--force`) — consistent with the ADR-009 run-suite architecture.
   ScriptSuiteTest pins the new command (existence, executable bit,
   dispatch, help, docs parity, bash -n, `$PHP_BIN` invariant).
4. **Dev-side only, on purpose**: production card reassignment stays a
   deliberate data operation (or a future admin flow), never a
   one-command device-adjacent action. `./run reset` remains the
   heavier full-reseed alternative.

## Rationale
- The alternative — a per-card unpair (DELETE by credential_uid) — was
  considered and deferred: the bench need is "reset the slate between
  passes", and a per-card variant invites accidental single-card
  surgery that invariant 2 deliberately makes awkward. The no-cards
  path is a clean bilingual noop, so over-running it costs nothing.
- Keeping tap events of unpaired cards would resurrect them on
  re-pair via the old card_id — but the rows are cascade-deleted by the
  FK anyway; the command just does it deterministically.
- The bench-loop test (`pair → unpair → re-pair the SAME uid`) pins the
  exact owner scenario; without it, "unpair" could silently mean the
  fake version (student_id-only) and the bench would still 422.

## Consequences
- One command restores pairing-testability: `./run unpair --force`
  between bench passes; direct artisan use is equivalent.
- Pairing-desk history loses its card links (rows survive, cards gone)
  — expected for a testing reset; `./run reset` restores demo cards.
- No HTTP surface changes: zero new routes, zero new write paths — the
  device protocol and ADR-020's security model are untouched.
