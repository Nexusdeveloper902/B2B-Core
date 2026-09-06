# OBS-014 — audit-verified truth: what the recycling system IS and IS NOT (2026-09-07)

Written by RUN-2026-09-07-audit-001 (independent re-verification of main
@ c582700). This observation records durable facts, not opinions. It does
not supersede anything — OBS-013 and the ADRs remain accurate; this file
exists because the owner distrusts recent history and asked for a
fresh-eyes truth snapshot.

## What the recent commits claimed, and what reproduced

| Claim | Re-verified | Evidence (this run) |
|---|---|---|
| TASK-022 "238 passed/1 skipped/3914 assertions" | YES (+1 test since) | `./run test all` → 239/1/3977 on c582700 |
| RUN-020 "e2e 24/24" | YES | `./run e2e` → 24 passed, 0 failed |
| RUN-020 "CI green on main @ 7511bab (run 34042048148)" | YES | GitHub API: conclusion=success |
| TASK-022 "zero GEMINI_* remains" | YES | rg over tracked source: no hits |
| TASK-023/024 "student card live update" | YES | functional diff + new passing test |
| CI green at HEAD c582700 | YES | run 34043187814 = success |

Live DeepSeek calls: NOT verified (no key in the audit environment) — same
acknowledged limitation the agents themselves recorded honestly.

## The two-sided truth about the owner's recycling spec

The commits are honest ABOUT WHAT THEY BUILT. What they built is a
presence/tap-first platform whose recycling loop is: tap(card) → event →
POST /api/v1/recycling/classify (event_id + image) → MaterialClassifier
(stub|local|deepseek) → deposit + ledger → SUM(delta) balance, plus a
transactional redemption endpoint.

The owner's 47-section spec asks for more. Still missing after 24 tasks:

- ESP32-CAM merged into B2B-Firmware (spec §6/§7) — NO camera code there
- ENTER-triggered capture + trigger abstraction (§2/§8/§37)
- Bottle-first association with pending image + timeout (§3 Case B, §5, §32)
- Image/reference persisted on the deposit (§14)
- classifyAndAward atomic transaction (§15/§18) — see RUN-2026-09-07-audit-001
- Leaderboard anywhere (§22/§28) — zero code mentions
- Student self-service accounts/login (§11/§12) — dashboards are
  admin/teacher/parent-view only
- Recycling WS frames (capture/validating/validated/points/redeemed) (§24–§29)
- Reward type/value/active/stock + redemption records (§19/§20)

Authoritative, actionable version of this list: TASK-025.

## Trust heuristics for future agents

1. The test pyramid, e2e.sh and the CI runs are real and re-runnable —
   they are a valid trust anchor.
2. RUN records in this repo have consistently self-corrected (e.g.
   TASK-007/firmware-007 downgraded an earlier "PROVEN WORKING" claim).
   Read the latest RUN + SNAPSHOT first; distrust any claim with no
   command you can re-execute.
3. The `.agent` task numbering was renumbered mid-history (disclosed in
   c582700's commit message): both the pre- and post-renumber lineages
   live in RUNS/. Cross-reference by date + commit hash, not by number
   alone.
