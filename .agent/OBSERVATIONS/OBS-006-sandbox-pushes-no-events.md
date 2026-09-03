# OBS-006 — Pushes from the build sandbox emit no GitHub events (push/PR triggers inert; workflow_dispatch works)

## Date
2026-09-03

## Observation
While activating CI (TASK-004), pushes to `Nexusdeveloper902/B2B-Core` made
from this build environment land correctly (refs update, `pushed_at` moves,
commits verifiable via the REST API) but produce **zero GitHub events**:

- `GET /repos/{o}/{r}/events` → empty (no PushEvent), even minutes after a push
- `GET /users/{owner}/events` → no events for this repo either
- `on: push` never triggers a run for these pushes
- a PR opened via the REST API (`pull_request` event) also produced **no run**

Meanwhile, event-independent paths work perfectly:

- `workflow_dispatch` via `POST /actions/workflows/{file}/dispatches` → 204,
  runs execute normally (this is how runs 1–4 in TASK-004 were created)
- everything else on the repo (contents, blobs, commits, jobs, logs) behaves
  normally over the REST API

## Interpretation
The webhook/event pipeline for pushes originating from this sandbox (and the
API-created PR event) does not fire, while direct API actions do. Earlier
PushEvents exist on the owner account (2026-09-02, other repos), so ordinary
pushes from a normal machine DO emit events.

## Practical consequences
1. **CI automation from this environment must use `workflow_dispatch`** (REST
   dispatch on the default branch), not "push and wait".
2. **The owner pushing from their own machine should trigger CI normally**
   (`on: push` is configured with no branch filter). If pushes from the
   owner's machine ever do NOT trigger runs, revisit this observation —
   that would indicate an account-level event suppression instead.
3. The empty Actions tab seen by the owner was NOT caused by this — it was
   the uncompilable workflow file (OBS-005). Both defects were found while
   diagnosing the same symptom.

## Workaround used
`POST /repos/Nexusdeveloper902/B2B-Core/actions/workflows/ci.yml/dispatches`
with `{"ref": "main"}` after each push that must be validated.
