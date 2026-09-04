# TASK-010-card-pairing-endpoint

Build the two-step card-pairing capability in the core platform backend:
arm a short-lived pending pairing for a student (dashboard/admin side),
then pair a freshly scanned card to that student (device side, Bearer
reader key) — the endpoint that the reader-firmware repository's
TASK-001-reader-firmware-mvp requires for its PAIRING MODE (its Phase E2
is blocked until this task is merged and verified on main).

This task exists under the narrow, owner-authorized write exception
documented in the firmware task's master protocol (Section 0.3): the
b2b-core repo is otherwise read-only reference for the firmware work.
Nothing else in this repository may change as part of this task.

## Endpoints (per the authorized spec)

```text
POST /api/v1/admin/students/{id}/arm-pairing
  Auth: admin dashboard session or personal access token (the existing
        dual-auth convention of the other admin-side endpoints)
  Effect: creates a short-lived "pending pairing" for that student
          (45 s window — config/presence.php, ADR-020)
  Response 200: { "status": "ok", "student_id": ..., "expires_at": "..." }

POST /api/v1/admin/cards/pair
  Auth: Authorization: Bearer <reader.api_key>   (identical identity
        pattern to the tap endpoint — the reader is who the key says)
  Body: { "credential_uid": "<scanned UID>" }
  Response 200: { "status": "ok", "paired_student_name": "...", "student_id": ... }
  Errors: 409 no active session; 422 card already paired; 401 bad key
```

## Required deliverables (same bar as every other endpoint here)

- Migration for a `pending_pairings` table (id, student_id FK, reader_id
  nullable FK, expires_at, consumed_at nullable, timestamps).
- Feature tests: happy path, no active session (409), already-paired
  card (422), expired session treated as inactive, plus auth/role and
  bilingual message checks.
- docs/API.md + docs/API.es.md + docs/postman_collection.json updated in
  the existing style.
- ADR in .agent/DECISIONS/ recording the design (auth pattern, expiry
  window, why two-step arm-then-pair).
- ARCHITECTURE note for the pending_pairings table and its relationship
  to the existing cards/events/points_ledger schema.
- DocumentationTest parity needles extended so the docs cannot go stale.

## Commit documentation (append-only, below)

---

## Commit — 1 (migration + model + service)
Date: 2026-09-05
Branch: feature/TASK-010-card-pairing-endpoint
Phase: data layer

Summary: `pending_pairings` migration (student FK cascade, reader FK
nullable, expires_at, consumed_at, composite index), `PendingPairing`
model with active scope + casts, `PairingService` (arm/pair with
transaction + lockForUpdate, never-reassign semantics), the
`presence.pairing_window_seconds` config (45 s default, env-overridable)
and its AppServiceProvider singleton binding.

Verification: `php artisan migrate` clean; CardPairingTest suite green
(see commit 2).

## Commit — 2 (endpoints + routes + lang + tests)
Date: 2026-09-05
Branch: feature/TASK-010-card-pairing-endpoint
Phase: HTTP surface

Summary: `ArmPairingController` (admin session/PAT via auth:sanctum +
role:admin, same convention as reader relabeling), `CardPairingController`
(reader.auth Bearer key — same identity plane as tap), `PairCardRequest`
(credential_uid rules identical to TapEventRequest), routes wired with
bilingual EN/ES lang keys (pairing_no_active_session,
pairing_card_already_paired), and `CardPairingTest` — 14 feature tests:
happy path, one-shot consumption, already-paired (incl. inactive-card
rejection + no-reassignment), expiry, most-recent-wins, role/auth on both
planes, PAT arming, user-PAT-is-not-a-reader-key, concurrency
double-consume, bilingual messages, validation 422.

Verification: `php artisan test --filter=CardPairingTest` — 14 passed
(55 assertions); full suite 141 passed (3 known LLM geo-skips); Pint
PASS; `./run e2e` 22/22; live HTTP verification script (throwaway DB,
real `artisan serve`, 2 s window): 20/20 checks — happy path, one-shot,
already-paired keeps owner + session stays armed, window expiry, 401/403
planes, ES messages, paired card immediately tap-able, 404 unknown
student.

## Commit — 3 (docs + Postman + ADR + architecture + parity needles)
Date: 2026-09-05
Branch: feature/TASK-010-card-pairing-endpoint
Phase: documentation

Summary: docs/API.md + docs/API.es.md pairing sections (both endpoints,
all response cases, window semantics, one-shot and no-reassignment
rules); postman_collection.json items 08 (arm, admin PAT) + 09 (pair,
reader key) with new_credential_uid/admin_pat variables; ADR-020 (two-
step arm-then-pair rationale, 45 s window choice, auth split, never-
reassign rule); ARCHITECTURE/card-pairing-flow.md (pending_pairings
schema relationships to cards/events/points_ledger + invariants);
DocumentationTest parity needles extended (bilingual API docs must
document both endpoints; Postman must cover both URLs).

Verification: DocumentationTest green; bilingual parity locked.

