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

## RUN-2026-09-06-core-013 — appended project facts (Colombia timezone)

- **The platform runs on Colombia school time** (TASK-015, ADR-025):
  `America/Bogota` (COT, fixed UTC−5, no DST) via
  `APP_TIMEZONE`-overridable config default. ONE timezone, wall-clock
  semantics end to end — `now()`, Eloquent datetime storage, dashboard
  clocks and "today" boundaries are the same Bogota local time; API
  ISO 8601 strings carry `-05:00` explicitly. UTC-store + display
  conversion rejected (single-school LAN: nothing gained, a conversion
  bug class bought).
- **No-DST is load-bearing**: COT has no transitions, so naive `H:i`
  comparisons (the 08:15 late cutoff) are school-local wall time
  forever — pinned by a test asserting −05:00 in January AND July.
- **Test count is 178** (was 174): +4 TimezoneTest; `DocumentationTest`
  pins the `America/Bogota` note in API.md EN/ES.
- Carry-over bench rows created pre-change are UTC-stamped (read 5 h
  off); `./run reset` is the sanctioned re-seed.

## RUN-2026-09-06-core-014 — appended project facts (realtime WebSocket feed)

- **The dashboards update live** (TASK-016, ADR-026): `php artisan
  realtime:serve` — a hand-rolled, zero-dependency pure-PHP WebSocket
  server (stream_select + own RFC 6455 codec, ~270 lines) — polls the
  **events table every ~300 ms** and pushes new taps to every open
  dashboard. No F5, sub-300 ms perceived latency, ~3 tiny SQLite
  queries/second. `./run serve` starts it next to the web server and
  the EXIT trap tears both down; `./run status` reports it.
- **The events spine is the broadcast source** — the tap endpoint is
  byte-identical (empty diff, device protocol untouched), works for
  taps written by ANY process sharing the DB, and the WS process
  dying degrades honestly: SSR rows + Offline badge + reload hint.
  Broadcasting from the tap endpoint was rejected (couples the device
  write path to a UI concern).
- **WS auth = HMAC-SHA256 one-time-window tokens** (`userId.expiry.
  sig`, keyed by APP_KEY, TTL 900 s) minted session-side by
  `GET /realtime/token` (admin/teacher) into the page render;
  re-minted on reconnect. The socket process never parses Laravel
  sessions. Invalid tokens get plain-HTTP 401 before any framing.
- **SSR-first live panel on both dashboards**: rows server-rendered
  from the same `RealtimeFeed::recent()` query the hello frame sends
  (one rendering path, one truth); `public/js/realtime.js` is no-build
  native WebSocket: badge honesty (live/connecting/offline),
  prepend+flash, backoff reconnect 1 s→15 s, `realtime:tap`
  CustomEvent; teacher attendance rows flip live with FIRST-tap-wins
  (matching `classAttendanceToday`).
- **Feed payload mirrors dashboard-visible data only** (no credential
  UIDs) — LAN eavesdroppers learn nothing the login wall doesn't
  gate. No permessage-deflate advertised, 1 MiB inbound frame ceiling
  as an abuse guard.
- **Test count is 211** (was 178): +33 — WsFrame 8 (lengths
  125/126/65536, masking, partial buffers), Handshake 5 (RFC 6455
  worked example), RealtimeToken 6 (expiry/tamper/wrong-key),
  RealtimeFeed 3, token route 3, DashboardTest +5 (SSR, waiting
  state, teacher rows, ES, attribute-JSON escaping — the TASK-014
  Blade lesson applied: attribute context takes the ESCAPED echo,
  script literals the unescaped one), RealtimeServerTest 2 — **real
  sockets against the real command process** (hello+history, live
  broadcast, 401). `./run e2e` is 24 (was 22): realtime probe checks.
- **Browser-proven live** (RUN record): offline badge honesty,
  auto-reconnect, tap→row without reload (flash class), attendance
  row Absent→Late live, first-tap-wins honored, zero console errors.
  Sandbox note (owner unaffected): `artisan serve`'s inner `php -S`
  child filters env vars — the deb-extracted sandbox PHP loses
  PHPRC/LD_LIBRARY_PATH there; raw `php -S` + router, phpunit and
  e2e are fine, the owner's system PHP is immune.

## RUN-2026-09-06-core-015 — appended project facts (UX overhaul, "Calm Ledger")

- **The whole UI is redesigned** (TASK-017, ADR-027): same brand
  (paper/pine/ink + IBM Plex/Space Grotesk), modern soft-card layer —
  10 px radii, two-level elevation, sticky blurred topbar, filled pill
  nav, initials user chip, hero attendance KPI top-left (research
  best practice) with 5 inline stroke-SVG icons, per-class summary
  chips, event chips + avatars in the live feed, amber Late stamps,
  status-card answers, per-button loading spinners on every async
  action, login password reveal + click-to-fill demo chips, pairing
  countdown progress bar, mobile card-stacked tables (data-label),
  44-48 px touch targets. Motion 150-280 ms, hard-off under
  prefers-reduced-motion.
