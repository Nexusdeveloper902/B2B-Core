# OBS-008 — Gemini docs truths from the live pull (TASK-007)

Date: 2026-09-04

## Observation

Pulled the REAL Gemini API docs (web-reader) instead of trusting memory.
Four truths that changed decisions:

1. **generateContent is labeled "Legacy" by Google itself** — the page
   metadata reads `"projectName": "Gemini Generate Content API (Legacy)"`
   and the docs nav centers on the new **Interactions API**
   (`POST /v1beta/interactions`, payload `{"model", "input", "tools":
   [{"type": "function", …}]}`, with "Interactions breaking changes
   (May 2026)" and "Migrate to Interactions API" pages). Our endpoint
   still works today (CI: HTTP 200 + live function-calling pass). **Do
   not migrate until Google announces a generateContent sunset or the
   endpoint starts 404ing** — then swap ENDPOINT + payload shape inside
   GeminiClient only (the service layer is endpoint-agnostic).

2. **The old AIza key is STILL VALID.** An invalid key returns
   `400 INVALID_ARGUMENT` + ErrorInfo reason `API_KEY_INVALID`; the old
   key returns the REGION refusal instead — meaning it authenticates
   fine. TASK-006's "dead key" framing was wrong: the key never died,
   the geo-block (OBS-002) just never let us see the difference. Both
   keys (old AIza + new AQ.) are valid credentials.

3. **Colombia is on the supported-regions list** (available-regions
   page). If the owner's local calls get the region refusal, the cause
   is their egress path (VPN, corporate proxy, ISP routing), not their
   country.

4. **Region errors carry NO ErrorInfo reason** — plain 400
   FAILED_PRECONDITION with the message as the only signal. Key errors
   DO carry `reason: API_KEY_INVALID`. The discriminator between two
   plain-400 failures is message/reason, and the mapping order must put
   the region check FIRST (ADR-016).

## Rule extracted

When integrating an external API: pull the CURRENT docs and the CURRENT
error contract before writing error handling — and validate assumptions
differentially (fake key vs real key vs wrong model) so each failure
class is observed, not guessed.
