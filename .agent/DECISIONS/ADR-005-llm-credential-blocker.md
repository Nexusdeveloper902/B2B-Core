# ADR-005

## Date
2026-09-02

## Context
Phase E depends on an external LLM credential that may be absent, rate-limited,
or unreachable in any given environment. The original protocol demands the phase
never be silently skipped or faked when a credential is missing.

## Decision
Treat LLM unavailability as a scoped, honest BLOCKER — never as a fake success:
- No key configured → `503 {"status":"blocked","blocked_reason":"missing_llm_credential"}`.
- Rate limited → `blocked_reason: "llm_rate_limited"`.
- Transport/model failures → `blocked_reason: "llm_unavailable"` (with detail).
The full function-calling scaffold and the four real query functions are always
built and independently tested regardless of LLM availability. Live end-to-end
LLM tests are opt-in (`RUN_LIVE_LLM_TESTS=1` + a real key) so the free tier and
CI are never consumed by default.

## Alternatives Considered
- Fake LLM responses to make demos "work" — rejected: the protocol explicitly
  forbids presenting fabricated model output as real.
- Skip the endpoint entirely without a key — rejected: silent skipping.

## Reasoning
An honest blocked state is debuggable and demo-safe; a fake success is a lie
that would surface at the worst moment (live judging). The scaffold being
credential-free is what keeps everything else shippable.

## Consequences
- Dashboards show a visible "not configured" warning.
- This run's live verification was blocked by a Gemini geo-restriction on the
  build environment's network (see RUN record) — an environment fact, not a
  code defect; the geo-restriction does not apply to the owner's local
  environment.

## Status
ACTIVE

## Supersedes
none
