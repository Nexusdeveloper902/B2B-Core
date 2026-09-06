# TASK-022-deepseek-provider-migration

Owner directive (2026-09-06): "Change the provider from gemini to
deepseek v4 flash, pull their docs from the web." Supplied with a
GitHub PAT and the three repo URLs (B2B-Marketplace, B2B-Core,
B2B-Firmware) under the stateless-agent protocol.

## Diagnosis

- Gemini was wired into TWO integrations, both in B2B-Core (the
  other two repos have no LLM code): the NL-query function-calling
  transport (`GeminiClient`) and the optional recycling vision
  classifier driver (`GeminiClassifier`). Both share the
  `GEMINI_API_KEY` env var via config/recycling.php.
- Provider history: ADR-006 chose Gemini (owner-supplied free-tier
  key); OBS-002 recorded that the BUILD SANDBOX's egress region is
  refused by Google ("User location is not supported") so live NL
  calls only ever worked from the CI runner / the owner's machine.
- DeepSeek docs were pulled live from api-docs.deepseek.com (OBS-013
  records the truths): the requested "deepseek-v4-flash" model is
  REAL and current (public beta API 2026-07-31, serving
  DeepSeek-V4-Flash-0731); the legacy deepseek-chat/deepseek-reasoner
  names were DISCONTINUED 2026-07-24; there is exactly one
  image-capable model (deepseek-v4-flash-vision-exp, 2026-08-21);
  the API is OpenAI-compatible (Bearer auth, chat/completions, tools
  array, role:"tool" replies); V4 models think by default (high
  effort) which ignores temperature and multiplies latency/cost; the
  error taxonomy has NO region class — and adds 402 Insufficient
  Balance (pay-as-you-go, no free tier).

## Decision

- Full provider swap, not an abstraction layer (ADR-006's own
  "future swap touches one class" held: GeminiClient/GeminiClassifier
  deleted, DeepSeekClient/DeepSeekClassifier added; config/env/docs
 /tests/CI/scripts renamed GEMINI_* → DEEPSEEK_*).
- NL query: deepseek-v4-flash + tools (registry declarations stay
  provider-neutral; the client wraps them into the OpenAI envelope),
  thinking mode explicitly DISABLED, temperature 0.
- Vision classifier: deepseek-v4-flash-vision-exp with
  response_format json_object + the prompt contract the JSON Output
  guide requires ("json" word + example). Driver name `deepseek`
  replaces `gemini` in ClassifierFactory.
- Error taxonomy (ADR-016 pattern): 401 → llm_invalid_key,
  402 → llm_insufficient_balance (NEW class, actionable top-up),
  404/Model Not Exist → llm_model_not_found, 429 → llm_rate_limited,
  500/503 → llm_unavailable (the ADR-019 retry class). The
  Gemini-era llm_region_unsupported class is retired — DeepSeek
  documents no region restriction.
- Multi-turn contract: echo the assistant message VERBATIM (raw
  tool_calls with ids + any reasoning fields) then one role:"tool"
  message per call — the documented DeepSeek tool-call loop, analogue
  of the Gemini thoughtSignature rule (ADR-015).

## Acceptance

- [x] DeepSeek docs pulled from the web (7 pages, OBS-013) — model
      choice grounded in the CURRENT changelog, not stale knowledge
- [x] `./run test` 238 passed / 1 skipped (live opt-in) / 3914
      assertions — was 235/1/3564 at baseline
- [x] `./run quality` PASS (Pint clean after 5 auto-fixes,
      shellcheck 0.10.0 clean, docs parity EN+ES holds)
- [x] `./run e2e` 24/24 (real HTTP)
- [x] `./run llm-check` exit 2 (honest not-configured guidance,
      bilingual)
- [x] No GEMINI_* env/config/code references remain (leak-tripwire
      PATTERNS for old AIza/AQ keys intentionally kept + new sk-
      pattern added)
- [x] CI workflow migrated (DEEPSEEK_API_KEY secret; live smoke
      probe → api.deepseek.com/chat/completions + /models listing)
- [x] CI green on GitHub after push — run 34042048148 on main @
      7511bab: 12/13 success + 1 by-design skip (live-llm-smoke gates
      off until the owner adds the DEEPSEEK_API_KEY secret; value NOT
      provided this run)
- [x] CI red root-caused and fixed en route: the first dispatch
      (34041756796) failed ONLY in gitleaks — a full-history scan
      landmine from TASK-016's HandshakeTest fixtures (commit a000fb6;
      a fabricated WS token + the RFC 6455 example Sec-WebSocket-Key),
      zero findings from TASK-022's own commits. Allowlisted in
      .gitleaks.toml per the repo's existing convention; proven with
      the CI-parity binary (8.24.3, 76 commits, no leaks, exit 0)

## Out of scope (recorded, not attempted)

- LIVE end-to-end NL round-trip against DeepSeek — requires a real
  DEEPSEEK_API_KEY; none was supplied this run (only the GitHub PAT).
  The mocked-transport suite covers the full protocol; the CI live
  gate re-proves the moment the secret exists.
- The NL-query bug from the owner's consolidated bootcamp list
  ("executing the generated query throws an error") — separate task;
  the FunctionRegistry executes real Eloquent queries and all its
  unit tests pass, so the report predates this swap or lives in the
  live path only. Next agent: reproduce with a real key first.
