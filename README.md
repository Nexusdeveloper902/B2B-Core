# Presence Platform — Core Platform (Backend + Dashboards)

> **Read this in:** [Español](README.es.md)
>
> The card is the identity, the platform is the intelligence. One NFC card
> per person; every tap becomes a labeled presence event; attendance,
> school feeding (PAE) and recycling incentives are all **derived views over
> that single event stream**.

[![CI](https://github.com/Nexusdeveloper902/B2B-Core/actions/workflows/ci.yml/badge.svg)](https://github.com/Nexusdeveloper902/B2B-Core/actions/workflows/ci.yml)

## What this is

A Laravel 13 application that ingests **tap events** from NFC readers
(simulated today via Postman/tests — real ESP32 hardware later with **zero
backend changes**), and derives three applications from one unified event
stream:

1. **Attendance tracking** — the foundational application
2. **PAE (school feeding program)** — mandatory breakfast/lunch tracking
3. **Recycling incentives** — tap → classify material → **earn** points →
   **spend** them on rewards (a real earn+spend loop with a verification
   step, unlike points displays with no spend mechanism)

Plus two AI components that close real gaps:

- A **computer-vision material classifier** at the recycling station, behind
  a swappable interface — runs as a **local model** by design
  ([docs/LOCAL_MODEL.md](docs/LOCAL_MODEL.md))
- A **natural-language query interface** over the event database using
  Gemini function-calling — the LLM selects functions, the backend computes
  the real answers

The app is fully **bilingual (English / Spanish)**: UI, device-facing API
messages (`Accept-Language`), seeder output, docs and tests.

## Quick start

The **`./run` suite** is the only thing you need to remember — one command
per operation, fully documented in [docs/SCRIPTS.md](docs/SCRIPTS.md) ·
[docs/SCRIPTS.es.md](docs/SCRIPTS.es.md), Linux-first with **Arch support**
and a Windows fallback that is auto-detected:

```bash
git clone https://github.com/Nexusdeveloper902/B2B-Core.git
cd B2B-Core
./run setup     # deps + .env + APP_KEY + database + demo data — prints credentials 🖨️
./run serve     # → http://127.0.0.1:8000  (health: /up)
```

`./run setup` is idempotent and safe to re-run at any time; under the hood it
performs `composer install`, creates `.env` (never overwriting an existing
one), generates `APP_KEY`, then `php artisan migrate --seed` — the seeder
prints all demo credentials bilingually (EN/ES). No usable PHP yet?
`./run doctor` prints the exact fix for your distro, or `./run toolchain`
provisions a hermetic PHP+Composer with zero system packages.

**On Windows** (Git Bash) install Git for Windows + PHP
(`winget install PHP.PHP-8.4`) and the same commands work unchanged; from
cmd/PowerShell use `run.cmd setup` / `run.cmd serve` — it delegates to the
same suite (Windows runs as a fallback, auto-detected — see the Windows
section in docs/SCRIPTS.md).

**Demo logins:** `admin@presence.test` / `teacher@presence.test` — password
`password`.

### Optional configuration (never commit real keys!)

```dotenv
GEMINI_API_KEY=               # enables live NL queries (flash-family models,
                             # default gemini-3.1-flash-lite). After setting it,
                             # verify from THIS machine with: ./run llm-check
RECYCLING_CLASSIFIER_DRIVER=stub   # stub | local | gemini
LOCAL_CLASSIFIER_URL=http://127.0.0.1:8501/v1/models/material:predict
ATTENDANCE_LATE_CUTOFF=08:15  # teacher-dashboard "late" cutoff
```

## Try the core loop in 60 seconds

```bash
READER_KEY=<classroom key from the seeder output>

# 1. A tap → an event
curl -X POST http://localhost:8000/api/v1/events/tap \
  -H "Authorization: Bearer $READER_KEY" -H "Accept: application/json" \
  -d '{"credential_uid":"<student card uid>"}'

# 2. Spanish device messages
curl -X POST http://localhost:8000/api/v1/events/tap \
  -H "Authorization: Bearer $READER_KEY" -H "Accept-Language: es" \
  -H "Accept: application/json" -d '{"credential_uid":"NOPE"}'
# → {"status":"error","message":"Tarjeta no reconocida"}
```

Full endpoint documentation: [docs/API.md](docs/API.md) ·
[docs/API.es.md](docs/API.es.md) ·
[docs/postman_collection.json](docs/postman_collection.json)

## Dashboards

| Route | Role | Shows |
|---|---|---|
| `/login` | — | EN/ES login |
| `/teacher` | teacher (or admin) | Today's class attendance: present / late (after configurable cutoff) / absent |
| `/admin` | admin | School-wide stats (attendance, PAE breakfast/lunch, recycling items+points), reader mode control, NL query box, redemption desk, parent-view links |
| `/parent/students/{id}` | admin/teacher | One student's full event timeline (simplified parent stand-in — a real parent-auth system is intentionally out of scope) |

Language switch: `EN·ES` in the navbar (session-based).

### Design system — "The Event Ledger"

The dashboards share one visual identity with the marketplace storefront:
porcelain-paper ground, hairline rules, tabular data set in mono, pine-green
accents, sharp 2px control radii, self-hosted Space Grotesk / IBM Plex fonts.
Tokens live in `public/css/tokens.css` (source of truth) and are
value-matched to the marketplace repo — see
`.agent/DECISIONS/ADR-013-design-tokens-value-matched.md` and
`.agent/DECISIONS/ADR-014-shared-layout-components.md`.

## Testing

```bash
./run test                     # everything (unit + feature + E2E)
./run test unit                # services, classifiers, NL orchestration
./run test feature             # API + web integration tests
./run test e2e                 # complete bilingual platform journeys

./run quality                  # Pint + bash syntax + shellcheck + docs parity
./run e2e                      # REAL HTTP end-to-end: boots the server,
                               # seeds a throwaway DB, exercises the whole flow
./run ci                       # everything CI runs, locally, in order
```

Each command accepts `--help`. Live LLM smoke tests are **skipped by
default** (free-tier friendly); opt in with `RUN_LIVE_LLM_TESTS=1` plus a real
`GEMINI_API_KEY`.

## CI

`.github/workflows/ci.yml` runs on every push/PR: **lint** (Pint),
**unit**, **integration** (feature), **E2E**, a **real-HTTP e2e** job, a
**secrets scan** (gitleaks), an **optional live-LLM smoke job** — plus three
jobs that continuously verify the `./run` suite itself: **scripts-lint**
(bash syntax + shellcheck on every script), **arch-smoke** (the suite on a
real `archlinux:base` container), **windows-smoke** (the suite on real
windows-latest via Git Bash — the auto-detected fallback) and **hermetic-smoke** (the full setup in a
container with **no PHP at all**). CI jobs dogfood `./run setup --ci` and
`./run e2e` on every push.

## Architecture in one paragraph

`events` is the **event-type spine**: one tap = one row
(`card_id`, `reader_id`, `type`, `occurred_at`). Attendance, PAE and
recycling numbers are **always derived** from that table — never stored
separately — so the three applications can never drift apart. Readers
authenticate with a static Bearer key (the key IS the reader identity).
Points live in an append-only `points_ledger` (balance = `SUM(delta)`).
The classifier is a contract (`MaterialClassifier`) resolved from config —
stub today, a local inference service at later stages, without touching a
single controller. See `.agent/ARCHITECTURE/` for details and
`.agent/DECISIONS/` for ADRs.

## Repository layout

```
app/
├── Contracts/MaterialClassifier.php      # the swappable classifier contract
├── Enums/                                # EventType, MaterialClass, ...
├── Http/Controllers/Api/V1/              # tap, classify, redeem, mode, nl-query
├── Http/Controllers/Web/                 # login + dashboards
├── Models/                               # Eloquent (events = PresenceEvent)
└── Services/                             # Tap, Points, Attendance, Recycling, NlQuery
database/migrations/ + seeders/DemoSeeder.php   # schema + bilingual credential printing
docs/                                     # API, LOCAL_MODEL, SCRIPTS (.md/.es.md), Postman
run + scripts/ + scripts/_lib/common.sh   # the ./run suite (one command per operation)
scripts/local-model-server/               # reference local classifier sidecar (FastAPI)
tests/{Unit,Feature,E2E}/                 # the test pyramid
.agent/                                   # persistent project memory (append-only)
```

## Status

Built under `TASK-002-core-platform-mvp` — see
`.agent/TASKS/TASK-002-core-platform-mvp.md` for phase-by-phase status and
`.agent/STATE/` snapshots. Phase E live-LLM testing is environment-dependent
(report the blocker honestly, never fake an answer).
