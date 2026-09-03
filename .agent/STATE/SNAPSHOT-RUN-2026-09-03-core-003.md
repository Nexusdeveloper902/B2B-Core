# STATE SNAPSHOT — after RUN-2026-09-03-core-003

## Repository state

- Branch: main at b0fd17d (+ this record commit)
- TASK-004 commits: 074c531 (activation + dual root cause + actionlint
  guard), b045742 (six-job repair), 83fb374 (sed/%b/matrix-guard fixes),
  5293916 (iconv + safe.directory), b0fd17d (php-gd)
- Working tree: clean (generated state gitignored: vendor/, .env, .tools/,
  pidfiles, venv)
- Remote: origin/main == local main; test branch `ci/pr-trigger-test`
  deleted; PR #1 (trigger smoke) closed

## What the project can do now

- **Everything from TASK-002 + TASK-003** (bilingual Laravel 13 platform,
  full test pyramid, `./run` single entry point).
- **Actual GitHub Actions CI, green**: 12 jobs — workflows-lint
  (actionlint), lint, scripts-lint, unit, integration, e2e, http-e2e,
  arch-smoke (real archlinux:base, PHP 8.5.10), hermetic-smoke (no-PHP
  container), secrets-scan (gitleaks), llm-gate + live-LLM smoke (Gemini
  flash, exercised and passing from runners).
- README CI badge live: "CI - passing".
- Triggers: `on: push` (all branches), `pull_request` (main),
  `workflow_dispatch` (the verified automation path from the build
  sandbox — OBS-006).
- Repo secret configured: `GEMINI_API_KEY` (Actions only, sealed-box via
  REST; never in git).

## Ops truth for Arch machines (machine-verified in CI)

Current Arch (verified against extra/php 8.5.10-1, php-sqlite 8.5.10-1,
php-gd 8.5.10-1, libzip 1.11.4-1, and the real php.ini inside the
packages):

1. SQLite extensions live in the separate **php-sqlite** package.
2. GD lives in the separate **php-gd** package.
3. The php.ini ships curl+zip pre-enabled; the rest are commented.
4. Remediation (as printed by `./run doctor`, copy-paste safe — no sed
   backreference, no `%b`-eaten escapes):

   ```
   sudo pacman -S --needed php php-sqlite php-gd composer
   sudo sed -ri '/^;(extension=(ctype|curl|dom|fileinfo|gd|iconv|libxml|mbstring|openssl|pdo_sqlite|session|sqlite3|tokenizer|xml|xmlwriter|zip))$/s/^;//' /etc/php/php.ini
   ./run setup && ./run doctor
   ```

## Test & CI surface

- 109 local tests (54 unit incl. 21 ScriptSuite guards / 55 feature+e2e)
  + real-HTTP e2e 22 checks + live-LLM opt-in (passing from runners).
- CI: 12 jobs, latest run success; PHP 8.4 pinned for matrix-free jobs
  (lock floor 8.4.1), PHP 8.5.10 exercised by arch-smoke, static PHP 8.4.23
  by hermetic-smoke.
- PHP_REQUIRED_MODULES now 17 modules (iconv and gd added — both genuinely
  required by the lock / the test suite respectively).

## Known limitations

- Sandbox pushes emit no GitHub events (OBS-006) — CI automation from the
  build environment must dispatch via REST; owner pushes expected to
  trigger normally.
- Live Gemini still geo-blocked from the build sandbox (OBS-002) but works
  from GitHub runners and unrestricted networks.
