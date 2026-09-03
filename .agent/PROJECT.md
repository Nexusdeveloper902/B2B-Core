# Project: Presence Platform — Core Platform

## What this is
The real product. A student/staff identity platform built around a single NFC card
per person. The card stores only a credential ID. Independent physical readers feed
one central backend. Core framing: "the card is the identity, the platform is the
intelligence." The architecture is a presence-event model: tap → identify →
timestamp → labeled event. Three applications share this same event stream:

1. Attendance tracking (the foundational application)
2. PAE (school feeding program) — mandatory breakfast/lunch attendance tracking
3. Recycling incentive system — tap → classify → award points, with a real earn
   AND spend loop (this is the explicit differentiator against a known competitor
   project, "Ecopuntos," whose points display had no earn mechanism, no spend
   mechanism, and no verification step)

Two AI/ML components exist because they close real gaps, not for decoration:
- A computer-vision material classifier at the recycling station, triggered by a
  card tap before points are awarded (closes the verification gap)
- A natural-language query interface over the event database, using LLM
  function-calling, for live-demo value with school staff

## CRITICAL: Hardware abstraction principle
No physical hardware (ESP32, NFC readers, cameras) exists yet at the time this
project starts. EVERY hardware integration point in this backend MUST be designed
as a plain HTTP endpoint with a stable, documented JSON/multipart contract that:
(a) can be fully exercised today using Postman or automated tests with fabricated
    data standing in for real device input, and
(b) will require ZERO backend changes when real hardware is wired up later — only
    firmware/software on the device side needs to start calling the same URLs with
    the same payload shapes.
Do not design any endpoint, auth mechanism, or data flow that assumes a specific
piece of hardware exists. Treat "a reader" as "anything that can make an
authenticated HTTP POST request," whether that's Postman, a test script, or an
ESP32 in the future.

## Relationship to the marketplace app
Independent codebase (see TASK-001-marketplace-mvp in the separate marketplace
repo). No shared code, no shared database, no runtime dependency in either
direction. The marketplace links to this platform conceptually in the pitch, not
in code.

## Data
SQLite for now (`database/database.sqlite`), consistent with the project's other
app. Sufficient for demo scale (a handful of students/cards/readers, low event
volume). Migrating to a server-based DB later is a config change, not a rewrite,
because all access goes through Eloquent.

---

## RUN-2026-09-02-core-001 — appended project facts (first implementation run)

This run built the full TASK-002 MVP (Phases A–G). Repository reality updates:

- **Framework**: Laravel 13.30 (PHP 8.3+), SQLite. No npm/Vite build is required
  for the dashboards (hand-rolled CSS; the vite config remains only as skeleton
  residue and is unused).
- **Bilingual requirement (owner-supplied, supersedes any monolingual reading of
  the original task)**: the app works in English AND Spanish — dashboard UI
  (session locale switcher), device-facing API messages (Accept-Language header),
  seeder console output, README/API/LOCAL_MODEL docs, and lang-key parity tests.
- **Classifier strategy (owner-supplied)**: the platform is intended to run fully
  locally at later stages, including the classification model. Implemented as a
  swappable `App\Contracts\MaterialClassifier` with three drivers: `stub`
  (default), `local` (HTTP contract for a local inference service — see
  docs/LOCAL_MODEL.md), `gemini` (optional cloud fallback). Swapping is a .env
  change only.
