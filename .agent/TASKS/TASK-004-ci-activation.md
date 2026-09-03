# TASK-004 — CI Activation (fix silently-dead GitHub Actions workflow)

## Date
2026-09-03

## Status
COMPLETED — see RUN-2026-09-03-core-003

## Request
Owner checked the GitHub **Actions tab and saw absolutely nothing** — no runs,
no jobs, nothing — despite TASK-002/003 having pushed `.github/workflows/ci.yml`.
Owner demands *actual* GitHub CI (real runs in the Actions tab), delivered under
the same stateless agent protocol as TASK-002/003.

## Root cause (diagnosed from live GitHub API evidence, not guesswork)
The workflow was dead for **two stacked reasons**, both present since its first
push (commit 5d70054):

**1. Primary: the file was never valid YAML.** Job name line:

```yaml
name: Lint (quality gate: Pint + bash + docs parity)   # colon+space in a plain scalar
```

A `: ` inside an unquoted YAML value is a syntax error ("mapping values are
not allowed here") — reproduced locally with PyYAML against the exact blob
pushed to origin/main (blob 5be2215). GitHub never parsed the file at all.

**2. Secondary (latent): invalid expression after YAML would parse.** The
`live-llm-smoke` job used the `secrets` context in a **job-level `if:`**:

```yaml
if: ${{ secrets.GEMINI_API_KEY != '' }}   # INVALID at job level
```

GitHub's context-availability rules do **not** allow the `secrets` context in
`jobs.<job_id>.if` (allowed there: `github, needs, strategy, matrix, vars,
inputs`). Fixing the YAML alone would have produced a workflow that STILL
failed to compile — just with a different silent failure mode.

When a workflow fails to compile, GitHub still *registers* the file but
cannot extract its `name` or triggers. Hard evidence gathered via the REST
API before any fix:

- `GET /actions/workflows` → workflow listed with **name = file path**
  (".github/workflows/ci.yml") instead of `name: CI` → GitHub never parsed it
- `GET /actions/runs` → **total_count: 0** (zero runs ever, despite pushes to
  main at 2026-09-02T22:34Z and 2026-09-03T00:45Z which the repo `pushed_at`
  confirms GitHub received)
- `POST /actions/workflows/ci.yml/dispatches` → **422 "Workflow does not have
  'workflow_dispatch' trigger"** although the file contains it — server-side
  proof of failed compilation
- Blob SHA of remote file == local file (byte-identical, valid YAML) → the bug
  was a *semantic* expression error, not corruption/truncation

## Deliverables
1. **Fixed workflow** — job-level `secrets` if replaced with the canonical
   gate-job pattern (`llm-gate` with `needs`/`outputs`; `secrets` read in job
   `env` where it IS allowed).
2. **Guard rail so this never happens again** — new `workflows-lint` CI job
   running `actionlint` (which specifically implements GitHub's
   context-availability table and would have flagged this exact bug).
3. Hardening: workflow-level `permissions: contents: read`, `concurrency`
   group, per-job `timeout-minutes`.
4. `GEMINI_API_KEY` configured as a repo secret so the optional live-LLM smoke
   job actually exercises (single flash call per run, `continue-on-error`).
5. Verified **real runs in the Actions tab, green**, from the pushed workflow.

## Phases
- A: diagnosis from GitHub API evidence; task record, ADR-012, OBS-005
- B: workflow fix (gate pattern + actionlint job + hardening)
- C: local validation (actionlint, YAML sanity, `./run quality`)
- D: push to main → run appears → iterate until green
- E: run record, ledger, state snapshot, PROJECT.md facts
