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

## TASK-005 — UI passover facts (RUN-2026-09-02-ui-passover-001)

- **The UI is now "The Event Ledger"**: paper #F3F4F0 ground, pine
  #0A5C38 accents, ink #101D18 text, hairline rules, mono ledger data,
  2px control radii, self-hosted Space Grotesk + IBM Plex Sans/Mono.
  Tokens live in `public/css/tokens.css` — value-matched 1:1 from the
  marketplace repo @ ecde2d5 (ADR-013; cross-repo consistency contract
  in ARCHITECTURE/value-matched-design-tokens.md).
- **One shared layout**: every page extends `layouts.app` (topbar with
  wordmark/tap mark, role-aware nav, EN/ES langswitch, ink footer).
  Repeated elements are anonymous components: `panel`, `stat`, `stamp`,
  `empty`, `field` (ADR-014).
- **Load-bearing JS contract**: the admin dashboard's inline script
  rewrites `className` on answer boxes (`nl-answer` +
  `answer-ok`/`answer-error`, `.hidden`) and queries fixed element
  ids — restyling must preserve those names.
- **`resources/css/app.css` is dead scaffold** (Laravel Tailwind
  default, never built — the app has no build step). Removal is
  recorded as follow-up work.
- **Mobile tables scroll, never crush**: `.ledger-table` min-width
  540px inside `.ledger-wrap` (overflow-x). Page-level overflow is
  zero at 390px (DOM-verified).
- **A11y floor**: skip link, `:focus-visible` outlines, WCAG contrast
  audited 22/22 pairs, `prefers-reduced-motion` honored.

---

## RUN-2026-09-03-core-004 — appended project facts (Gemini key + model swap)

This run rotated the dead AIza key to the owner's new AQ-format key and
made the live NL-query feature actually, verifiably work. Repository
reality updates:

- **LLM model**: `gemini-3.1-flash-lite` is the default everywhere
  (`GEMINI_MODEL` / `GEMINI_VISION_MODEL` config defaults, .env.example,
  GeminiClassifier, AppServiceProvider, bilingual docs). Owner directive.
- **The live NL-query path works end-to-end** — first time in project
  history: CI run 33786816821 shows the live test passing (3.33s) with a
  real function-calling round-trip. Everything before TASK-006 was a
  masked skip (OBS-007).
- **Gemini 3.x wire contract** (ADR-015, unit-locked): function
  declarations use lowercase OpenAPI types; the model turn is echoed
  back verbatim (raw `parts`, preserving `thoughtSignature`).
- **Test secrets must be process env, not .env**: phpunit.xml env entries
  (force=false) outrank dotenv — the CI smoke job passes
  `GEMINI_API_KEY` as step env. Appending to `.env` is a silent no-op.
- **The live-LLM smoke job is self-verifying**: skip-with-key = hard
  failure; failure tails laravel.log (blocked queries log their exact
  cause there — the API response stays generic); the raw probe prints
  the bare Google error + reachable flash models.
- **Leak tripwires** (DocumentationTest): AIza…, AQ.… (new AI Studio
  key format), ghp_… — all pattern-scanned over tracked files in every
  CI run (plus gitleaks).
- **Sandbox geo-block (OBS-002) stands**: Gemini live calls from the
  build sandbox are refused by region; the CI runner is the designated
  live verifier (differential auth evidence: fake key 401, real key
  geo-400, runner 200).

---

## RUN-2026-09-04-core-005 — appended project facts (docs pull + error taxonomy)

This run pulled the actual Gemini API docs and made every LLM failure
self-explaining. Repository reality updates:

- **Error taxonomy per Google's documented contract** (ADR-016):
  `GeminiClient` parses `{error: {code, message, status, details[].reason}}`
  and maps to typed exceptions — region check runs FIRST (both key and
  region failures are plain 400s; region errors carry no reason detail,
  key errors carry `API_KEY_INVALID`). Distinct `blocked_reason` values
  with bilingual actionable messages; raw cause logged to laravel.log.
- **`./run llm-check`** is the designated LOCAL diagnosis tool: one bare
  live call, Google's exact verdict for THIS machine, EN/ES guidance,
  exit 0/1/2, no PHP needed. CI proves the config; llm-check proves the
  environment — complementary truths (ADR-016).
