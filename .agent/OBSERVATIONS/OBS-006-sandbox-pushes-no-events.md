# OBS-006 — GitHub event warm-up: early pushes triggered nothing; later pushes DO trigger

## Date
2026-09-03 (updated same day after further evidence)

## Observation timeline (all UTC)
- Pushes at 2026-09-02T22:34Z and 2026-09-03T00:45Z (workflow file still
  uncompilable, OBS-005): refs updated, `pushed_at` moved, but **zero
  PushEvents and zero runs**.
- Pushes at 01:2x–01:5xZ (workflow already valid, runs existing): still no
  push-triggered runs; a PR opened via REST at 01:34Z produced PR events
  in the events feed but **no `pull_request` run**.
- Push of e47fafe at **01:56:34Z: a run with `event: push` WAS created** —
  then auto-cancelled by the concurrency group (`CI-refs/heads/main`,
  `cancel-in-progress: true`) because a manual dispatch landed seconds
  later on the same ref.

## Interpretation (updated)
The event/trigger pipeline for this repository needed a warm-up period —
most plausibly it only fully registered the (newly valid) workflow's
triggers some time after the first successful run. What looked like
permanent event suppression during diagnosis was transient: **push
triggering works now**. `workflow_dispatch` via REST was and remains a
guaranteed automation path. Cancelled runs sharing the concurrency group
are deduplication by design, not failures.

## Practical guidance
1. Automation from the build environment: dispatch via REST works
   unconditionally; pushes also work as of 01:56Z (verify per-repo if it
   matters).
2. Rapid successive triggers on the same ref: expect the older in-flight
   run to be cancelled (concurrency, ADR-012 hardening) — this is
   intentional feedback-latency optimization, not flakiness.
3. If push triggers ever appear dead again on a fresh repo, check OBS-005
   first (uncompilable workflows produce zero runs with zero errors) —
   the two observations look identical from the Actions tab.
