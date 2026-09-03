# OBS-007 — Green CI can mask a never-running optional job (masked-skip flaw)

Date: 2026-09-03
Discovered in: TASK-006 (but the flaw shipped in TASK-002/TASK-004)

## Observation

The `live-llm-smoke` job was designed with `continue-on-error: true` and
an opt-in live test. Combined with a key-delivery bug, this produced a
**silent no-op**: the live test skipped, the job went green, the run went
green — for weeks. The TASK-004 ledger line "live-LLM smoke PASSED from
runners" was recorded from the job conclusion alone and was actually a
masked skip. The owner's dead key therefore went unnoticed: CI looked
healthy while the headline feature had never once executed live.

Contributing chain (each layer individually reasonable, together deadly):

1. `continue-on-error: true` — a *failing* smoke becomes a green job.
2. Opt-in skip semantics — a *skipping* test is a normal, expected state.
3. Key delivered to `.env` while `phpunit.xml` pins `GEMINI_API_KEY=""`
   (force=false) — phpunit-level env beats dotenv; the key never arrived
   (see TASK-006 root cause 1).
4. No assertion anywhere that, **given a configured key**, the live test
   must actually execute.

## Rule extracted

An optional job must distinguish its three states explicitly:

- **not configured** → job skipped by the gate (by design, fine);
- **configured and skipped** → **hard failure** (wiring bug);
- **configured and failing** → **visible failure** (the thing you wanted
  to know about).

Never combine `continue-on-error` with opt-in skip semantics on the same
job. The current ci.yml implements the rule: skip-detection + no
continue-on-error + raw-diagnostics probe + laravel.log tail on failure.

## Applies to

Any future "optional integration" job (payment sandbox, email provider,
hardware simulator): the same three-state rule and skip-detection pattern.
