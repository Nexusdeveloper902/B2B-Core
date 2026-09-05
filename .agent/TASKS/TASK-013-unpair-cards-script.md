# TASK-013-unpair-cards-script

Owner's bench request (2026-09-05): "make a little script in b2b core
that basically unpairs every card (it'll make testing easier), same
protocol." After TASK-007/TASK-012 pairing works end-to-end, but every
successful pair burns its credential (ADR-020 invariant 2: any existing
cards row is rejected, 422), so each bench pass previously needed a
manual row delete.

## Deliverables

1. `app/Console/Commands/UnpairCardsCommand.php` — artisan
   `cards:unpair {--force}`: deletes every `cards` row in ONE
   transaction (events cascaded, `pending_pairings.card_id` cleared,
   history rows survive; students/readers/users untouched), bilingual
   output with honest counts, confirmation guard unless `--force`,
   clean noop on an empty table. Semantics per ADR-023 (unpair =
   delete; student_id-nulling would be a fake unpair).
2. `scripts/unpair.sh` + `./run unpair` — reset.sh-style wrapper
   (bilingual header + warning + confirm, `--force` passthrough,
   `$PHP_BIN` invariant), dispatched through the ADR-009 run suite.
3. `tests/Feature/Console/UnpairCardsCommandTest.php` — 5 tests: full
   delete/cascade/survivor semantics; the owner's bench loop (pair →
   422 on re-pair → unpair → SAME uid re-pairs to another student with
   clean event history); the confirmation guard both ways; bilingual
   output with exact counts; empty-table noop.
4. `ScriptSuiteTest` COMMAND_SCRIPTS gains `unpair` → the command is
   pinned by the suite's existence/executable/dispatch/help/docs/
   bash-n/no-bare-php invariants.
5. Docs: `docs/SCRIPTS.md` + `SCRIPTS.es.md` `## unpair` sections
   (with the deletes/keeps table); `.agent/ARCHITECTURE/
   card-pairing-flow.md` "Re-testing the flow" section.
6. `.agent` records: ADR-023, this task file, RUN-2026-09-05-core-011
   + ledger, STATE snapshot, PROJECT.md facts.

## Acceptance

- [x] `./run unpair` and `php artisan cards:unpair --force` both
      restore every credential to fresh/pairable state
- [x] Guarded both layers (confirm unless --force); empty table is a
      clean noop
- [x] 5 new tests (+1 ScriptSuiteTest data-provider case for the new
      command); suite = 167 passed / 3 skipped (was 161)
- [x] `./run quality` PASS (bash -n covers the new script via the
      maxdepth-1 scan; ScriptSuiteTest dispatch/help/docs parity green)
- [x] `./run e2e` 22/22 (throwaway DB — unaffected by design)
- [x] Fresh-clone catch fixed: pre-`./run setup` invocation now dies
      bilingual-friendly (exit 1) instead of a QueryException traceback
      (fix 7bb0673, re-merge b6be717) — proven in-tree and re-cloned
- [x] SCRIPTS.md + SCRIPTS.es.md parity sections; pairing-flow doc note
- [x] No new routes, no new write paths, no device-protocol changes

## Out of scope (deliberately)

- No per-card unpair endpoint/command (bench need is "reset the slate";
  single-card surgery stays deliberate — see ADR-023 rationale).
- No production admin surface for card reassignment (ADR-020's
  model: replacement credentials are NEW pairings, not reassignments).
- B2B-Firmware untouched: its PAIRING.md may later point at
  `./run unpair` as the bench reset (optional follow-up there).
