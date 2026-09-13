# ADR-049 — Product storage is MariaDB (SQLite stays hermetic)

## Date
2026-09-12

## Context
Owner directive: "Migrate to mariadb please." ADR-001 chose SQLite
deliberately and predicted the migration would be "a config change, not
a rewrite, because all access goes through Eloquent." Reality check
against the full suite on a real MariaDB 12.3 server proved the schema
and queries ARE portable (migrations + seed + 447/453 tests passed on
the first attempt), but surfaced six genuine engine-bound issues that
had been invisible on SQLite.

## Decision
1. **The product's dev/prod storage is MariaDB.** `.env` on this
   workstation runs `DB_CONNECTION=mariadb` against a user-level
   MariaDB 12.3 systemd instance (`127.0.0.1:33060`, datadir
   `~/.local/share/pulse-mariadb`, admin `jperez` via unix_socket — the
   system server on 3306 is root-only). Fresh clones still default to
   SQLite (config fallback unchanged) so the zero-config first run
   keeps working; `.env.example` documents the MariaDB path.
2. **The automated test suite stays on hermetic in-memory SQLite**
   (phpunit.xml) — fast, no server dependency — and CI gains a
   `mariadb` service job running the FULL suite on MariaDB 12, so the
   engine cannot drift again.
3. **The e2e stays a hermetic throwaway-SQLite suite** and now pins
   `DB_CONNECTION=sqlite` explicitly (an unpinned connection would
   point `database/e2e.sqlite` at a MariaDB server).
4. **The run suite becomes driver-aware**: `./run setup` (probe +
   bilingual remediation), `./run serve` (probe instead of file check),
   `./run reset` (guarded `migrate:fresh` on MariaDB, file recycle on
   SQLite), `./run status` (live target + reachability),
   `./run doctor` (`pdo_mysql` + connection + schema depth). A shared
   `mariadb_probe` helper in `common.sh` passes credentials to the PHP
   child as process env — never argv, never echoed.
5. **Two-tier PHP module contract**: `PHP_REQUIRED_MODULES` (core,
   interpreter filtering unchanged) + `PHP_RUNTIME_EXTRA_MODULES`
   (`pdo_mysql` — enforced by doctor only when the driver is mariadb).
   The ScriptSuiteTest CI-drift guard covers both tiers.

## Engine truths the migration surfaced (all fixed this run)
- **`roster_updates.payload` was `text`** — 64 KB on MariaDB vs
  effectively unbounded on SQLite; a bulk CSV import broadcast
  exceeded it in strict mode → HTTP 500 mid-import. New migration
  widens it to `json` (LONGTEXT). This was a REAL product bug on the
  target engine, not a test artifact.
- **InnoDB auto-increment survives rolled-back test transactions**
  (SQLite rowids do not) — four tests/fixtures assumed positional ids
  (`class_id => 1`, `'reader_id' => 1`, token id `1`, `assertSame(1,
  verify())`) and broke once the counter advanced. Fixed by resolving
  named fixtures (the RUN-033 rule, now engine-proven).
- **MariaDB refuses `DROP TABLE` on schema-level FK grounds even when
  empty** — one test simulated "DB not ready" by dropping `cards`;
  now suspends FK checks portably around the drop.

## Alternatives Considered
- Switch the test suite to MariaDB entirely — rejected: 453 tests
  against a server slow down CI and add a service dependency to every
  job; the mariadb job covers engine truth where it matters.
- Migrate the Marketplace repo too — not applicable: it is stateless
  by design (its ADR-013, no database anywhere).
- Edit the original roster migration instead of adding a new one —
  rejected: house convention adds migrations for schema changes so an
  already-migrated database converges by `php artisan migrate`.

## Consequences
- CI grows a 14th job; local `./run ci` parity is a follow-up if the
  owner wants the mariadb leg locally (needs the instance up — it is).
- The owner may switch to the system MariaDB (3306) anytime by
  provisioning DB+user there and changing `DB_PORT`.
- `docs/DATABASE.md` + `.es.md` are the storage reference (provisioning
  SQL, instance layout, engine contract, timezone note).
- The system MariaDB on 3306 remains untouched/root-owned; Pulse does
  not depend on it.

## Status
ACTIVE (supersedes ADR-001's "SQLite for now" storage choice; SQLite
remains the test/e2e/fresh-clone engine per this ADR)
