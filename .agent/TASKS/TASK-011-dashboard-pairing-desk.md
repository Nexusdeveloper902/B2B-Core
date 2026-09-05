# TASK-011-dashboard-pairing-desk

Owner's bench report (2026-09-05, after TASK-010 + the firmware pairing
work was live on their desk): "I don't really want to make an individual
manual post request each time I wanna pair some new student." Owner
delegated placement: "you decide where, maybe in the core repo but it's
up to you." This repo is the right home — arming is a backend-side
admin decision by design (ADR-020), and the dashboard session removes
the PAT+curl ceremony entirely. ADR-020 had already recorded the
dashboard "Pair new card" button as a deliberate follow-up; this task
implements it (ADR-021).

## Deliverables

1. `GET /admin/pairing` (web route, auth + role:admin, name
   `admin.pairing`) — the pairing desk page:
   - Students table (name, class, current card UID or "no card") with a
     one-click **Arm pairing** button per row.
   - The buttons POST to the EXISTING TASK-010 endpoint
     (`/api/v1/admin/students/{id}/arm-pairing`) session-authed via
     statefulApi — NO new write path.
   - Live status box: server-rendered armed state + JS countdown from
     `expires_at`; polls the status endpoint every 2 s while armed;
     shows the success line the moment a card is paired; shows
     "window expired — arm again" on expiry; brief poll tails after
     completion/expiry to catch rapid re-arms.
   - "Recently paired cards" history table (server-rendered, refreshed
     in place from the status JSON).
   - Nav link in the shared layout (admin section), bilingual
     EN/ES (lang keys, session locale).
2. `GET /api/v1/admin/pairing/status` (api route, `auth:sanctum` +
   `role:admin`, name `api.v1.pairing.status`) — read-only JSON:
   `pending` (student + expires_at + seconds_left) | null,
   `last_pairing` | null, `recent_pairings` (8 most recent
   completions). Never writes.
3. Migration `2026_09_05_000002_add_card_id_to_pending_pairings_table`:
   nullable FK `card_id` → cards (null on delete); stamped in
   `PairingService::pair()` — the audit column that makes the history
   exact (a completed pairing points at the exact cards row it
   created). `PendingPairing` gains the `card` relation + fillable.
4. `PairingService` read-side helpers `activeSession()` +
   `recentCompletions(limit)` shared by both controllers (thin
   controllers, one domain service).
5. Feature tests: `tests/Feature/Web/AdminPairingDeskTest.php`
   (guest redirect, teacher 403, student table + arm buttons + endpoint
   wiring, server-rendered armed state, history rows, full ES
   translation, nav link) + `tests/Feature/Api/PairingStatusTest.php`
   (guest 401, teacher 403, empty state, armed + countdown bounds,
   expired not pending, completed pairing incl. card_id audit stamp +
   reader label, newest-first history) — 14 tests.
6. Docs parity: docs/API.md + API.es.md (status endpoint section +
   dashboard-shortcut note + auth table row), postman item 10,
   DocumentationTest needles extended (API docs + postman URL),
   README.md + README.es.md dashboards table row,
   ARCHITECTURE/card-pairing-flow.md updated (card_id row, desk-UI flow
   box, invariant 5).
7. `.agent` records: ADR-021, this task file, RUN-2026-09-05-core-009
   + ledger, STATE snapshot, PROJECT.md facts appended.

## Out of scope (deliberately)

- Mass-pairing / bulk arming (violates the one-human-decision-per-card
  model; not asked for).
- Reader-scoped arming, expired-row cleanup jobs (still TASK-010
  follow-ups).
- Any change to the pair endpoint, the 45 s window default, or the
  firmware contract (firmware TASK-004 docs get a POINTER to this page
  in that repo's own task cycle — not here).
- Real-browser/JS behavior verification (Laravel feature tests assert
  the rendered contracts: endpoint URLs, button labels, state text;
  the countdown/polling loop is bench-visible).

## Commit documentation (append-only, below)

---

## Commit — 1 (full feature, single-commit landing)
Date: 2026-09-05
Branch: feature/TASK-011-dashboard-pairing-desk

Summary: page + status endpoint + card_id migration + service read
helpers + 14 tests + full bilingual docs/records parity (ADR-021).
Verification on this machine: `./run quality` PASS (Pint + shell +
bilingual parity), `./run test` 155 passed / 3 skipped (141 prior + 14
new), `./run e2e` 22/22.

Notable fixes during the run (kept as environment lessons in RUN-009):
PHPUnit's own final `status()` method shadowing test helpers (renamed
to `getStatus`), Carbon 3 signed `diffInSeconds` (the countdown was
inverted → 0), and a JS ticker-stacking bug caught in review (one
global 1 s ticker, started once).
