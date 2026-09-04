# OBS-012 — Gemini 503 "high demand" blips can fail an otherwise-perfect run

Date: 2026-09-05 · Discovered in: TASK-009 (run 33923179031)

## Observation
Run 33923179031 (tip push of a22302b, 12/13 otherwise green) failed
the Optional live-LLM smoke with:

```
nl_query.transport_failure: HTTP 503 UNAVAILABLE: This model is
currently experiencing high demand. Spikes in demand are usually
temporary. Please try again later.
```

Evidence chain that this is Google-side capacity, not repo state:
- The same commit passed the same test on the branch dispatch
  (33922978231) 3 minutes earlier.
- The commit (a22302b) touches only action version pins — zero LLM
  code.
- The GitHub **re-run of the failed job alone** passed one minute
  later (run flipped to success).
- The laravel.log tail named the exact blocked reason
  (`llm_unavailable`) — the ADR-016 taxonomy doing its job.

## Consequence adopted (ADR-019)
The live gate now retries the transport-failure class only
(`llm_unavailable`: 5xx high-demand, timeouts, connection resets),
bounded at 3 attempts / 20s apart. Quota (429), invalid key, region,
model and wiring errors still fail immediately; a persistent transient
still fails red; skips still hard-fail (OBS-007).

## Rule going forward
- A single 503 from a free-tier flash model is weather, not news:
  absorb it with bounded, reason-scoped retries — never with
  `continue-on-error` (that is the masked-failure trap of TASK-006
  again).
- When a live gate fails, pull the job log BEFORE changing anything:
  the ADR-016 blocked-reason line states the verdict in one line.
- Re-run-failed-jobs is the cheapest transience probe: if the rerun
  is green, the failure is environmental — then harden the gate, not
  the code.
