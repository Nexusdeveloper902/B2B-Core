# STATE SNAPSHOT — after RUN-2026-09-05-core-007

## Repository state

- Branch: main at 4eacd11 (closure records commit on top)
- TASK-009 commits: a22302b (node24 migration), 4eacd11 (live-gate
  transient retry)
- Working tree: clean; no new executable files (OBS-009 not triggered)
- Remote: origin/main == local main; push-triggered tip run 33923747128
  green (13/13) with ZERO annotations

## What the project can do now

- Everything from TASK-002..008 (bilingual platform, ./run suite with
  Windows fallback, real CI 13/13, Event Ledger UI, live NL queries,
  self-explaining LLM failures, llm-check).
- **The CI is deadline-proof past 2026-09-16** (node20 runner removal):
  all actions run on node24 (checkout@v7 ×10, cache@v6 ×2,
  gitleaks-action@v3 ×1, setup-php@v2 already compliant).
- **The live-LLM gate survives Gemini capacity blips** without losing
  honesty: transport-class failures (5xx/timeout) retry up to 3×20s;
  every other failure class — quota, invalid key, region, model,
  wiring, skip — fails immediately and loudly.

## Ops truths (machine-verified this run)

- Green runs can carry platform rot visible ONLY in check-run
  annotations (OBS-011): conclusions said success while 10 jobs warned
  node20 deprecation. The annotations API is part of CI triage now.
- Gemini free-tier 503 "high demand" blips are real and random
  (OBS-012): hit a run 3 minutes after the same test passed; the job
  re-run passed. Bounded reason-scoped retry absorbs the class without
  masking anything (ADR-019).
- workflow_dispatch + push on the same ref race in the concurrency
  group (cancel-in-progress): dispatches supersede push runs — select
  the run by event when polling, or you will poll the cancelled twin.
- Current actions majors as of 2026-09-05: checkout v7.0.1, cache
  v6.1.0, gitleaks-action v3.0.0, gitleaks binary v8.30.1, setup-php
  still on the v2 line (node24 already).

## Repo secrets

- `GEMINI_API_KEY` (Actions only): unchanged this run, still proven
  live (live smoke passed twice this session). Full-diff pattern scan
  since 5dccb7d = 0 matches.

## Open follow-ups

- gemini vision-classifier live path still unexercised (stub/local by
  design).
- Interactions API migration deferred (OBS-008 trigger conditions).
- Annotation forensics (OBS-011 rule) is agent discipline, not yet a
  CI job — a future "deprecation watchdog" step could assert
  annotations_count == 0 on its own run.
- setup-php@v2: last third-party v2 major in the workflow; re-check
  its runtime on the next action sweep.
