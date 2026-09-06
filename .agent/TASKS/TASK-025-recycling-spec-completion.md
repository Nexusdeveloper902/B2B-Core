# TASK-025-recycling-spec-completion

Status: OPEN (not started)
Created by: RUN-2026-09-07-audit-001 (owner-directed audit — "look at the
latest implementation of the last commits, I don't trust them as much")
Source of requirements: the owner's 47-section task brief "Implement the
Complete Recycling, Student Account, Points, Rewards, and Real-Time
System" (supplied 2026-09-07 with the audit request).

## Why this task exists

The audit verified the recent commits are honest about what they built —
and that what they built covers only the card-first half of the owner's
spec. This file is the verified, evidence-backed gap list. Nothing here
is speculation; each line was confirmed by reading main @ c582700 and
re-running the pyramid (239/1/3977 green) on 2026-09-07.

## Verified working today (do not re-litigate)

- Tap → event → classify(event_id+image) → MaterialClassifier(stub|local|
  deepseek) → deposit + append-only ledger; balance = SUM(delta)
- DeepSeek cost-gating holds by construction for card-first (classify
  requires a tap event, a tap requires a card→student)
- Unique(event_id) prevents double-award; duplicate classify returns
  already_classified=true
- Redemption: DB::transaction + lockForUpdate, 422 shortfall, no negative
  balances; points per material configurable in config/recycling.php
- Migrations+seeders green from scratch (`./run setup`)
- WS server: hand-rolled RFC 6455, HMAC tokens, admin gating; `tap` +
  `pairing` frames; clients never poll
- 239 tests / 24 real-HTTP e2e checks / CI green at c582700

## Gap list (ordered — backend items here; firmware items live in
B2B-Firmware TASK-008)

1. **Atomic classify+award** (spec §15/§18): wrap
   `ClassificationService::classifyAndAward` in a single DB transaction;
   handle the unique(event_id) race gracefully (concurrent loser should
   get the duplicate response, not a raw 500).
2. **Bottle-first flow** (spec §3 Case B, §5, §32): pending image capture
   WITHOUT a student, pending card WITHOUT an image, explicit state
   machine (IDLE/CARD_IDENTIFIED/WAITING_FOR_CAPTURE/IMAGE_CAPTURED/
   WAITING_FOR_CARD/READY_FOR_VALIDATION/VALIDATING/ACCEPTED/REJECTED/
   FAILED), configurable timeouts, expiry of pending events. Design
   note: pending_pairings already models exactly this shape (arm →
   consume/reject with expiry) for card pairing — reuse the pattern.
3. **Image persistence** (spec §14): store the captured image (storage
   disk or BLOB per architecture conventions) and keep a reference on
   the recycling event/deposit for auditability.
4. **Leaderboard** (spec §22/§28): global ranking from the real points
   ledger, student's own rank, consistent tie-breaking, WS updates.
5. **Student self-service accounts** (spec §11/§12/§30): login for
   students referencing the existing students rows (1:1 account layer —
   do NOT create a second student identity), own points/history/
   rewards/redemptions views, server-side authorization (students see
   only their own data).
6. **Recycling WS frames** (spec §24–§29): frames for capture created /
   validation started / validated / points awarded / reward redeemed /
   leaderboard updated, pushed only from committed DB state (the
   poll-then-push `events`-table pattern in RealtimeServeCommand is the
   proven template — extend it to the new tables, never broadcast
   pre-commit).
7. **Rewards model** (spec §19/§20): type/value/active/stock columns and
   a persistent reward_redemptions record per redemption (today only a
   points_ledger row with reward_id); prevent over-redemption of
   limited stock; double-submit protection.
8. **AI result schema** (spec §9): keep material_class but consider
   adding is_bottle/is_recyclable semantics at the boundary (backend
   owns all business rules either way — spec §10 already honored).

## Acceptance criteria

- All of the above implemented inside the existing Laravel architecture
  and conventions; no detached second app.
- `./run test all` + `./run e2e` extended and green; CI green.
- The two demonstration flows of the spec (§38 card-first, §39
  bottle-first) both work end-to-end against real HTTP, with no
  DeepSeek call before student association (assert it in tests).
- Pending-event timeout proven by test (expiry → no award, no leak).

## Out of scope here

- ESP32-CAM firmware merge / ENTER trigger / IR abstraction →
  B2B-Firmware TASK-008.
- Vision-pipeline-to-backend bridge → ESP32-CAM-CV TASK-001.
- IR sensor hardware (spec §2 explicitly postpones it).

## Resolution (append 2026-09-07, RUN-2026-09-07-core-022)

Status: COMPLETED — all 8 items implemented, verified, merged to main
(commit daee0d7). Evidence: `./run ci` 3/3; 270 passed/1 skipped/4170
assertions (31 new tests); e2e 33/33 (Fase H adds bottle-first,
leaderboard, student login). Design decisions: ADR-031 (pending
captures), ADR-032 (commit-only frames), ADR-033 (1:1 student
accounts), ADR-034 (redemption idempotency), ADR-035 (ledger-derived
leaderboard). The remaining spec halves were never backend work:
firmware items ran as B2B-Firmware TASK-008 and ESP32-CAM-CV TASK-001
the same date. Open limitation: live DeepSeek behavior of the extended
prompt (is_bottle/is_recyclable) unverified until an owner-provided key.
