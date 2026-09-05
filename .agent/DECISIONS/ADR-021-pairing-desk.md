# ADR-021: Pairing desk — one-click arming on the dashboard

## Status
Accepted (2026-09-05, TASK-011-dashboard-pairing-desk)

## Context

Since TASK-010 (ADR-020), pairing a new student's card requires the
admin to POST `/api/v1/admin/students/{id}/arm-pairing` — in practice:
mint a personal access token in tinker, then curl per student, per
card, every time. The owner's bench report (2026-09-05): "I don't
really want to make an individual manual post request each time I wanna
pair some new student." ADR-020 itself recorded the dashboard "Pair new
card" button as a deliberate follow-up; this decision implements it.

Design questions to settle:
1. Where does the UI live, and who does it authenticate as?
2. Does arming need a new write path?
3. How does the operator see the result without the serial monitor?

## Decision

**A dedicated admin page (`/admin/pairing`, "Pair cards") whose buttons
POST to the EXISTING TASK-010 arm endpoint under the admin's own web
session — plus a strictly read-only status endpoint it polls.**

1. **No new write path.** The page adds zero ways to mutate pairing
   state: arming stays exactly the ADR-020 POST (`auth:sanctum` +
   `role:admin`, reached session-authed via `statefulApi()` — same
   pattern the admin dashboard's mode/redeem/NL forms already use), and
   pairing stays reader-side. No PAT, no curl, no new trust assumption.
2. **`GET /api/v1/admin/pairing/status`** (admin session/PAT,
   read-only) feeds the page: the armed session (student +
   `seconds_left`), the last completed pairing, and the 8 most recent
   completions. The page polls ~every 2 s while a session is armed, so
   the operator sees "card 62041607 → Maria González" the moment the
   reader consumes the session — no serial monitor, and no write
   anywhere near the polling path.
3. **`pending_pairings.card_id`** (nullable FK, stamped in
   `PairingService::pair()`) is the audit column that makes the history
   exact: a completed pairing points at the very `cards` row it
   created. Seeded demo cards (fabricated by the seeder, never paired)
   never appear in the history — it shows real pairings only.
4. **Server-rendered first.** The page renders armed state, student
   table, and history from plain Blade/PHP; the script only adds the
   countdown ticking, polling, and in-place history refresh. Same
   no-build-stack convention as the rest of the dashboards (ADR-013/014
   tokens + components).

## Consequences

- One-click arming per student: click → 45 s window → tap the fresh
  card at the reader → success line + history row appear on the page.
- `pair`'s payload/behavior is unchanged (firmware TASK-004 contract
  untouched); `PairingService` gained read-side helpers
  (`activeSession`, `recentCompletions`) shared by both controllers.
- A second consumer of the pairing state now exists (the page) — the
  task file records the polling interval (2 s) and the 8-row history
  cap as tuning knobs if the desk ever feels laggy.
- Still deliberately NOT built (recorded as follow-ups in TASK-011):
  mass-pairing workflows, reader-scoped arming, expired-row cleanup.
