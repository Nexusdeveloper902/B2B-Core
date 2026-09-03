# TASK-007 — Pull the actual Gemini API docs + make every failure self-explaining

## Date
2026-09-04

## Status
COMPLETED — see RUN-2026-09-04-core-005

## Request
Owner: *"Using the same stateless protocol, please pull the actual gemini
api docs bc it still aint working"* — after TASK-006 proved the round-trip
works from CI runners, the owner still sees failure locally and demands
verification against the REAL Google documentation, not guesswork.

## What the docs actually said (fetched live, not from memory)

Pages pulled: REST reference for `models.generateContent`
(`POST /v1beta/{model=models/*}:generateContent`), the **API errors
contract** (`/gemini-api/docs/generate-content/api-errors`), the
function-calling guide, the troubleshooting guide, and the
available-regions page. Machine-checked truths:

1. **The documented error body is `{error: {code, message, status, details[].reason}}`**
   — gRPC status in SCREAMING_CASE, ErrorInfo `reason` such as
   `API_KEY_INVALID`. Our client collapsed ALL of these into one generic
   `llm_unavailable` → the owner's "it said its unaveliable" was
   literally our error-hiding, not Google's verdict.
2. **An invalid key is `400 INVALID_ARGUMENT / API_KEY_INVALID`** ("API
   key not valid. Please pass a valid API key."). We live-tested the OLD
   AIza key: it returns the REGION error, not the invalid-key error →
   **the old key is still valid**; the sandbox (and possibly the owner's
   egress network) is what Google refuses.
3. **The region refusal is `400 FAILED_PRECONDITION "User location is not
   supported for the API use."` with NO reason detail** — the message is
   the only signal. Colombia IS on the supported-regions list.
4. Function-calling docs confirm the TASK-006 wire format (lowercase
   OpenAPI types; "Gemini 3 series models use an internal thinking
   process… SDKs automatically handle thought signatures").
5. The generateContent API page is labeled **"(Legacy)"** in Google's own
   page metadata — the strategic endpoint is now the Interactions API
   (`POST /v1beta/interactions`, with breaking changes May 2026). Our
   endpoint still works today (CI 200); recorded as OBS-008 for the
   future migration decision.

## What was delivered

**Error taxonomy (per the docs contract) + `./run llm-check` self-diagnosis:**

- `GeminiClient::mapApiFailure()` — typed mapping: invalid key
  (API_KEY_INVALID/401/403/UNAUTHENTICATED), region refusal (message
  match — runs FIRST because both are plain 400s), model not found (404),
  rate limit (429), transport fallback.
- Distinct `blocked_reason` values + bilingual actionable messages
  (`llm_invalid_key`, `llm_region_unsupported`, `llm_model_not_found`)
  — every message names the fix and points at `./run llm-check`.
- **`./run llm-check`**: one bare live call with the SAME key+model; prints
  Google's EXACT verdict for THIS machine with EN/ES fix guidance; exit
  0/1/2 (works/diagnosed/not-configured). Verified live against real
  Google responses in both the region and invalid-key branches (from the
  geo-blocked sandbox). Found + fixed a `set -e` trap live: an unmatched
  grep inside `$(...)` killed the script before the diagnosis printed.
- Docs: API.md/.es blocked_reason table, SCRIPTS.md/.es `llm-check`
  sections, README quick-start hints — all bilingual.
- Tests: `GeminiClientTest` (8 tests, fixture bodies verbatim from the
  docs/live probes), 2 feature tests for distinct blockers, ScriptSuite
  wired. 119 local, CI 12/12 green (run 33811543860).

## Why the owner still saw failure (the honest answer)

The app was working from supported egress points (CI runners: live test
passes), but every local refusal — stale key, region, wrong model — was
displayed as the SAME generic "service unavailable". Now each cause is
named, and `./run llm-check` on the owner's machine will print Google's
verdict for THEIR network in one command. Most probable local causes,
now distinguishable: (a) local `.env` still holds the OLD key + OLD model
(setup never overwrites an existing .env); (b) the owner's egress region
is refused (Colombia is supported — VPN/ISP routing matters).

## Follow-ups
- `gemini` vision-classifier live path still unexercised (by design).
- Interactions API migration — deferred until Google announces
  generateContent sunset (OBS-008).