- **The chip tone mapping lives ONLY in CSS** —
  `.live-chip[data-event-type^="CLASS_|PAE_|RECYCLING_|CARD_"]`
  attribute selectors; SSR rows and realtime.js rows both pass the
  raw type through. One source of truth, zero PHP/JS duplication.
- **Live-arrival relative time is honest**: only rows that arrived
  live get "just now → N min ago" (client arrival clock, 15 s
  ticker, falls back to absolute wall time after 10 min). History
  rows keep absolute time — SSR-first, no invented timestamps.
- **ADR-013's marketplace 1:1 token value-match is superseded for
  Core** (ADR-027): brand values kept; radii/shadows/amber/sky/motion
  are Core-only. The marketplace catching up is a marketplace task.
- **All JS-facing selectors survived 1:1** (`.nl-answer` +
  `.answer-ok/.answer-error`, `#pairing-state`, `.arm-btn`,
  `.mode-form/.mode-select`, `.js-tap-status/.js-tap-time`,
  `tr[data-student-row]`, `.stack[data-cutoff]`, `#live-*`,
  `.live-row/.live-new/.live-dot`, `tr.js-row-flash`) — the new UI
  rides on additional elements/classnames, never renamed ones. The
  countdown bar is a SIBLING of `#pairing-state` because the desk
  script rewrites that box's textContent.
- **Test count is 219** (was 211): +8 UX regressions (bootstrap
  rel-time strings, hero+icons+CSS pin, feed avatars/chips + CSS
  tone mapping, summary chips, mobile data-stack, login
  affordances, countdown sibling + idle-hidden). Zero existing
  assertions changed. `./run e2e` 24/24; quality PASS.
- **Browser-proven** (RUN record): every claim above was verified
  live — micro-interactions, live tap with avatar/chip/relative
  time/animation, live row flip, full countdown lifecycle, loading
  states, mobile 390 px card-stack, reduced-motion collapse,
  Spanish, zero console errors. Research sources recorded in the
  RUN ledger (26 sources across dashboard/realtime/empty-state/
  mobile/form UX).

## TASK-018 (RUN-2026-09-06-core-016) — CI red fixed

- **CI had been red since TASK-016** (two merges shipped red —
  4 jobs each: Lint, Scripts lint, Windows smoke, Arch smoke): one
  shellcheck warning, `scripts/serve.sh:77 SC2034` — TASK-016's
  realtime startup probe loop declared `i` but never read it.
  Local gates missed it because `./run quality` runs shellcheck
  only when installed and the sandbox had none; CI installs and
  enforces it.
- **Fix**: the probe counter is now used honestly — the realtime
  startup failure warning reports the probe count ("did not come
  up after N probe(s)", EN+ES; 10 = exhausted, <10 = process died
  early). Loop behavior unchanged. No `shellcheck disable` pragma.
- **Dev-sandbox parity restored**: shellcheck 0.10.0 at
  /home/z/my-project/tools/shellcheck (sandbox artifact, not a
  repo file) — with tools on PATH, the local quality gate is
  byte-for-byte as strict as CI's scripts-lint job.
- Gates unchanged: tests 219/3, e2e 24/24, quality PASS (now with
  shellcheck enforced). GitHub Actions green on main post-push.

## TASK-019 (RUN-2026-09-06-core-017) — Signal: the marketplace's design system, 1:1

- **The UI is "Signal" now** (ADR-028): the marketplace's dark
  editorial system — shadow-grey 950 ground, scarlet CTAs,
  muted-teal data color, tiger-orange sparing accents, ruled
  sections, mono data labels, blurred topbar, anime.js reveals.
  tokens.css carries the marketplace's LITERAL token values
  (ADR-013's value-match contract restored; ADR-027's supersession
  reversed). "0 AI slop" by construction: no gradients, no
  glassmorphism, no decorative emoji — rules, dividers, type.
- **Motion is the marketplace's architecture**: the same vendored
  anime.esm.min.js (md5 fbfdf1a7, byte-identical) + public/js/
  motion.js; the inline .js-motion gate is NEVER added under
  prefers-reduced-motion, so reveals are pure progressive
  enhancement — everything visible without JS.
- **Functional UX gains kept, restyled**: loading spinners,
  per-class summary chips, pairing countdown bar (teal → scarlet
  is-low), demo-chip login + pw reveal, mobile card tables,
  honest empty states. All JS-facing selectors 1:1; realtime.js,
  pairing desk, device + realtime protocols UNTOUCHED. The honest
  "Live feed unavailable — reload the page to see the latest
  taps." degrade re-proven live (badge + hint + auto-reconnect).
- **Test count is 223** (was 219): +4 pins (value-match with hex
  spot-checks, dark ground + focus floor + JS-gated reveals,
  motion module wiring, Signal-palette tone mapping). Zero
  existing assertions changed. quality PASS (shellcheck
  enforced); e2e 24/24. Browser-proven: live tap without reload,
  live row flip, countdown drain, 390 px zero h-scroll on 5 pages
  (one overflow caught + fixed), reduced-motion collapse,
  Spanish, zero console errors.
