# ADR-010

## Date
2026-09-03

## Context
The owner runs **Arch Linux** and asked for it to be the priority platform.
Arch's `php` package ships `/etc/php/php.ini` with most `extension=` lines
commented out, so `pacman -S php` alone leaves Laravel without
`pdo_sqlite`/`curl`/`mbstring`/`zip` until the user uncomments them — a
well-known new-contributor dead end. Other distros (Debian/Ubuntu/Fedora)
split extensions into packages instead. Meanwhile the run environment that
built TASK-002 proved a fully **static PHP CLI + Composer** toolchain works
for this app (OBS-001), needing zero system packages and zero root.

## Decision
**System-PHP-first with an Arch-aware doctor, and a hermetic toolchain as a
first-class fallback:**

1. PHP/Composer resolution order (in `scripts/_lib/common.sh`):
   `B2B_PHP` env override → `php` on PATH → `.tools/php` (hermetic).
   The first candidate that satisfies **PHP ≥ 8.3** AND all required
   extensions wins; the app never invokes a bare `php` again.
2. `./run doctor` detects the distro via `/etc/os-release` (arch, and
   arch-like via ID_LIKE) and prints **exact, copy-pasteable remediation**:
   the `pacman` install command and the `sed` one-liner that uncomments the
   required extension lines in `/etc/php/php.ini` — the same remediation the
   `arch-smoke` CI job applies on a real `archlinux:base` container, so the
   advice is continuously machine-verified.
3. `./run toolchain` (and `./run setup --hermetic`) provision a static PHP
   CLI + `composer.phar` into **`.tools/` (gitignored)** from
   `dl.static-php.dev` / `getcomposer.org`. No root, no system packages, no
   php.ini editing — works on any x86_64 Linux, verified by the
   `hermetic-smoke` CI job which runs in a container with **no PHP at all**.

## Alternatives Considered
- Hermetic-only (always download static PHP) — rejected as the default: it
  bypasses the distro's patched, security-updated PHP and wastes bandwidth
  for users who already have a correct system PHP.
- System-only with documentation — rejected: fresh-Arch failure mode is the
  exact problem the owner asked to solve.
- Bundling PHP into the repo — rejected: tens of MB of binaries in git.

## Reasoning
Arch users get a fast path that respects their distro (system PHP + one
documented ini fix), while anyone on any Linux (or without root, or with a
broken system PHP) still gets a working app via the hermetic path. Both paths
are continuously proven in CI, so they cannot silently rot.

## Consequences
- `.tools/` must stay gitignored (enforced by ScriptSuiteTest).
- Hermetic PHP is pinned via `B2B_STATIC_PHP_VERSION` (default: the
  OBS-001-verified 8.4.23) so the download is reproducible.
- Required-extension list lives in ONE place (`common.sh`) and must match
  composer.json platform requirements and the CI setup-php extension list —
  ScriptSuiteTest cross-checks the list against composer.json.

## Status
ACTIVE

## Supersedes
none
