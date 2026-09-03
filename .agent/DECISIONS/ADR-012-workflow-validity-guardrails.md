# ADR-012

## Date
2026-09-03

## Context
TASK-002/003 pushed a CI workflow that GitHub **silently never ran**: the
Actions tab showed zero runs. Diagnosis (see TASK-004 for the API evidence
trail) showed **two stacked defects, both present since the first push**:

1. **YAML syntax error** — `name: Lint (quality gate: Pint + bash + docs
   parity)` contains a colon+space inside an unquoted plain scalar, which is
   a parse error ("mapping values are not allowed here"). GitHub never parsed
   the file: the workflow was registered under its file path with no
   triggers, producing zero runs with zero error messages.
2. **Invalid expression (latent)** — `live-llm-smoke` used the `secrets`
   context in a **job-level `if:`**, where only `github, needs, strategy,
   matrix, vars, inputs` are allowed. Fixing the YAML alone would have moved
   the failure, not removed it.

## Decision
1. **actionlint as a mandatory, first CI job (`workflows-lint`).** actionlint
   validates the *entire class* of defects that killed this workflow: YAML
   syntax (including colon-in-plain-scalar traps), GitHub expression
   semantics, and context availability (it specifically rejects `secrets` in
   job-level `if`). Workflow files are now first-class linted artifacts, like
   PHP (Pint) and bash (shellcheck).
2. **Canonical gate-job pattern for secret-dependent jobs.** A tiny
   `llm-gate` job reads the secret where it IS legal (job-level `env`), emits
   a boolean output, and the gated job uses job-level
   `if: needs.llm-gate.outputs.enabled == 'true'` — `needs` IS allowed there.
3. **Quoted display names.** Job display names containing colons (or other
   YAML-significant characters) are always quoted — the literal one-character
   class of bug that silently killed CI twice.
4. **Least privilege + blast-radius hardening** for the workflow itself:
   `permissions: contents: read` at workflow level, a `concurrency` group
   that cancels superseded runs on the same ref, and `timeout-minutes` on
   every job so a hung runner can never hold the pipeline.

## Alternatives Considered
- **Move the check into a step-level `if`** — valid (step `if` allows
  `secrets`), but every step needs the condition repeated; easy to miss one
  and still perform side-effectful steps (checkout, setup-php) for a job that
  should not run at all.
- **Repo variable (`vars.LIVE_LLM == 'true'`)** — allowed in job-level `if`,
  but splits configuration across two mechanisms and cannot hold the secret
  value itself; the gate job keeps everything in one place.
- **Do nothing / trust review** — rejected: the bug already shipped twice
  across two task runs and was only caught because the owner opened the
  Actions tab. Silent-failure classes need automated guards, not vigilance.

## Consequences
- The Actions tab shows real runs again; the README CI badge (already
  present since TASK-002, previously dead) goes live.
- Any future workflow edit that references an unavailable context, or that
  breaks YAML syntax, fails the `workflows-lint` job loudly instead of
  silently disabling CI — the failure mode that cost this project two task
  runs of invisible CI.
- The optional live-LLM smoke only runs when `GEMINI_API_KEY` is configured
  as a repo secret; otherwise `llm-gate` emits a visible skip notice —
  "skipped by design" instead of "file never compiled".
