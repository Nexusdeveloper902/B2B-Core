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

Requirements: PHP 8.3+ (with `sqlite3`, `pdo_sqlite`, `mbstring`, `curl`,
`zip`), Composer.

```bash
git clone https://github.com/Nexusdeveloper902/B2B-Core.git
cd B2B-Core
composer install
cp .env.example .env          # then set APP_KEY + optional keys below
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed    # the seeder PRINTS all demo credentials 🖨️
php artisan serve             # → http://localhost:8000
```

The seeder prints (in English AND Spanish): the dashboard logins, every
card's `credential_uid`, and every reader's Bearer `api_key` — copy them
straight into [docs/postman_collection.json](docs/postman_collection.json)
variables or curl.

**Demo logins:** `admin@presence.test` / `teacher@presence.test` — password
`password`.

### Optional configuration (never commit real keys!)

```dotenv
GEMINI_API_KEY=               # enables live NL queries (flash models only)
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

## Testing

```bash
php artisan test                    # everything (unit + feature + E2E)
php artisan test --testsuite=Unit   # services, classifiers, NL orchestration
php artisan test --testsuite=Feature# API + web integration tests
php artisan test --testsuite=E2E    # complete bilingual platform journeys

vendor/bin/pint                     # code style (Laravel Pint)

bash scripts/e2e.sh                 # REAL HTTP end-to-end: boots the server,
                                    # seeds, and exercises the whole flow with curl
```

Live LLM smoke tests are **skipped by default** (free-tier friendly); opt in
with `RUN_LIVE_LLM_TESTS=1` plus a real `GEMINI_API_KEY`.

## CI

`.github/workflows/ci.yml` runs on every push/PR: **lint** (Pint),
**unit**, **integration** (feature), **E2E**, a **real-HTTP e2e** job
(`scripts/e2e.sh` against `php artisan serve`), a **secrets scan**
(gitleaks), and an **optional live-LLM smoke job** that only runs when a
`GEMINI_API_KEY` secret is configured.

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
docs/                                     # API.md/.es.md, LOCAL_MODEL.md/.es.md, Postman
scripts/e2e.sh                            # real-HTTP end-to-end runner
scripts/local-model-server/               # reference local classifier sidecar (FastAPI)
tests/{Unit,Feature,E2E}/                 # the test pyramid
.agent/                                   # persistent project memory (append-only)
```

## Status

Built under `TASK-002-core-platform-mvp` — see
`.agent/TASKS/TASK-002-core-platform-mvp.md` for phase-by-phase status and
`.agent/STATE/` snapshots. Phase E live-LLM testing is environment-dependent
(report the blocker honestly, never fake an answer).
