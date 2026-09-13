# ADR-055 — DB-backed runtime settings (settings table + SettingsService)

## Date
2026-09-14

## Context
The safe presence/PAE knobs lived in `.env` (PAIRING_WINDOW_SECONDS,
ATTENDANCE_LATE_CUTOFF, STUDENT_EMAIL_DOMAIN, ...) and the new meal
windows would have joined them. The spec requires admins to configure
these through a UI, persisted and used by the runtime — without
exposing secrets or requiring file edits + restarts.

## Decision
1. **`settings` table** (`key` unique, `value` json) + a canonical key
   registry in `SettingsService` (the ONE authority for shape,
   validation and defaults): meal windows, late cutoff, pairing
   window, student email domain, initial password. Secrets never join
   the registry.
2. **Fallback chain**: settings row → config default (env-overridable:
   `PAE_BREAKFAST_START` etc. in `.env.example`) — a fresh clone works
   with zero rows, an admin override is live for the very next request.
3. **Validation**: dotted keys are nested paths to Laravel's validator
   (a flat payload looks EMPTY to the rules — converted with
   `data_set` before validating); cross-field rules (windows must not
   overlap, end after start) + per-field rules; partial batches
   validate the MERGED view so one bad field can never corrupt the
   rest. The settings controller maps ValidationException to a 422
   with per-field errors.
4. **Runtime consumers rewired**: late cutoff, pairing window, account
   conventions and the meal engine all resolve through the service
   (per-request static cache, flushed after writes and between tests —
   RefreshDatabase recreates the table).
5. **Surfaces**: GET/PUT `/api/v1/admin/settings` (admin-only) + the
   `/admin/settings` desk (bilingual, marks customized fields); both
   seeders write the canonical defaults as rows so the desk shows
   configured values and the e2e can widen the windows via env before
   seeding.

## Alternatives Considered
- config cache writes (`config()->set` persistence): not durable, and
  per-process.
- A typed column per setting: schema churn for every future knob; the
  key registry already gives typed access + validation.

## Consequences
- Settings changes are behavior changes: SettingsTest pins that a
  saved window steers the next tap and a saved cutoff flips the next
  late computation.
- The e2e exports wide window env vars BEFORE seeding (the seeder
  copies config → rows), keeping the HTTP contract suite deterministic
  at any wall-clock time.
- A settings-cache flush hook in TestCase (static cache would leak
  across RefreshDatabase boundaries).

## Status
ACTIVE
