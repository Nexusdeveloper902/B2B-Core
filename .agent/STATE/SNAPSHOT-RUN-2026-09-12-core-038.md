# STATE SNAPSHOT — RUN-2026-09-12-core-038

## Overall Status
SQLite → MariaDB migration DONE and verified (uncommitted tree). The
app runs on MariaDB 12.3 (user-level instance); the suite passes in
full on BOTH engines; CI will too once pushed.

## Completed
- User-level `pulse-mariadb` systemd service (127.0.0.1:33060, socket
  admin `jperez`), databases `pulse` + `pulse_test`, app user with
  .env-only credentials.
- `.env` on mariadb; migrations/seed clean; payload-widening migration
  (`roster_updates.payload` → json).
- Six engine-truths fixed (payload ceiling = real product bug; 4
  positional-id fixtures; 1 non-portable drop).
- Run suite driver-aware (setup/serve/reset/status/doctor + probe
  helper); e2e pinned to sqlite; CI `mariadb` job + two-tier module
  contract; docs DATABASE.md/.es + SCRIPTS updates; ADR-049.

## In Progress
- Nothing.

## Blocked
- Push + remote CI observation pending owner PAT (RUN-036/037/038 all
  uncommitted).

## Known Problems
- None new. The system MariaDB on 3306 is root-only and untouched;
  Pulse uses its own user-level instance on 33060.

## Important Current Facts
- Gates: MariaDB suite 453/3 · sqlite suite 453/3 · quality PASS ·
  e2e 33/33 · live browser write verified into MariaDB.
- App server runs detached (log: /tmp/pulse-serve.log).
- Durable traps: (1) InnoDB auto-increment survives rolled-back tests —
  never write positional-id fixtures; (2) MariaDB TEXT = 64 KB strict —
  unbounded payloads need json/longText; (3) `DROP TABLE` on a parent
  table needs FK checks suspended even when empty; (4) do not merge
  pdo_mysql into PHP_REQUIRED_MODULES (two-tier contract is tested).
- `.env` holds DB_PASSWORD (gitignored, never echoed to records).