- **LLM provider**: the owner supplied a Gemini API key (free tier, flash models
  only, light usage) — this replaces the Azure OpenAI assumption in the original
  task text (ADR-006). Live end-to-end NL testing was BLOCKED from this build
  environment by a Google geo-restriction on the sandbox's network location; the
  endpoint honestly reports 503 blocked states and works wherever the Gemini API
  is reachable (the owner's local environment).
- **Testing**: 88 automated tests (unit / feature-integration / E2E suites) +
  a real-HTTP e2e script (`scripts/e2e.sh`, 22 bilingual checks) + GitHub
  Actions CI (lint, matrix unit, integration, e2e, http-e2e, gitleaks, optional
  live-LLM smoke).

---

## RUN-2026-09-03-core-002 — appended project facts (run-script suite run)

This run added the operations layer (TASK-003, ADR-009/010/011):

- **`./run` is THE entry point** for operating the platform: 11 subcommands
  (setup, serve, test, e2e, quality, doctor, status, reset, model, toolchain,
  ci) delegating to standalone scripts in `scripts/` that share
  `scripts/_lib/common.sh`. Quick start is now `./run setup && ./run serve`.
- **No script calls a bare `php`** — every invocation goes through the
  resolution chain B2B_PHP → PATH → `.tools/php` (ADR-010). Composer (a phar)
  is likewise always executed via the resolved PHP, so the whole suite works
  on machines with no php on PATH.
- **Hermetic toolchain is a first-class path** (`./run toolchain`): static
  PHP + Composer into gitignored `.tools/`; proven by CI on a no-PHP
  container. The owner's future local runs can use either system PHP (Arch
  remediation printed by doctor, machine-verified by CI's archlinux:base
  job) or the hermetic path.
- **Bilingual invariant extended to operations**: script output, --help text,
  and docs/SCRIPTS.md + .es.md; ScriptSuiteTest fails the build if a command
  loses its bilingual documentation.
- **CI now dogfoods the suite** on every push, and `./run ci` mirrors the
  pipeline locally.
- Fixed pre-existing latent defects found while verifying: a
  FunctionRegistryTest date-boundary flake (would fail any post-midnight CI
  run) and a Pint style drift in bootstrap/app.php.

## RUN-2026-09-03-core-003 — appended project facts (CI activation run)

This run made the GitHub Actions pipeline REAL (TASK-004, ADR-012):

- **The Actions tab was empty because the workflow never compiled**: the
  first ci.yml had (1) a colon+space inside an unquoted job display name —
  a YAML syntax error — and (2) `secrets` context in a job-level `if` —
  illegal per GitHub's context-availability table. GitHub registers
  uncompilable workflows under their file path with NO triggers: zero
  runs, zero errors, anywhere a human would look (OBS-005).
- **`workflows-lint` (actionlint) is now the first CI job** — it rejects
  exactly that defect class (YAML syntax, expression semantics, context
  availability, shellcheck of run blocks). Workflow files are linted
  artifacts like PHP (Pint) and bash (shellcheck).
- **Secret-dependent jobs use the canonical gate pattern** (ADR-012):
  `llm-gate` reads GEMINI_API_KEY in job `env` (where `secrets` IS legal)
  and emits a boolean output; `live-llm-smoke` gates on
  `needs.llm-gate.outputs.enabled`. The key is stored ONLY as a GitHub
  Actions repo secret (libsodium sealed box via REST) — the live smoke
  passes from runners with a single flash call.
- **Current Arch packaging truth (machine-verified against extra/php
  8.5.10-1, php-sqlite, php-gd)**: sqlite3/pdo_sqlite live in the separate
  `php-sqlite` package; gd in `php-gd`; the ini ships curl+zip
  pre-enabled. `./run doctor` prints the complete remediation; the sed
  form is backreference-free and `printf %b`-safe (the old
  `extension=\1` form double-prefixed into `extension=extension=X`, which
  made PHP abort ini parsing — and silently dropped `extension=zip`).
- **PHP_REQUIRED_MODULES is now 17 modules** (iconv + gd added: the
  composer lock requires ext-iconv; the test suite requires ext-gd).
- **PHP 8.3 legs were dropped from CI** (the lock requires >= 8.4.1);
  8.4 is pinned for standard jobs, 8.5.10 is exercised by arch-smoke, and
  the static PHP 8.4.23 by hermetic-smoke.
- **Trigger reality (OBS-006)**: pushes from the build sandbox emit no
  GitHub events — automation dispatches via REST `workflow_dispatch`;
  `on: push` (all branches) + `pull_request` are configured for normal
  machines.
- CI is green: 12/12 jobs on b0fd17d (run 33705375607); README badge live
  ("CI - passing").
