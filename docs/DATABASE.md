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

## 6. Schema additions — HCE credentials (phone-as-credential)

- **`cards.kind`** (`physical` | `hce`, default `physical`): HOW the
  credential was captured — an RF-layer MIFARE UID vs an
  application-level Android HCE credential id from the SELECT AID
  (`F0010203040506`) + CHALLENGE exchange. Display/audit metadata only:
  tap lookup stays `credential_uid`-only (the phone's RF UID is
  randomized per tap by Android and never stored), existing rows
  backfill as `physical`, and pairing defaults to `physical` when the
  reader omits `credential_kind`. Safe and reversible (drop-column
  down). The pairing desk badges `hce` rows (“Phone” / “Teléfono”).

## 7. Schema additions — TASK-037 (PAE full program)

- **`students`**: the single `pae_enrolled` flag became two independent
  columns — `pae_breakfast_enrolled` and `pae_lunch_enrolled` (both
  boolean, default false). A student may be enrolled for breakfast only,
  lunch only, both, or neither. The migration copies the old flag into
  both (a superset — no enrolled student loses a meal) before dropping
  the column; a fresh reseed is equally valid per the task spec.
- **`events`**: two columns making the served-vs-flagged distinction
  explicit on the event-type spine (ADR-053): `served` (boolean, default
  true — only served rows count toward PAE statistics) and `reason`
  (nullable string — the machine-stable rejection reason for flagged
  rows: `weekend` / `out_of_window` / `window_overlap` / `no_student` /
  `not_enrolled` / `no_attendance` / `duplicate`).
- **`settings`** (new table, ADR-055): `key` (unique) + `value` (json) +
  timestamps — the DB-backed runtime overrides read by
  `SettingsService` (meal windows, late cutoff, pairing window, student
  account conventions). Reads resolve row → config default; the config
  defaults remain env-overridable (`PAE_BREAKFAST_START` etc. in
  `.env.example`).

## 8. Schema additions — TASK-045 (schools / organizations)

> ADR-064. Additive and reversible; no data rewrite.

**`schools`** — the organization every org-owned row belongs to, and the
anchor of the branding chain (`docs/BRAND.md` §3b).

| Column | Notes |
|---|---|
| `name` | the institution's own name, unique (e.g. `IE Concejo de Sabaneta J.M.C.B`) |
| `slug` | stable machine identifier, unique |
| `brand_key` | which `config/branding.php` profile it renders with; `NULL`/unknown ⇒ stock Pulse |

**`school_id`** (nullable FK, `nullOnDelete`) was added to the ROOT
organization-owned tables only:

```
users · classes · students · readers · rewards · events
roster_updates · recycling_updates
```

Everything else descends from one of those and is scoped **through its
parent**, so ownership is stored exactly once and cannot disagree with
itself:

| Child table | Owner resolved via |
|---|---|
| `cards`, `points_ledger`, `reward_redemptions`, `pending_pairings` | `students.school_id` |
| `recycling_deposits` | `events.school_id` |
| `pending_captures` | `readers.school_id` |

`events` is the single deliberate denormalization: attendance, PAE and
recycling are all derived views aggregated straight off that table, so a
join per aggregate would be paid on every dashboard render. It is
stamped from its **reader** (the device's own identity — never a
client-supplied value), and a test pins that every event agrees with its
reader's school.

Indexes added: `events (school_id, type, occurred_at)` and
`students (school_id, class_id)` — the two hot reads every dashboard runs.

### `NULL` is a supported value, not a half-migration

Every column is nullable and every pre-existing row keeps working:

- **Admin + `school_id IS NULL` = the system administrator.** It belongs
  to no organization and therefore operates across all of them. That is
  the one deliberate cross-organization capability, granted by the
  account's own row — never by a request parameter.
- **Any other role with `school_id IS NULL`** is restricted to the
  unassigned (`NULL`) data set. It never falls open to the whole
  database.

`./run reset` (DemoSeeder) and `./run reset --pilot` deliberately seed no
school at all — they are the stock-Pulse fixture. `./run seed-realistic`
seeds one school (`IE Concejo de Sabaneta J.M.C.B`) owning every row it
writes, plus exactly one system administrator **outside** it
(`SystemAdminSeeder`, run separately by the script).
