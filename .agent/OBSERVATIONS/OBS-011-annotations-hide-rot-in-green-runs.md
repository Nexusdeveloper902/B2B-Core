# OBS-011 — Platform deprecations hide in green runs (annotations, not conclusions)

Date: 2026-09-05 · Discovered in: TASK-009 (run 33922483187 forensics)

## Observation
Run 33922483187 completed **success 13/13** — while EVERY
action-consuming job carried a `[warning]` annotation: *"Node.js 20 is
deprecated. The following actions target Node.js 20 but are being
forced to run on Node.js 24: actions/checkout@v4, actions/cache@v4,
gitleaks/gitleaks-action@v2."*

Job conclusions never showed it. The Actions UI surfaces it only as a
yellow icon you must click into. The only reliable detection is the
check-runs annotations API:

```
GET /repos/{owner}/{repo}/commits/{sha}/check-runs
  → output.annotations_count per job
GET /repos/{owner}/{repo}/check-runs/{id}/annotations
  → the actual warning text
```

This is OBS-010's lesson ("green logs of new platforms deserve
forensics") generalized: **the annotations endpoint is where platform
rot hides in green pipelines.**

## Why it mattered here
GitHub removes node20 from hosted runners on **2026-09-16**. After
that date every `actions/checkout@v4` step — the first step of 10 of
13 jobs — hard-fails. The repository would have gone fully red with
zero repo-side changes (ADR-018 has the migration).

## Rule going forward
- When asked to "fix the CI" while everything looks green: dispatch a
  fresh run and inspect annotations, not just conclusions.
- When a platform layer (runner runtime, action major, base image)
  announces a removal date, migrate BEFORE the deadline and verify
  acceptance as "green AND zero deprecation annotations".
- A green run with annotations_count > 0 is green-with-rot: triage it
  the same session.
