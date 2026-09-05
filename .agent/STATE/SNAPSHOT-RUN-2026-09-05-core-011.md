# STATE SNAPSHOT — after RUN-2026-09-05-core-011

## Repository state

- Branch: main at 18a895e — TASK-013 landed as a merge pair (73a5d20
  main merge; then two fresh-clone catches fixed on the feature branch
  and re-merged: 7bb0673 preflight → b6be717, 9fbc576 fail-fast →
  18a895e; records docs commit on top)
- Working tree: clean
- Test count: 168 passed / 3 skipped (was 161/3) — +6
  UnpairCardsCommandTest, +1 ScriptSuiteTest provider case
- B2B-Firmware: untouched this run (main @ f325b2e, TASK-007)

## What the backend does now (delta vs RUN-010)

- `./run unpair` (and `php artisan cards:unpair --force`): the pairing
  bench reset. Deletes every `cards` row in one transaction — tap
  events cascade, `pending_pairings.card_id` links clear, history rows
  survive — so EVERY credential_uid is fresh and pairable again through
  the normal arm-then-pair flow. Students/readers/users/points/
  recycling untouched. Bilingual honest counts; confirmation guard on
  both layers unless `--force`; empty table = clean noop.
- No HTTP changes: zero new routes, zero new write paths. ADR-020's
  pairing protocol and the device contract are untouched.

## Confirmed facts (cumulative, still current)

- Freshness = row non-existence: any existing cards row makes a
  credential unpairable-by-protocol (422, invariant 2) — which is why
  "unpair" deletes rows instead of clearing student_id (ADR-023)
- The bench loop is test-pinned: pair → 422 on re-pair → `./run unpair
  --force` → the SAME credential_uid re-pairs to another student with a
  clean event history
- Pairing desk arming + status feed (TASK-011), request-host stateful
  LAN access (TASK-012) — unchanged and still current
- `./run reset` remains the heavier full-reseed alternative and
  restores the seeded demo cards

## Bench expectations after the owner pulls

- `git pull` then between pairing passes: `./run unpair` (confirm) or
  `./run unpair --force` → every card is fresh → arm on the dashboard
  (desktop or phone) → tap within 45 s → paired again.
- Direct artisan form works identically: `php artisan cards:unpair
  [--force]`.

## Open items

- Deferred: per-card unpair surface (deliberate — see ADR-023
  rationale); GET /api/v1/reader/me boot-time key check; production
  admin card-reassignment flow (deliberately out of scope)
- Optional firmware follow-up (NOT started): B2B-Firmware's PAIRING.md
  could name `./run unpair` as the bench reset between passes
