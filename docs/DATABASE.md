# Data & Storage — Pulse Core

> **Read this in:** [Español](DATABASE.es.md)
>
> Established by the MariaDB migration (2026-09-12, ADR-049, superseding
> ADR-001's "SQLite for now"). The product's dev/prod storage is
> **MariaDB**; SQLite remains a first-class engine for fresh clones, the
> automated test suite and the hermetic e2e.

## 1. The engine contract

| Engine | Used for | Why |
|---|---|---|
| **MariaDB** (10.6+ / 12.x verified) | the running app (dev/prod), sessions + cache tables, realtime feed | server-based storage as requested; same schema via Eloquent migrations |
| **SQLite** (`:memory:`) | `php artisan test` (the 453-test suite) | hermetic, fast, no server in CI |
| **SQLite** (file) | `./run e2e` throwaway DB | the real-HTTP contract suite is self-contained by design (ADR-010/011) |

Migrations are written engine-neutral (no SQLite-specific SQL anywhere —
verified by running the full suite on BOTH engines). The one deliberate
widening: `roster_updates.payload` is `json` (LONGTEXT on MariaDB) — a
bulk-import broadcast must never hit the old TEXT 64 KB ceiling.

## 2. Provisioning MariaDB (one-time)

Create the database and the app user (credentials then live ONLY in
`.env`):

```sql
CREATE DATABASE pulse CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'pulse'@'localhost' IDENTIFIED BY '<password-from-.env>';
CREATE USER 'pulse'@'127.0.0.1' IDENTIFIED BY '<password-from-.env>';
GRANT ALL PRIVILEGES ON pulse.* TO 'pulse'@'localhost';
GRANT ALL PRIVILEGES ON pulse.* TO 'pulse'@'127.0.0.1';
FLUSH PRIVILEGES;
```

Then in `.env`:

```
DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=pulse
DB_USERNAME=pulse
DB_PASSWORD=<the password>
```

`./run setup` verifies reachability before migrating; `./run status`
reports the live target; `./run doctor` checks `pdo_mysql`, the
connection and the schema depth; `./run reset` runs the guarded
`migrate:fresh --seed` on MariaDB exactly like it does on SQLite.

## 3. This workstation's instance (user-level systemd service)

The machine's system MariaDB (port 3306) is root-only (unix_socket
auth, no root for the agent). Pulse therefore runs on a **user-level
instance** — same server binary, owned by `jperez`, durable (user
lingering is enabled):

- Unit: `~/.config/systemd/user/pulse-mariadb.service`
  (`systemctl --user enable --now pulse-mariadb`)
- Datadir: `~/.local/share/pulse-mariadb`
- Socket: `$XDG_RUNTIME_DIR/pulse-mariadb.sock` (admin = `jperez` via
  unix_socket), TCP **127.0.0.1:33060** (loopback only)
- Databases: `pulse` (app) and `pulse_test` (the MariaDB suite runs)

`.env` points at `127.0.0.1:33060`. To move to the system server later:
provision the DB + user there (needs root), change `DB_PORT` to 3306,
`./run reset --force`.

## 4. CI

`ci.yml` runs the **full suite against a MariaDB 12 service container**
(job `mariadb`) alongside the default sqlite jobs — schema drift and
engine-specific regressions fail the build. The drift guard
(`ScriptSuiteTest`) now covers a two-tier module contract:
`PHP_REQUIRED_MODULES` (core, always required) and
`PHP_RUNTIME_EXTRA_MODULES` (`pdo_mysql` — required at runtime only
when `DB_CONNECTION=mariadb`, never part of interpreter candidate
filtering, so the hermetic toolchain path is unaffected).

## 5. Timezone

Unchanged by this migration (ADR-025): the app is single-timezone
wall-clock (`America/Bogota`); MariaDB DATETIME columns store the same
naive local timestamps SQLite stored. The `-05:00` ISO API strings come
from the app layer, not the engine.
