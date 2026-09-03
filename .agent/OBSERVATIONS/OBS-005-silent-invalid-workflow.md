# OBS-005 — GitHub silently registers uncompilable workflows (no runs, no errors)

## Date
2026-09-03

## Observation
A workflow file that fails GitHub's compilation — YAML syntax error OR an
illegal expression — is **registered but never scheduled**. Symptoms observed
via the REST API on this repository:

| Signal | Value seen | Meaning |
|---|---|---|
| `GET /actions/workflows` `name` | file **path**, not `name: CI` | file never parsed |
| `GET /actions/runs` `total_count` | 0 | never scheduled, despite matching pushes |
| `POST …/dispatches` | 422 "Workflow does not have 'workflow_dispatch' trigger" | server-side proof |
| Repo `pushed_at` | updated at each push | GitHub DID receive the pushes |

No email, no failed-run entry, no annotation. The Actions tab simply looks
empty, which humans misread as "no CI" — when the actual state is "CI file
present but uncompilable".

## Why it happened here
The first version of `ci.yml` (commit 5d70054) contained BOTH defects: a
colon+space inside an unquoted job display name (`name: Lint (quality gate:
Pint + bash + docs parity)`) — invalid YAML — plus the illegal job-level
`if: ${{ secrets.GEMINI_API_KEY != '' }}`. Every subsequent push re-registered
the same uncompilable file, so the bug survived two task runs.

## Detection recipe (for any future repo)
1. `GET /repos/{o}/{r}/actions/workflows` — if `name` equals the path while
   the file declares `name:`, the file is not compiling.
2. `POST /repos/{o}/{r}/actions/workflows/{file}/dispatches` with
   `{"ref": "<default-branch>"}` — a 422 "does not have … trigger" on a file
   that declares that trigger means compilation failure.
3. Run `actionlint` locally before pushing (now enforced in CI as the
   `workflows-lint` job, ADR-012). It catches both failure classes: YAML
   syntax and expression/context validity.

## Impact on this project
Cost: one owner-visible failure ("GitHub Action stuff… absolutely nothing")
and one repair task (TASK-004). Mitigation now permanent via ADR-012.
