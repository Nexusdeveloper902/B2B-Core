# STATE SNAPSHOT — after RUN-2026-09-06-core-013

## Repository state

- Branch: main at the TASK-015 merge commit (feature/
  TASK-015-colombia-timezone merged --no-ff; see `git log` for the
  hash) — records docs commit on top
- Working tree: clean
- Test count: 178 passed / 3 skipped (was 174/3) — +4 TimezoneTest
- B2B-Firmware: untouched (main @ f325b2e, TASK-007)

## What the backend does now (delta vs RUN-012)

- **The platform runs on Colombia school time** (TASK-015, ADR-025):
  `config('app.timezone')` = `env('APP_TIMEZONE', 'America/Bogota')`,
  fixed UTC−5, no DST. Wall-clock semantics end to end — `now()`,
  Eloquent storage, dashboards and the "today" boundaries are all the
  same Bogota local time. API ISO 8601 strings carry `-05:00`
  explicitly. The device contract is unchanged (client_timestamp with
  any offset honored as-is).
- No routes, no migrations, no write paths — pure clock semantics.

## Confirmed facts (cumulative, still current)

- Pairing desk honesty (TASK-014/ADR-024), unpair bench reset
  (TASK-013/ADR-023), LAN request-host stateful access
  (TASK-012/ADR-022), ADR-020 pairing invariants: all unchanged
- Dev DB on this machine re-seeded post-merge → demo rows are
  Bogota-stamped

## Bench expectations after the owner pulls

- `git pull` + restart `./run serve` → every clock reads Colombia
  local time. Carry-over rows created before the change read 5 h off;
  `./run reset` re-seeds demo data on Bogota time.

## Open items

- Next: TASK-016 (real-time WebSocket dashboard) + TASK-017 (UX
  overhaul) — owner directive of 2026-09-06, planned and accepted
- Deferred (unchanged): GET /api/v1/reader/me; firmware PAIRING.md
  pointer
