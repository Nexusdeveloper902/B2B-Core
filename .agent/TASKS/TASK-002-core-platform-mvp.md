# TASK-002-core-platform-mvp

Build the core Presence Platform backend and its role-based dashboards: a Laravel
application that ingests tap events from NFC readers (simulated via Postman/tests
for now, real hardware later with zero backend changes), derives
attendance/PAE/recycling data from a single unified event stream, runs a recycling
points earn-and-spend loop backed by a pluggable material classifier, exposes a
natural-language query interface over the data, and presents Teacher/Admin/Parent
dashboards.

Owner-supplied additions for this run: bilingual EN/ES app+docs, a local-model
classifier path, a Gemini (flash, light usage) LLM key for Phase E, and a
CI+testing mandate (unit/integration/e2e and more).

Priority order: A → B → G(seeders/Postman) → F(teacher/admin) → C → D → E →
F(parent view).

---

## Commit — 5c39a6d
Date: 2026-09-02
Branch: feature/TASK-002-core-platform-mvp
Phase: A

Summary: Laravel 13.30 skeleton (with API scaffolding via `install:api`, Sanctum)
+ all nine migrations, Eloquent models (PresenceEvent→`events` table as the
event-type spine), enums, config/presence + config/recycling, and the bilingual
DemoSeeder that prints every credential_uid and reader api_key to the console
(EN+ES labels — hard requirement).

Changes:
- Migrations: classes, users.role, students, cards, readers, events,
  recycling_deposits, rewards, points_ledger
- app/{Enums,Models}, config/{presence,recycling}.php
- database/seeders/DemoSeeder.php (re-printable, firstOrCreate-idempotent)
- lang/en + lang/es (api, app, auth) foundations
- .env.example with classifier/Gemini/cutoff keys (empty values only)

Verification:
- `php artisan migrate:fresh --seed` runs clean; credentials printed bilingual
- Tap/readers rows verified via sqlite queries

Notes:
- Intermediate routes/providers in early phase commits are skeleton slices of a
  single-run build; the branch tip is the fully verified state (see RUN record).

## Commit — cdf5788
Date: 2026-09-02
Branch: feature/TASK-002-core-platform-mvp
Phase: B

Summary: Core presence loop wiring — bootstrap middleware config (statefulApi
for dashboard fetches, reader.auth + role aliases, API Accept-Language locale,
web session locale) and the tap + reader-mode controllers landed with the Phase A
slice.

Changes:
- bootstrap/app.php (final middleware wiring)
- TapEventController, ReaderModeController, ResolveReaderToken, EnsureRole,
  SetApiLocale, SetWebLocale, TapService, TapEventRequest, ReaderModeRequest

Verification (live, via curl against `php artisan serve`):
- Valid tap → 200 {"status":"ok","event_id",...,"next_step":null}
- Recycling tap → next_step=awaiting_classification, no points at tap time
- Bad token → 401; unknown card → 404 EN / "Tarjeta no reconocida" (Accept-Language: es)
- Mode change: guest 401, admin 200 (PAE_LUNCH), invalid type 422, teacher 403

## Commit — a33a386
Date: 2026-09-02
Branch: feature/TASK-002-core-platform-mvp
Phase: C

Summary: Classification + earn behind the swappable MaterialClassifier contract
(stub default, local HTTP inference driver, optional gemini driver);
ClassificationService idempotency (one tap = one deposit = one award);
PointsService append-only ledger; POST /api/v1/recycling/classify with reader
ownership + type checks + 503-on-driver-failure semantics.

Verification (live):
- Classify returns material/confidence/points/new_balance; retry returns
  already_classified=true with identical values and exactly one ledger row
- Wrong reader → 403; non-recycling event → 422; local driver down → 503
  (covered later by automated tests)

## Commit — 87a8634
Date: 2026-09-02
Branch: feature/TASK-002-core-platform-mvp
Phase: D

Summary: Redemption endpoint (spend half of the loop) — transaction + row lock,
clean 422 with shortfall when insufficient.

## Commit — 7c0a197
Date: 2026-09-02
Branch: feature/TASK-002-core-platform-mvp
Phase: E

Summary: NL query interface — Gemini flash function-calling with a fixed
FunctionRegistry backed by real Eloquent queries; honest 503 blocked states;
Gemini replaces the Azure OpenAI assumption (ADR-006); all v1 API routes wired
(tap, classify, mode, redeem, nl-query).

Notes:
- Live end-to-end blocked from the build environment (Gemini geo-restriction on
  the sandbox network) — documented in the RUN record; works from any
  non-restricted network (owner's local environment).

## Commit — 3d483a5
Date: 2026-09-02
Branch: feature/TASK-002-core-platform-mvp
Phase: F

Summary: Bilingual dashboards — login (admin/teacher), teacher attendance board
(present/late/absent with configurable cutoff), admin dashboard (school-wide
stats, reader mode control, NL box, redemption desk), parent-view timeline; EN/ES
session language switcher; no-npm CSS.

## Commit — 77a3c00
Date: 2026-09-02
Branch: feature/TASK-002-core-platform-mvp
Phase: G

Summary: The full test pyramid — 6 unit classes, API+Web feature/integration
tests, E2E journeys (complete school day, full Spanish experience, lang-key
parity), phpunit suite wiring, live-LLM opt-in pattern, committed-secrets
pattern tripwire.

Verification: `php artisan test` → 88 passed, 1 skipped (live opt-in),
595 assertions. Pint clean (107 files).

## Commit — 6142167
Date: 2026-09-02
Branch: feature/TASK-002-core-platform-mvp
Phase: G

Summary: Postman collection (all endpoints, bilingual, variable-driven auth) +
API.md/.es.md + LOCAL_MODEL.md/.es.md.

## Commit — 5d70054
Date: 2026-09-02
Branch: feature/TASK-002-core-platform-mpp (typo: mvp)
Phase: G/CI

Summary: GitHub Actions CI (lint, unit matrix 8.3+8.4, integration, e2e,
http-e2e, gitleaks, optional live-LLM smoke) + scripts/e2e.sh real-HTTP runner
(22 bilingual checks — all passing) + scripts/local-model-server FastAPI
reference.

Verification: `bash scripts/e2e.sh` → 22 passed, 0 failed (real HTTP).

## Commit — eb7e8cf
Date: 2026-09-02
Branch: feature/TASK-002-core-platform-mvp
Phase: G

Summary: Bilingual README (EN/ES) with quick start, testing, CI, architecture.

## Merge — feature/TASK-002-core-platform-mvp → main
Date: 2026-09-02 (see RUN record for the merge commit hash)

Phases represented: A–G (all).

Verification on main after merge (fresh):
- composer install, artisan key:generate, migrate --seed
- Full test suite green
- Real tap request against a freshly seeded main instance
- scripts/e2e.sh (real HTTP, throwaway DB) green
