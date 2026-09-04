# TASK-009 — Migrate CI actions off the deprecated Node 20 runtime

## Date
2026-09-05

## Status
IN PROGRESS

## Request
Owner: *"Please, using the same protocol, fix the CI"* — the CI shows
warning icons on every green job. State 13/13 green is *green-with-rot*:
all 11 action-consuming jobs carry a deprecation annotation. Same
stateless agent protocol.

## Problem (evidence, not suspicion)

Fresh dispatch run 33922483187 (2026-09-05, main @ 5dccb7d): 13/13 jobs
**success** — but the check-runs annotations API shows, on every job
that consumes an action:

> `[warning] Node.js 20 is deprecated. The following actions target
> Node.js 20 but are being forced to run on Node.js 24:
> actions/checkout@v4, actions/cache@v4, gitleaks/gitleaks-action@v2`

Job conclusions stay `success` — the rot is invisible in status, only
in annotations (extends the OBS-010 lesson: green logs deserve
forensics; here the annotations endpoint is where it hides).

**Deadline (from gitleaks-action v3 migration notes, cross-checked with
GitHub's 2025-09-19 changelog):**
- 2026-06-02 (already passed): runner default flipped to Node 24;
  node20-targeting actions are force-run on Node 24 with warnings.
- **2026-09-16 (11 days away): Node 20 is removed from GitHub-hosted
  runners entirely.** Every `actions/checkout@v4` step — the first step
  of 10 of 13 jobs — will hard-fail. The CI goes fully red without a
  single change in this repository.

## Verified facts (action.yml `runs.using` per tag, via API)

| Pin in repo | runs.using | Target | Latest major line | Breaking-change surface |
|---|---|---|---|---|
| `actions/checkout@v4` (10 uses) | node20 | **v7** (7.0.1, node24) | v7.0.x | v5.1.0/v6.1.0/v7 carry `allow-unsafe-pr-checkout` (2026-06-18 safer defaults) — affects only `pull_request_target` + custom PR-head checkout; this repo uses plain `pull_request` and default ref. Untouched. |
| `actions/cache@v4` (2 uses) | node20 | **v6** (6.1.0, node24) | v6.1.0 | v6.0.0 "migrate to ESM" (internal); inputs path/key/restore-keys unchanged. Untouched. |
| `gitleaks/gitleaks-action@v2` (1 use) | node20 | **v3** (node24) | v3.0.0 | Release notes: "No changes to inputs, outputs, or behavior." We pass only `GITHUB_TOKEN` env. Untouched. |
| `shivammathur/setup-php@v2` (7 uses) | node24 — no warning | **stay** | — | — |

arch-smoke and hermetic-smoke clone with plain `git` (node-free by
design, ADR-010) — they are immune to this class of rot and stay as-is.

## Decision (ADR-018)
Bump to the CURRENT major of each action (checkout@v7, cache@v6,
gitleaks-action@v3), not the oldest node24-capable major: the backport
lines (checkout v5/v6) already carry the same breaking change, so
staying old buys nothing; latest majors give the longest runway. Our
usage touches none of the documented breaking surfaces.

## Scope
- `.github/workflows/ci.yml` ONLY (13 version-token edits:
  10× checkout, 2× cache, 1× gitleaks-action)
- No script, test, or doc changes (verified: no doc/record pins these
  versions; `./run quality` docs-parity surface unaffected).

## Commit plan
1. fix(ci): migrate actions off deprecated node20 runtime (ADR-018)
2. docs(agent): TASK-009 closure records

## Verification plan
- YAML sanity + actionlint happens in the workflows-lint job (it
  downloads the latest actionlint).
- Dispatch the feature branch: 13/13 jobs green.
- **Acceptance = zero deprecation annotations** on every check-run of
  the run (API-verified, not just green conclusions — the exact
  forensics that found this).
- Merge fast-forward to main, push, tip-run green + zero annotations.
