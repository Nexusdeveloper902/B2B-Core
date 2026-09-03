# Run Scripts Reference — One Command for Everything

> **Read this in:** [Español](SCRIPTS.es.md)
>
> The `./run` suite replaces the ~10-step manual bootstrap (composer install,
> cp .env, key:generate, touch db, migrate --seed, serve, artisan test, pint,
> e2e.sh, venv+pip+uvicorn) with **one well-documented command per operation**.
> Linux support is prioritized, **Arch Linux first**.

Design decisions live in `.agent/DECISIONS/`:
[ADR-009](../.agent/DECISIONS/ADR-009-run-dispatcher.md) (dispatcher architecture),
[ADR-010](../.agent/DECISIONS/ADR-010-arch-first-with-hermetic-fallback.md) (Arch-first + hermetic toolchain),
[ADR-011](../.agent/DECISIONS/ADR-011-idempotent-self-diagnosing-scripts.md) (idempotent, self-diagnosing scripts).

## The 60-second version

```bash
git clone https://github.com/Nexusdeveloper902/B2B-Core.git
cd B2B-Core
./run setup     # deps + .env + APP_KEY + database + demo data (prints credentials)
./run serve     # preflight-checked dev server → http://127.0.0.1:8000
```

That's the whole quick start. If anything is off on your machine, the next
command to run is always printed for you — and it's usually `./run doctor`.

## Command reference

| Command | Replaces | One-liner |
|---|---|---|
| `./run setup` | 5 bootstrap commands | Idempotent full bootstrap, prints demo credentials |
| `./run serve` | `php artisan serve` + hope | Dev server with preflight checks |
| `./run test` | `php artisan test --testsuite=…` | Test pyramid: `unit` · `feature` · `e2e` · `all` |
| `./run e2e` | `bash scripts/e2e.sh` | Real-HTTP end-to-end (throwaway DB) |
| `./run quality` | `vendor/bin/pint` + manual checks | Pint + bash syntax + shellcheck + docs parity |
| `./run doctor` | reading error messages | Environment diagnostics + exact fixes |
| `./run status` | guessing | App state: toolchain, .env, DB, servers, classifier |
| `./run reset` | `migrate:fresh --seed` | Fresh DB + demo data (asks first) |
| `./run model` | venv + pip + uvicorn (3 cmds) | Local model server: start/stop/status/run |
| `./run toolchain` | manual PHP installation | Hermetic static PHP+Composer into `.tools/` |
| `./run ci` | reading ci.yml | Everything CI runs, locally, in order |

Every command accepts `--help` (e.g. `./run setup --help`) and every script is
also directly executable (`bash scripts/setup.sh …`) — CI uses them that way.

---

## `setup`

```bash
./run setup                # full bootstrap (interactive-safe)
./run setup --ci           # quiet: composer install + .env + APP_KEY only
./run setup --hermetic     # provision .tools/ PHP first, then full setup
./run setup --no-seed      # migrate without demo data
./run setup --fresh        # wipe the dev database and rebuild from zero
```

What it does, in order (each step is a no-op when already done — safe to
re-run any time, see ADR-011):

