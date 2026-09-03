# TASK-008 — Windows support for the run suite (auto-detected, fallback-only)

## Date
2026-09-04

## Status
COMPLETED — see RUN-2026-09-04-core-006

## Request
Owner: *"Ok now you should include windows support for the scripts but
only as fallbacks (it should detect automatically) same stateless
protocol"* — the ./run suite is Arch/Linux-first (ADR-009/010); Windows
must work, but ONLY as an automatically detected fallback. Linux
behavior must not drift. Same stateless agent protocol.

## Design (ADR-017)

**Detection, not configuration.** `scripts/_lib/common.sh` gains
`detect_os()` → `B2B_OS` = `linux | macos | windows | unknown`,
resolved ONCE at source time from `uname -s` (MINGW*/MSYS*/CYGWIN* ⇒
windows; the `OS=Windows_NT` env var is the secondary signal). WSL
reports `Linux` — it runs the same ELF binaries, so it IS the Linux
path. An explicit `B2B_OS` env var wins over detection (test hook +
power-user override); default is always auto.

**What changes on Windows (fallback), what never changes (Linux):**

| Area | Linux (unchanged) | Windows fallback |
|---|---|---|
| PHP resolution | B2B_PHP → PATH → `.tools/php` (static ELF) | B2B_PHP → PATH (`php.exe`); `.tools/php` is a Linux ELF and is NEVER probed |
| Composer resolution | B2B_PHP → PATH → `.tools/composer` phar via `$PHP_BIN` | same, plus `composer.bat` / `composer.cmd` / `composer.phar` PATH probes (bash cannot auto-resolve `.bat`), invoked DIRECTLY (they wrap PHP themselves) |
| `./run toolchain` | static PHP + composer.phar into `.tools/` | composer.phar only (cross-platform); PHP must come from php.net / winget / choco — printed |
| Model server venv | `.venv/bin/` | `.venv/Scripts/` (Windows venv layout) |
| `pkill` fallback (model stop) | `pkill -f uvicorn` | `taskkill //F //T //PID <winpid>` via `/proc/<pid>/winpid` (Git Bash /proc emulation) |
| python resolution | `python3` | `python3` → `python` → `py` (the Windows launcher) |
| Entry point | `./run` (bash) | `run.cmd` (cmd/PowerShell wrapper that locates Git Bash and delegates to the SAME dispatcher); `./run` also works inside Git Bash |
| Install guidance | pacman / apt / dnf | php.net / `winget install PHP.PHP-8.4` / choco |

**Line-ending contract** (`.gitattributes`, new): `*.sh` + `run` pinned
`eol=lf` (CRLF breaks bash), `*.cmd`/`*.bat` pinned `eol=crlf`. This
protects Git Bash users from a global `core.autocrlf=true` corrupting
the suite.

**Machine proof (CI):** new `windows-smoke` job on `windows-latest`
(Git Bash shell): `./run setup --ci` → `./run doctor` → `./run quality`
→ `./run test` → `./run e2e` (real HTTP). NOT optional (OBS-007
three-state rule does not apply — it always runs). This is the same
"prove it on the real OS" pattern as arch-smoke / hermetic-smoke.

## Scope
- `run`, `run.cmd` (new), `.gitattributes` (new)
- `scripts/_lib/common.sh`, `scripts/doctor.sh`, `scripts/status.sh`,
  `scripts/model-server.sh`, `scripts/provision-toolchain.sh`
- `.github/workflows/ci.yml` (windows-smoke job)
- `tests/Unit/ScriptSuiteTest.php` (windows contracts)
- `docs/SCRIPTS.md` + `.es.md`, `README.md` + `README.es.md`

## Commit plan
1. feat(scripts): windows fallback layer
2. feat(ci): windows-smoke job
3. test(scripts): windows fallback contracts
4. docs: windows fallback (EN/ES)
5. docs(agent): TASK-008 closure records

## Verification plan
- Local (Linux): full suite unchanged — `./run test`, `quality`, `e2e`,
  `doctor`; `bash -n` + shellcheck on every script; `B2B_OS=windows`
  simulations (candidates exclude `.tools/php`, bat probes emitted,
  windows guidance path reachable).
- CI: dispatch on the feature branch; windows-smoke must go green on a
  real windows-latest runner; all 12 existing jobs must stay green
  (zero Linux drift); then merge → main → tip-run verification.


## Outcome (2026-09-04)

All five commits landed on main (fast-forward from feature branch):

- 92ce002 feat(scripts): windows fallback layer (ADR-017)
- b4dc326 feat(ci): windows-smoke job
- 42f57bd test(scripts): windows fallback contracts (10 tests)
- cc0ec88 docs: windows fallback (EN/ES)
- a951b06 fix(scripts): never php-validate composer.bat/.cmd (OBS-010)

Verification:
- Local (Linux): 129 passed / 1 opt-in skip; quality green; e2e 22/22;
  doctor green — zero Linux drift (linux counter-test locks .tools probing).
- CI dispatch 1 (33815966369): 13/13 success FIRST DISPATCH — windows-smoke
  green on real hardware (auto-detected B2B_OS=windows; 125 tests; e2e 22/22).
- Green-log forensics found OBS-010 (php false-positive on .bat) → a951b06.
- CI dispatch 2 (33816386157): 13/13 success; composer.bat validated direct.
- Merged to main, pushed; push-triggered tip run 33816810463: success 13/13.

Files: run, run.cmd (new), .gitattributes, scripts/_lib/common.sh,
doctor.sh, status.sh, model-server.sh, provision-toolchain.sh, e2e.sh,
.github/workflows/ci.yml, tests/Unit/ScriptSuiteTest.php,
docs/SCRIPTS.md/.es.md, README.md/.es.md.
