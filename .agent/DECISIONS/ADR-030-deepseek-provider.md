# ADR-030

## Date
2026-09-06

## Context
The owner directed a provider migration: "Change the provider from
gemini to deepseek v4 flash, pull their docs from the web." The Gemini
provider (ADR-006) carried two practical costs: the build sandbox's
egress region is refused by Google (OBS-002 — live NL verification only
ever worked from the CI runner / owner's machine), and the free-tier
flash quota blipped 503 under load (OBS-012). DeepSeek's CURRENT docs
(pulled live this run — OBS-013) show a V4 family with a public-beta
API, OpenAI-compatible wire format, and no region restriction, but
pay-as-you-go balance instead of a free tier.

## Decision
Swap BOTH Gemini integrations to DeepSeek (this repo's only LLM
consumers):

1. **NL query**: `DeepSeekClient` (replaces `GeminiClient`) —
   `POST https://api.deepseek.com/chat/completions`, Bearer auth,
   default model `deepseek-v4-flash` (serves DeepSeek-V4-Flash-0731).
   Tools = the registry's provider-neutral declarations wrapped into
   the OpenAI `{type:"function", function:{…}}` envelope; tool results
   return as `role:"tool"` messages keyed by `tool_call_id`; the
   assistant turn is echoed back VERBATIM (ids + raw string arguments
   preserved) per the documented multi-turn contract. **Thinking mode
   is explicitly disabled** on every call: V4 models think by default
   (effort "high") which multiplies latency/cost for a
   function-selection task AND silently ignores `temperature` — so the
   deterministic temperature=0 contract only holds with thinking off.
2. **Vision classifier**: `DeepSeekClassifier` (replaces
   `GeminiClassifier`) — model `deepseek-v4-flash-vision-exp` (the
   ONLY DeepSeek model that accepts images; non-vision models 400),
   images as base64 data-URL `image_url` content parts,
   `response_format json_object` + prompt containing the word "json"
   and a format example (both REQUIRED by the JSON Output guide).
3. **Error taxonomy** (ADR-016 pattern, DeepSeek contract): 401 →
   `llm_invalid_key`; **402 → `llm_insufficient_balance` (new class:
   the actionable pay-as-you-go failure)**; 404/"Model Not Exist" →
   `llm_model_not_found`; 429 → `llm_rate_limited`; 500/503 →
   `llm_unavailable` (the ADR-019 transient-retry class). The
   Gemini-era `llm_region_unsupported` class is retired — DeepSeek
   documents no region restriction.
4. Env/config: `GEMINI_API_KEY/GEMINI_MODEL/GEMINI_VISION_MODEL/
   GEMINI_TIMEOUT` → `DEEPSEEK_API_KEY/DEEPSEEK_MODEL/
   DEEPSEEK_VISION_MODEL/DEEPSEEK_TIMEOUT`; classifier driver value
   `gemini` → `deepseek`; CI secret `GEMINI_API_KEY` →
   `DEEPSEEK_API_KEY` (owner must add the new secret value).

## Alternatives Considered
- Provider-agnostic `LlmClient` interface with pluggable backends —
  rejected: speculative abstraction for a single active provider;
  ADR-006 predicted "a swap touches one class" and that held.
- Keeping Gemini as a second configurable backend — rejected: two
  live providers to keep honest (docs, tests, CI secrets) for zero
  current value; git history preserves the code.
- deepseek-chat / deepseek-reasoner model names — rejected: the docs
  changelog discontinues both on 2026-07-24; they would silently rot.

## Reasoning
The swap is grounded in the CURRENT official docs (pulled this run,
not memory): model lineup, wire format, vision support, thinking-mode
default, and the documented error table are all first-hand. The
project's honest-blocker architecture (typed exceptions → distinct
blocked_reason → bilingual actionable message) transfers 1:1; only the
region class had no DeepSeek counterpart, and the balance class is the
new provider-specific actionable failure. Disabling thinking is a
latency/cost/determinism decision documented against the docs' own
caveats (thinking ignores temperature).

## Consequences
- NL queries and the vision classifier now bill a DeepSeek pay-as-you-
  go balance — the free-tier framing in docs/CI notices is replaced by
  the honest "owner opts in by adding the secret" framing; 402 gets
  its own typed blocker with top-up guidance.
- Live E2E verification requires the owner to (a) create a DeepSeek
  key at platform.deepseek.com and (b) store it as the GitHub Actions
  secret `DEEPSEEK_API_KEY`; until then the live-llm-smoke job gates
  off (by design) and the live test skips (honest, not masked).
- The OBS-002 sandbox geo-block is expected to become moot for LLM
  calls (DeepSeek documents no region restriction) — verify with
  `./run llm-check` once a key exists.
- Old Gemini key-leak tripwire patterns (AIza…/AQ…) are KEPT in
  DocumentationTest (they guard git history) and joined by a DeepSeek
  `sk-…` pattern.

## Status
ACTIVE

## Supersedes
ADR-006 (Gemini provider) and the Gemini-3.x wire-format specifics of
ADR-015 (the verbatim-echo multi-turn rule survives in its OpenAI
form). ADR-016's taxonomy PATTERN survives with the DeepSeek error
table as its new provider contract.