1. Resolves PHP and Composer (see [toolchain resolution](#toolchain-resolution)).
2. `composer install` (verifies `vendor/` afterwards).
3. Creates `.env` from `.env.example` **only when missing** — your edits are
   never overwritten.
4. Generates `APP_KEY` only when empty.
5. Creates `database/database.sqlite` when the connection is sqlite.
6. `php artisan migrate --force` + `php artisan db:seed --force` (skipped in
   `--ci`; the seeder is firstOrCreate-idempotent and **re-prints every demo
   credential bilingually** on every run).

Exit codes: `0` ok · `1` failure with a suggested fix printed.

## `serve`

```bash
./run serve                # 127.0.0.1:8000
./run serve 8080           # custom port
./run serve --host=0.0.0.0 # all interfaces (LAN demo)
```

Fails **before** binding (not at request time) when setup is incomplete, and
tells you exactly which `./run` command fixes it. Env overrides:
`B2B_SERVE_PORT`, `B2B_SERVE_HOST`.

## `test`

```bash
./run test                 # all suites
./run test unit            # services, classifiers, NL orchestration
./run test feature         # API + web integration
./run test e2e             # full bilingual journeys (in-process)
./run test unit --filter=PointsServiceTest   # pass-through to artisan
```

Anything after the suite name is forwarded to `php artisan test`. Live-LLM
tests remain opt-in (`RUN_LIVE_LLM_TESTS=1` + `GEMINI_API_KEY`).

## `e2e`

```bash
./run e2e                  # throwaway DB on port 8089
./run e2e 9090             # custom port
```

Boots `php artisan serve` against `database/e2e.sqlite` (your dev database is
**never touched**), then exercises the whole platform story over plain HTTP
with 22 bilingual checks: tap → classify → idempotent earn → reader relabel →
redeem → honest NL-query blocked state → dashboard routing. Exits non-zero if
any check fails.

## `quality`

```bash
./run quality
```

The lint gate, same as CI's lint stage: (1) `bash -n` on `run` + every script,
(2) shellcheck when installed (CI always installs it), (3) Laravel Pint,
(4) bilingual docs parity — every dispatcher command must have a documented
section in `docs/SCRIPTS.md` **and** `docs/SCRIPTS.es.md`.

## `doctor`

```bash
./run doctor
```

Checks OS/distro, bash, curl, git, python3, **every** PHP candidate and **every**
Composer candidate (with per-candidate failure reasons), then the project state
(.env, APP_KEY, vendor/, database file + schema). Exits `0` only when
everything is green, and prints the exact remediation for each problem — on
Arch that includes copy-pasteable `pacman` + `php.ini` commands (see
[Arch Linux](#arch-linux) below).

## `status`

```bash
./run status
```

One glance: distro, resolved PHP/Composer (and where they came from), .env and
APP_KEY state, classifier driver, database, app-server health (`/up`),
model-server health. Informational — always exits `0`.

## `reset`

```bash
./run reset                # asks for confirmation
./run reset --force        # no prompt (scripting/CI)
```

Wipes **the dev sqlite database only** and rebuilds it with fresh demo data
(`migrate:fresh --seed`), re-printing all credentials. The e2e throwaway DB is
unaffected. Refuses to operate when `DB_CONNECTION` is not sqlite.

## `model`

```bash
./run model start    # venv (once) + deps + background uvicorn + wait healthy
./run model status   # health + PID + log path
./run model stop     # stop it (SIGTERM, then SIGKILL if needed)
./run model run      # foreground mode (Ctrl+C)
```

Manages the local classifier sidecar (`scripts/local-model-server/`, see
[docs/LOCAL_MODEL.md](LOCAL_MODEL.md)). The venv is created once and
dependencies reinstall automatically only when `requirements.txt` changes.
Log: `storage/logs/model-server.log`; PID: `.model-server.pid` (gitignored).
Env: `B2B_MODEL_PORT` (default 8501, matching `LOCAL_CLASSIFIER_URL`).

## `toolchain`

```bash
./run toolchain           # idempotent no-op when already provisioned
./run toolchain --force   # re-download
```

Downloads a **statically-linked PHP CLI** (all required extensions compiled
in — verified against the same module list `doctor` checks) plus
`composer.phar` into `.tools/` (gitignored). No root, no system packages, no
`php.ini` edits. Works on any x86_64 Linux — including a bare Arch install,
containers, and machines where you simply cannot touch the system PHP.
Pin a version with `B2B_STATIC_PHP_VERSION` (default: `8.4.23`).

## `ci`

```bash
./run ci
```

Runs, in CI order: `quality` → `test all` → `e2e`. Fails fast and reports
which stage broke. This is the same code path the GitHub Actions pipeline
dogfoods on every push.

---

## Toolchain resolution

Every script resolves its interpreter through one chain (ADR-010), so no
script ever calls a bare `php`:

1. **`B2B_PHP` env override** (must be valid, or it is ignored with a warning)
2. **`php` on PATH** — when it is ≥ 8.3 and has all required modules
   (`ctype curl dom fileinfo libxml mbstring openssl pdo_sqlite session
   sqlite3 tokenizer xml xmlwriter zip`)
3. **`.tools/php`** — the hermetic static build from `./run toolchain`

Composer follows the same chain (`B2B_COMPOSER` → PATH → `.tools/composer`)
and is always **executed through the resolved PHP** (`php composer.phar …`),
so the whole suite works on machines with no `php` on PATH at all.

| Env var | Default | Used by |
|---|---|---|
| `B2B_PHP` | — | force a PHP binary |
| `B2B_COMPOSER` | — | force a Composer phar/binary |
| `B2B_STATIC_PHP_VERSION` | `8.4.23` | `./run toolchain` |
| `B2B_SERVE_PORT` / `B2B_SERVE_HOST` | `8000` / `127.0.0.1` | `./run serve` |
| `B2B_MODEL_PORT` | `8501` | `./run model` |
| `NO_COLOR` | — | disable colored output |

## Arch Linux

Arch is the priority platform. `sudo pacman -S php` ships a `/etc/php/php.ini`
with most `extension=` lines **commented out**, and — importantly — current
Arch **splits the SQLite extensions into a separate `php-sqlite` package**
(the main `php` package no longer contains `pdo_sqlite.so`/`sqlite3.so`), so
a fresh install fails Laravel's extension requirements until both are fixed.
`./run doctor` detects this and prints the exact fix:

```bash
sudo pacman -S --needed php php-sqlite composer
# Enable the extensions the platform needs (as printed by ./run doctor —
# the form below uses no sed backreference, so it is copy-paste safe):
sudo sed -ri '/^;(extension=(ctype|curl|dom|fileinfo|iconv|libxml|mbstring|openssl|pdo_sqlite|session|sqlite3|tokenizer|xml|xmlwriter|zip))$/s/^;//' /etc/php/php.ini
./run setup && ./run doctor   # verify → all green
./run serve
```

The `sed` one-liner is not folklore: the `arch-smoke` CI job applies exactly
this remediation on a real `archlinux:base` container on every push, so the
advice is continuously machine-verified. If you would rather not touch the
system at all, the hermetic path works on a bare Arch box with zero packages:

```bash
./run toolchain && ./run setup   # no root, no pacman, no php.ini edits
```

The local model server needs `python3`, which is part of Arch's base system
(`python -m venv` works out of the box — no extra package needed).

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `No usable PHP found …` | no PHP / old PHP / missing extensions | read the printed distro hint, or `./run toolchain` |
| `vendor/ missing — run ./run setup` | dependencies not installed | `./run setup` |
| `APP_KEY: EMPTY` | .env created but key not generated | `./run setup` (only fills empties) |
| `database/database.sqlite missing` | no DB file | `./run setup` |
| Composer "not usable" in doctor | no PHP to run the phar through | fix PHP first (see above) |
| `Model server failed to become healthy` | port in use / venv broken | `tail storage/logs/model-server.log`, `./run model stop` then `start` |
| Server runs but pages error | partial setup state | `./run doctor`, then `./run setup` |
| Pint fails in `./run quality` | style drift | `vendor/bin/pint` (writes fixes), re-run |

## File map

```
run                              # the dispatcher (single entry point)
scripts/
├── _lib/common.sh               # shared lib: resolution chain, logging, distro detect
├── setup.sh · serve.sh · test.sh · e2e.sh · quality.sh
├── doctor.sh · status.sh · reset.sh
├── model-server.sh              # local classifier lifecycle
├── provision-toolchain.sh       # hermetic PHP+Composer provisioner
├── ci.sh                        # local CI mirror
└── local-model-server/          # FastAPI reference server (unchanged)
```
