# ADR-017 — Windows as an auto-detected fallback (never a Linux fork)

## Status
ACCEPTED (2026-09-04, TASK-008)

## Context
ADR-009/010 made `./run` the single operating entry point with an
Arch-first, hermetic-capable resolution chain. The owner now needs the
same suite usable on Windows — but "only as fallbacks (it should detect
automatically)". The risk to avoid: maintaining two divergent script
suites, or quietly changing Linux behavior while adding Windows.

## Decision
**One suite, one source of truth, OS-aware branches — not a fork.**

1. **Detection is a first-class layer in `common.sh`**: `detect_os()`
   sets `B2B_OS` (`linux | macos | windows | unknown`) once at source
   time from `uname -s` (MINGW/MSYS/CYGWIN ⇒ windows; `OS=Windows_NT`
   as secondary signal). WSL is Linux. `B2B_OS` env var overrides
   detection — this is the seam the tests use (no mocking framework
   needed) and an escape hatch for users.
2. **The resolution chain keeps its ORDER on every OS**
   (override → PATH → `.tools/`); only the CANDIDATE SET is OS-aware:
   the Linux ELF `.tools/php` is never probed on Windows; Windows-only
   Composer wrappers (`composer.bat`, `composer.cmd`) are probed there.
   Composer gains two invocation modes — phar (via `$PHP_BIN`) and
   direct (Windows wrappers already wrap PHP).
3. **Windows-specific mechanics are isolated to guarded branches**:
   venv `Scripts/` layout, `taskkill //F //T //PID <winpid>` via
   `/proc/<pid>/winpid` (Git Bash /proc emulation) as the pkill
   fallback, `python3 → python → py` resolution. Every guard is
   `is_windows &&` — Linux code paths are byte-identical.
4. **`./run toolchain` degrades honestly on Windows**: it provisions
   the cross-platform `composer.phar` and REFUSES the static-PHP
   download with install guidance (php.net / winget / choco) — no
   silent partial installs.
5. **`run.cmd` is a thin delegator, not a dispatcher**: it locates
   Git Bash (well-known install paths, then PATH) and forwards every
   argument to the SAME bash `run` dispatcher. Command routing stays
   single-source (ADR-009).
6. **`.gitattributes` pins the line-ending contract**: `*.sh` + `run`
   `eol=lf`, `*.cmd`/`*.bat` `eol=crlf` — a user's global
   `core.autocrlf=true` can no longer corrupt the bash suite on
   checkout.
7. **Proof by CI, not by claim**: a non-optional `windows-smoke` job
   (windows-latest, Git Bash) runs doctor → setup → quality → test →
   e2e on real Windows hardware, mirroring the arch-smoke /
   hermetic-smoke pattern.

## Consequences
- Windows users need Git for Windows (bash) + a system PHP; the
  hermetic zero-system path remains Linux-only (ELF reality).
- `ScriptSuiteTest` locks the fallback contracts source-level and
  behaviorally (`B2B_OS=windows` simulations run on any OS).
- Linux behavior is guarded by the existing 12 CI jobs plus local
  full-suite runs before merge.
- macOS stays a supported-by-construction platform (unix chain), still
  not CI-exercised (unchanged risk, now explicit in docs).
