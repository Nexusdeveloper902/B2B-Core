# STATE SNAPSHOT — after RUN-2026-09-03-core-002

## Repository state

- Branch: main (merged from feature/TASK-003-run-script-suite, fast-forward)
- Base: edbc48a (TASK-002 platform) + 5 TASK-003 commits
- Working tree: clean (generated state gitignored: vendor/, .env, .tools/,
  .model-server.pid, scripts/local-model-server/.venv)

## What the project can do now

- **Everything from TASK-002** (bilingual Laravel 13 platform: tap events,
  recycling classify→earn→spend, NL query, dashboards, 88-test pyramid).
- **Operated through one entry point**: `./run setup` + `./run serve` replaces
  the ~10-step manual flow; 11 documented subcommands; bilingual output.
- **Runs on Arch** with a machine-verified remediation path (doctor prints
  the pacman + php.ini fix; CI applies it on a real archlinux:base container).
- **Runs with ZERO system PHP** via `./run toolchain` (hermetic static
  PHP 8.4.23 + Composer 2.10.3 in .tools/) — proven by a CI container job
  with no PHP installed at all.

## Test & CI surface

- 108 tests (88 platform + 20 ScriptSuiteTest) + 1 opt-in live-LLM skip.
- Real-HTTP e2e: 22 checks (bilingual), runs against a throwaway DB via the
  same toolchain resolution.
- CI jobs: lint (quality gate), scripts-lint, unit (8.3/8.4 matrix),
  integration, e2e, http-e2e, arch-smoke, hermetic-smoke, gitleaks,
  optional live-LLM smoke. All jobs dogfood `./run` commands.

## Known states / limitations

- Live Gemini calls remain geo-blocked from this run environment (OBS-002);
  protocol covered by mocks + opt-in CI job.
- laravel/boost not installed (OBS-004 — no app-code scope in this run).
- Hermetic static PHP is x86_64-only (doctor says so; distro PHP otherwise).
- Midnight-flakiness in FunctionRegistryTest fixed via travelTo (2026-09-03);
  TapEventTest has time-relative input values but no date-filter assertions
  (verified passing post-midnight).

## Next natural steps (suggestions, not commitments)

- Real hardware integration (ESP32 firmware pointing at the documented
  endpoints — zero backend changes needed by design).
- Real CV model behind the local classifier contract (swap the FastAPI
  placeholder).
- A future ops run could add: `./run model` into `./run ci`, doctor --json,
  systemd user units for serve/model.
