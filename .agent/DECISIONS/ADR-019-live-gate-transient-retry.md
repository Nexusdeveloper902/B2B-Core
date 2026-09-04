# ADR-019 — Bounded, reason-scoped transient retry in the live-LLM gate

## Status
ACCEPTED (2026-09-05, TASK-009)

## Context
ADR-016 drew a sharp line: the Gemini **probe** step is
diagnostics-only and tolerates transient network errors, while the
**live test** is the authoritative gate and must fail red (OBS-007: a
configured key that does not PROVE itself = failure; the masked-skip
era of TASK-006 must never return).

Run 33923179031 (tip push of a22302b) exposed the gap in that line:
the live test failed with Google's own documented-transient error —
`HTTP 503 UNAVAILABLE: This model is currently experiencing high
demand. Spikes in demand are usually temporary. Please try again
later.` The same test had passed on the branch dispatch 3 minutes
earlier and on the immediate job re-run. A single-attempt gate turns
every Google capacity blip into a red pipeline — noise that erodes
trust in the gate (crying-wolf risk).

## Decision
**The gate retries, but only the transport-failure class, bounded at 3
attempts, and everything else still fails immediately.**

1. The retry trigger greps the blocked payload the API echoes for
   `llm_unavailable` — the ADR-016 taxonomy class that covers 5xx
   "high demand", timeouts and connection resets. Invalid key
   (`llm_invalid_key`), region (`llm_region_unsupported`), quota
   (`llm_rate_limited`), model (`model_not_found`) and wiring failures
   do NOT match: they fail on attempt 1, honestly and fast.
2. Bounded: up to 3 attempts, 20s apart. A transient that survives all
   attempts still fails RED with the laravel.log tail — OBS-007
   honesty is preserved (this is retry, not masking).
3. The skip-detection check (`skipped` in the log = wiring bug) runs
   BEFORE any failure handling and still hard-fails unconditionally.
4. Each retry emits a `::warning::` annotation naming the attempt and
   the reason — visible in the job log, never silent.

## Consequences
- Verified by local simulation of all three paths: transient-that-
  clears → 1 retry → green; invalid-key → 0 retries → red;
  persistent-503 → 3 attempts → red.
- Worst-case added wall time when Google is genuinely down: ~40s of
  backoff inside a 10-minute job timeout.
- The probe-vs-test boundary from ADR-016 shifts slightly: BOTH may
  now retry documented transients, but only the test remains the gate
  — the distinction is now "diagnostics never fail the job; the gate
  fails after honest bounded retries", not "only the probe tolerates
  reality".
- Quota (429) deliberately stays NON-retryable: RPM spikes self-heal
  in seconds but daily-quota exhaustion is a usage signal the owner
  must see, not a blip to sleep through.
