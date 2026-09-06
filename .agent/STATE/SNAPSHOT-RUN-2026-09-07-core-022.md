# STATE SNAPSHOT — after RUN-2026-09-07-core-022

## Overall Status
TASK-025 COMPLETE: the recycling spec's backend half is implemented and
green (270/1-skip tests, 33/33 e2e, quality gate, local CI mirror 3/3).
The firmware + reference-repo halves of the same owner directive ran
concurrently in their repositories this date.

## Completed
- All 8 TASK-025 items: atomic classifyAndAward (transactional deposit+
  ledger, graceful unique(event_id) races), bottle-first pending_captures
  flow with expiry + sweep, image persistence on every deposit,
  ledger-derived leaderboard endpoint, student self-service accounts
  (1:1 users layer), transactional recycling WS frames on a third realtime
  channel, full rewards catalog model (type/value/active/stock +
  reward_redemptions + request_id idempotency), is_bottle/is_recyclable
  boundary schema.
- 31 new tests; e2e extended 24→33 checks (Fase H: bottle-first +
  leaderboard + student login); API docs EN/ES updated with the new
  device contract (capture/associate) the firmware will build against.
- Demo student accounts seeded and printed (maria@/carlos@/ana@/
  diego@presence.test, password).

## In Progress
- B2B-Firmware TASK-008 (same date, other repo): ESP32-CAM merge + ENTER
  capture trigger driving the capture/associate endpoints.
- ESP32-CAM-CV TASK-001 (same date): bridge-or-supersede decision.

## Blocked
- Live DeepSeek verification of the extended prompt (is_bottle /
  is_recyclable) — no key in this environment. Pre-existing, by design.

## Known Problems
- None new. Pre-existing: app.css full-file reformat in c582700 remains
  owner-unreviewed (cosmetic).

## Important Current Facts
- Branch: main (feature/TASK-025-recycling-spec-completion merged,
  commit daee0d7). No application-source changes outside the task scope.
- Recycling device endpoints: POST /api/v1/recycling/capture (multipart
  image) and POST /api/v1/recycling/captures/{id}/associate (JSON
  credential_uid) — both Bearer reader auth, recycling readers only.
- WS frames: tap | pairing (admin) | recycling (all roles; hello carries
  snapshot). recycling_updates is append-only, written inside the state
  change's transaction.
- Redemption API: reason-shaped 422s (insufficient/inactive/out_of_stock/
  duplicate); request_id gives true idempotency (replayed=true replays
  the original answer).
- Leaderboard: every student ranks; competition ranking (1,2,2,4); ties
  by student id; students see 'me' from their account.
- Student web desk: /student, /student/history, /student/rewards —
  server-side scoped; staff dashboards 403 for students.
- Config knobs: RECYCLING_CAPTURE_TTL (300), RECYCLING_CLASSIFY_WINDOW
  (600), RECYCLING_REDEMPTION_DUPLICATE_WINDOW (10),
  RECYCLING_LEADERBOARD_TOP (10).
- Trust anchor: `./run ci` 3/3; test counts 270/1-skip; e2e 33/33.
