# ADR-009

## Date
2026-09-03

## Context
TASK-002 shipped an operational surface of ~10 memorized commands (composer
install, cp .env, key:generate, touch db, migrate --seed, serve, artisan test
--testsuite=…, pint, scripts/e2e.sh, and a 3-command venv+pip+uvicorn dance for
the model server). The owner asked for "a collection of properly done, well
documented scripts" instead — Linux support prioritized, Arch specifically.

## Decision
Adopt a **single dispatcher + standalone scripts** architecture:

- `./run <command>` at the repository root is the only entry point a human
  needs to remember (`./run help` lists everything).
- Every subcommand delegates to a standalone script under `scripts/` that is
  independently executable and documented (`scripts/doctor.sh`, `setup.sh`,
  `serve.sh`, `test.sh`, `quality.sh`, `status.sh`, `reset.sh`,
  `model-server.sh`, `provision-toolchain.sh`, plus the existing `e2e.sh`).
- All scripts share one library, `scripts/_lib/common.sh` (logging, bilingual
  output, OS detection, PHP/Composer resolution, precondition helpers).
- Dispatcher and scripts are bash (present by default on Arch and every
  mainstream Linux; the project targets Linux servers/desktops).

## Alternatives Considered
- **Makefile** — rejected: tab-significance, poor arg passing, help text is
  an afterthought, and `make test e2e` semantics (two targets) surprise
  non-make users.
- **`just`** — rejected: requires installing an extra tool that is not in
  base repos on several distros (AUR-only on Arch) — contradicts "works on a
  fresh Arch box".
- **Composer scripts** — rejected: `composer run-script` presupposes a
  working PHP+Composer toolchain, which is exactly what the scripts must
  bootstrap; also cannot express process management (serve/model server).
- **A `bin/console` PHP tool** — same bootstrap circularity as Composer
  scripts.

## Reasoning
The dispatcher gives one memorable entry point (the "not 10 commands" goal),
while standalone scripts keep every operation composable for CI and power
users (CI calls `scripts/*.sh` directly, matrix jobs on PHP 8.3/8.4 then
double as toolchain-resolution tests). Bash is the lowest common denominator
on Linux and needs no runtime to bootstrap.

## Consequences
- Shell code is part of the deliverable and therefore part of the tested
  surface: `bash -n` + shellcheck run in CI (`scripts-lint` job), and
  `tests/Unit/ScriptSuiteTest.php` guards structure and doc parity.
- New operational features must be added as scripts + a dispatcher subcommand
  + bilingual doc entries (the ScriptSuite test enforces the doc half).
- Windows is out of scope (owner prioritized Linux/Arch); WSL works because
  bash is present.

## Status
ACTIVE

## Supersedes
none