- **The old AIza key is still valid** (OBS-008 corrects TASK-006): an
  invalid key returns 400 API_KEY_INVALID; the old key returns the
  REGION refusal — it authenticates fine.
- **Colombia is a supported Gemini region**; local region refusals
  point at the egress path (VPN/proxy/ISP), not the country.
- **generateContent is Legacy** in Google's own page metadata; the
  Interactions API (`POST /v1beta/interactions`) is the strategic
  endpoint — migrate only on sunset/404 (OBS-008).
- **git core.fileMode=false strips +x from NEW scripts** at commit
  time (OBS-009): always `git update-index --chmod=+x` for new
  executables; ScriptSuiteTest is the CI net that catches it.

---

## RUN-2026-09-04-core-006 — appended project facts (Windows fallback run)

This run added Windows as an auto-detected fallback for the ./run suite.
Repository reality updates:

- **The suite is one codebase across OSes** (ADR-017): `common.sh`
  resolves `B2B_OS` once at source time (uname MINGW/MSYS/CYGWIN =>
  windows; WSL => linux and uses the normal path incl. hermetic .tools);
  an explicit `B2B_OS` env var overrides detection — that is the test
  seam and the power-user escape hatch.
- **Windows candidate set**: `.tools/php` (Linux ELF) is NEVER probed on
  windows; Composer probes `composer` → `composer.bat` → `composer.cmd`
  → `composer.phar` → `.tools/composer`, with wrappers validated AND
  invoked direct-only (OBS-010: php-mediated validation false-positives
  on .bat/.cmd text).
- **`./run toolchain` on windows = composer.phar only**; PHP comes from
  winget/choco/scoop/php.net (guidance printed by resolve_php, doctor,
  and toolchain itself). The zero-system hermetic PHP path is
  Linux-only by binary reality.
- **`run.cmd`** is the cmd/PowerShell entry: finds Git Bash (B2B_BASH
  override → well-known paths → PATH) and forwards to the same bash
  dispatcher — no command routing in it (single-source dispatch,
  ADR-009).
- **`.gitattributes` line-ending contract**: `*.sh` + `run` LF,
  `*.cmd`/`*.bat` CRLF (merged with the skeleton's `* text=auto eol=lf`
  + diff/export rules — a wholesale Write initially clobbered them;
  caught via git status and restored).
- **CI is 13 jobs now**: windows-smoke (windows-latest, Git Bash) is
  non-optional and runs setup --ci → doctor → choco shellcheck →
  quality → test → e2e. Proven green on the first dispatch
  (33815966369) and after the OBS-010 fix (33816386157); tip-of-main
  push run 33816810463 green.
- **Windows e2e truths**: native curl.exe cannot read msys /tmp paths
  (the e2e test image is project-relative); Git Bash has no pgrep/pkill
  (taskkill //F //T //PID via /proc/<pid>/winpid is the fallback);
  Windows venvs put binaries in `.venv/Scripts/`.
- **ScriptSuiteTest is 31 tests** (10 Windows contracts: source +
  behavioral B2B_OS=windows simulations + linux counter-test locking
  .tools probing). On windows runners 5 skips are by-design OS-guards.
- **Green logs of NEW platforms deserve forensics too**: the OBS-010
  false-positive was found in a SUCCESSFUL windows job's log.

## RUN-2026-09-05-core-007 — appended project facts (CI node24 + live-gate hardening)

This run fixed the CI the day the owner asked — nothing was red, but
everything was warned. Repository reality updates:

- **"Fix the CI" with a green board means: go one level deeper.** The
  fresh dispatch (33922483187) was 13/13 success, yet every
  action-consuming job carried the node20-deprecation annotation
  (OBS-011). Job conclusions hide platform rot; the check-runs
  annotations API shows it.
- **The node20 deadline was real and near**: GitHub removes node20
  from hosted runners on 2026-09-16; `actions/checkout@v4` (first
  step of 10 of 13 jobs) would have hard-failed the whole pipeline
  with zero repo-side changes. All actions now run node24
  (ADR-018): checkout@v7, cache@v6, gitleaks-action@v3;
  setup-php@v2 was already node24 and stays.
- **Action majors are chosen by verification, not vibes**: each
  target's `action.yml` `runs.using` was checked via API before
  editing; current majors beat oldest-compatible majors because the
  backport lines carry the same breaking changes anyway and this
  repo's usage (plain pull_request, default ref, standard cache
  inputs) touches none of them.
