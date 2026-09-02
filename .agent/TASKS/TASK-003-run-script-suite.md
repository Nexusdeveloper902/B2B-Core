# TASK-003-run-script-suite

Owner request (RUN-2026-09-03-core-002):

> "Using the same stateless prompt architecture, design scripts (Linux support
> prioritized, Arch specifically) so that running this app doesn't consist of
> 10 different commands but a collection of properly done, well documented
> scripts."

## Problem being solved

TASK-002 delivered a working platform but an operational surface of ~10 manual
commands, several with environment-specific failure modes:

| Step (README quick start) | Manual command | Failure modes on a fresh Linux box |
|---|---|---|
| Dependencies | `composer install` | no PHP / wrong PHP version / missing extensions |
| Environment | `cp .env.example .env` | forgotten, then cryptic "No application encryption key" |
| Key | `php artisan key:generate` | forgotten |
| Database | `touch database/database.sqlite` | forgotten → "database file does not exist" |
| Schema+data | `php artisan migrate --seed` | partial state from a previous attempt |
| Serve | `php artisan serve` | all of the above failing at request time |
| Tests | `php artisan test --testsuite=…` | suite names must be memorized |
| Lint | `vendor/bin/pint` | yet another binary path to remember |
| Real-HTTP e2e | `bash scripts/e2e.sh` | assumes `php` resolves on PATH |
| Local model server | venv + pip + uvicorn (3 commands) | venv/pip bootstrapping each time |

On Arch specifically, `pacman -S php` ships a php.ini with most extension
lines commented out, so even a "successful" install fails Laravel's
`pdo_sqlite`/`curl`/`mbstring` requirements until lines are uncommented — a
classic new-contributor dead end this task removes.

## Solution shape

- **One entry point**: `./run <command>` dispatcher (executable, bash strict mode).
- **A collection of properly-done scripts**: every subcommand delegates to a
  standalone, individually documented script under `scripts/`, sharing
  `scripts/_lib/common.sh` (logging, OS detection, PHP/Composer resolution,
  bilingual output). Scripts remain directly callable — CI uses them without
  the dispatcher.
- **Arch-first**: `doctor` detects Arch via `/etc/os-release` and prints the
  exact `pacman` + `php.ini` remediation (a copy-pasteable `sed` one-liner).
- **Hermetic fallback**: `./run toolchain` provisions a static PHP CLI +
  Composer into `.tools/` (gitignored) — zero system packages, zero root,
  works on any x86_64 Linux including a bare Arch install.
- **Idempotent by design**: every command verifies preconditions and is safe
  to re-run (the stateless principle applied to operations: no command assumes
  a previous command ran).
- **Bilingual**: EN/ES output convention matching the app's bilingual rule;
  full docs in `docs/SCRIPTS.md` + `docs/SCRIPTS.es.md`.
- **Tested**: `tests/Unit/ScriptSuiteTest.php` (structure + bash syntax +
  doc parity), CI jobs: `scripts-lint` (bash -n + shellcheck),
  `arch-smoke` (real `archlinux:base` container), `hermetic-smoke`
  (no-PHP container), plus existing jobs refactored to dogfood `./run setup --ci`.

## Phases

- **A** — persistent memory: task record, ADR-009/010/011, feature branch.
- **B** — `scripts/_lib/common.sh` + `run` dispatcher.
- **B2** — core scripts: doctor, setup, serve, test.
- **B3** — quality, status, reset, provision-toolchain.
- **C** — model-server lifecycle + e2e.sh migrated to shared resolution.
- **D** — bilingual SCRIPTS docs + README quick start rewrite + .gitignore.
- **E** — ScriptSuiteTest + CI jobs (scripts-lint / arch-smoke / hermetic-smoke).
- **F** — full command-by-command verification in the run environment.
- **G** — merge to main (verified), push, run record + state snapshot.

---

## Commit — (appended during execution)

Date: 2026-09-03
Branch: feature/TASK-003-run-script-suite

(Each phase appends its commit record below.)