- **The live-LLM gate retries, honestly (ADR-019)**: after a tip run
  failed on Google's documented-transient 503 "high demand"
  (transience proven by a 1-minute-later green job re-run, OBS-012),
  the gate now retries ONLY the transport class
  (`llm_unavailable`), max 3 attempts / 20s, with visible
  `::warning::` annotations. Quota, invalid key, region, model,
  wiring errors and skips still fail immediately — this is retry,
  never masking.
- **Acceptance for CI fixes is "green AND zero annotations"**: proven
  on branch dispatch 33922978231 and main tip push 33923747128 (both
  13/13, 0 annotations).
- **Concurrency behavior is a feature to remember**: a dispatch on a
  ref cancels that ref's in-flight push run (cancel-in-progress);
  poll by event=workflow_dispatch or you watch the cancelled twin.

## RUN-2026-09-05-core-008 — appended project facts (card pairing endpoint)

This run built the two-step card-pairing capability (TASK-010, ADR-020)
that the reader-firmware repository's TASK-001 requires for its PAIRING
MODE — the owner-authorized narrow write exception; everything else in
this repo was untouched. Repository reality updates:

- **Card pairing is a real, tested capability now** — previously the
  seeder fabricated cards and the docs recorded pairing as a gap.
  `POST /api/v1/admin/students/{id}/arm-pairing` (admin session/PAT)
  arms a 45 s pending pairing (`PAIRING_WINDOW_SECONDS` overridable);
  `POST /api/v1/admin/cards/pair` (reader Bearer key — the tap
  endpoint's identity plane) links the next fresh card UID. One-shot
  consumption (row-locked), never-reassign, most-recent-wins.
- **The firmware repo is unblocked**: B2B-Firmware TASK-001 Phase E2 can
  now implement pairing calls against main. Cross-references both ways:
  firmware RUN-2026-09-03-firmware-001 ↔ this repo's TASK-010.
- **Test count is 141** (was 127): +14 CardPairingTest cases; the full
  suite, Pint, `./run e2e` (22/22) and a live curl pairing verification
  (20/20, 2 s window incl. expiry) all pass on this machine.
- **DocumentationTest parity needles extended**: the bilingual API docs
  and the Postman collection MUST cover the pairing endpoints — docs
  cannot go stale now.
- **pending_pairings is transient by design** (see
  ARCHITECTURE/card-pairing-flow.md): rows expire or are consumed within
  seconds; cards/events/points_ledger semantics are unchanged (a paired
  card immediately tap-works; pairing awards nothing).
- Deferred follow-ups (in the task file): dashboard "Pair new card"
  button (explicitly out of scope of the firmware protocol), mass
  pairing, reader-scoped arming, expired-row cleanup job.

## RUN-2026-09-05-core-009 — appended project facts (dashboard pairing desk)

This run built the pairing desk (TASK-011, ADR-021) — the ADR-020
deferred follow-up, triggered by the owner's "no manual post request
per student" bench report. Repository reality updates:

- **Pairing arming is a UI action now**: `/admin/pairing` ("Pair
  cards" in the admin nav, session-authed, bilingual) — per-student
  "Arm pairing" buttons POST to the UNCHANGED TASK-010 endpoint via
  statefulApi (same pattern as the mode/redeem/NL forms). No PAT, no
  curl, and no new write path exists.
- **`GET /api/v1/admin/pairing/status`** is the read-only feed the
  page polls (~2 s while a session is armed): pending session
  (student + seconds_left), last completed pairing, 8-row history.
  DocumentationTest now pins its coverage in API.md/.es.md + Postman.
- **`pending_pairings.card_id`** (nullable FK, stamped in
  `PairingService::pair()`) is the audit column making the history
  exact: a completed pairing points at the very cards row it created.
  Seeded demo cards never appear in the history.
- **Test count is 155** (was 141): +14 (7 Api PairingStatusTest, 7 Web
  AdminPairingDeskTest — incl. ES translation and role walls).
- The page follows the no-build stack (Blade + inline JS, Event Ledger
  tokens/components, server-rendered state first); the polling JS is
  bench-verifiable only — recorded as the honesty boundary.
- Cross-repo note: B2B-Firmware's canonical PAIRING.md should point at
  this page as the recommended arming path (that repo's next task).

## RUN-2026-09-05-core-010 — appended project facts (stateful LAN access)

- **The default Sanctum stateful list includes the request's own host**
  (config/sanctum.php, `Sanctum::currentRequestHost()` placeholder —
  TASK-012/ADR-022): any host that serves the app is first-party for
  its same-origin dashboard fetches. Phones on the LAN log in and drive
  the pairing desk with no .env change (DHCP-proof).
  `SANCTUM_STATEFUL_DOMAINS`, when set, replaces the default entirely
  (the empty-uncommented-value trap is documented in .env.example).
- **Why this exists**: web login from a phone worked (host-only session
  cookie) but `auth:sanctum` API fetches 401'd — Sanctum session-auth
  applies only to requests whose Referer/Origin host is stateful, and
  the stock default covers only localhost/127.0.0.1/::1/APP_URL.
- **Device endpoints are unaffected**: readers send no Referer/Origin,
  so `fromFrontend()` is false and their Bearer flow stays stateless
  (pinned by StatefulDomainMatchingTest: no-referer → not stateful).
- **Testing pitfall (recorded in RUN-010)**: full-stack session tests
  cannot honestly diff this middleware — the guard/session are shared
  across requests within a test, keeping users authenticated
  regardless; the fromFrontend() level is what tests pin.
- **Test count is 161** (was 155): +6 StatefulDomainMatchingTest.
- Cross-repo note: the same bench session's device-side 401s were the
  FIRMWARE's Basic/Bearer scheme bug — fixed in B2B-Firmware TASK-007
  (its main @ f325b2e); the backend pair contract was verified correct
  and untouched here.

## RUN-2026-09-05-core-011 — appended project facts (unpair-every-card script)

- **`./run unpair` / `php artisan cards:unpair --force`** (TASK-013,
  ADR-023) is the pairing bench reset: deletes every `cards` row (tap
  events cascade, `pending_pairings.card_id` cleared, history rows
  survive) so every credential is fresh/pairable again. Guarded by a
  bilingual confirmation at BOTH layers unless `--force`; empty table
  is a noop; `./run reset` restores the demo cards.
- **Why delete and not student_id-null**: freshness = row
  non-existence (ADR-020 invariant 2 — any existing row 422s); a
  student_id-nulling "unpair" would be a fake one.
- **The owner's bench loop is test-pinned**: pair → unpair → re-pair
  the SAME credential_uid to another student with clean event history
  (UnpairCardsCommandTest, 5 tests).
- **Test count is 168** (was 161): +6 command tests, +1
  ScriptSuiteTest provider case for the new `unpair` command.
- No new routes, no new write paths — the device protocol is
  untouched; dev-side reset only, on purpose.

## RUN-2026-09-05-core-012 — appended project facts (desk honesty, TASK-014)

- **The pairing desk broke after the FIRST completed pairing** (the
  owner's "button says nothing / F5 doesn't help" bench report,
  reproduced live in a real browser): Blade's escaped echo rendered
  the desk script's `lastSeenUid` JSON literal as `&quot;…&quot;` →
  fatal SyntaxError → dead arm buttons + no polling on every reload.
  Fixed by unescaped-echo JSON literals (+ regression test that
  renders the desk WITH a completed pairing — the state TASK-011's
  tests never exercised).
- **Rejected taps are desk-visible now**: `pending_pairings.
  last_rejected_uid/_reason/_at` (migration 000003), stamped inside
  the pair transaction; `GET /api/v1/admin/pairing/status` reports
  `pending.last_rejection`; the desk shows the bilingual note with the
  remediation (different card / `./run unpair`). Window stays armed.
  No new write path; device contract unchanged.
- **Desk state machine honest**: success persists; "expired" only for
  real expiry; 2 s active / 15 s quiet idle polling; the old eternal
  3 s "Window expired" flashing loop (which overwrote the success line
  ~7 s after a good pairing) is gone.
- **Test count is 174** (was 168): +3 PairingStatusTest, +3
  AdminPairingDeskTest (incl. the script-syntax regression).
- Blade lessons recorded: `{{ }}` echo is forbidden for JS string
  literals; brace pairs in comments are parsed by Blade (reword).
