# STATE SNAPSHOT — after RUN-2026-09-06-core-020

## Repository state

- Branch: main at the TASK-022 merge commit (feature/
  TASK-022-deepseek-provider-migration merged --no-ff; see `git log`
  for the hash)
- Working tree: clean (all changes committed and pushed)
- Test count: 238 passed / 1 skipped (was 235/1 at baseline) —
  +9-case DeepSeekClientTest, -6-case GeminiClientTest, +1 balance
  blocker feature test
- B2B-Marketplace: untouched this run (no LLM code exists there)
- B2B-Firmware: untouched this run (device protocol never touched the
  LLM)

## What changed (delta vs RUN-019)

- **The LLM provider is DeepSeek now** (TASK-022, ADR-030 supersedes
  ADR-006): NL queries run on `deepseek-v4-flash` via
  `DeepSeekClient` (OpenAI-compatible chat/completions, Bearer auth,
  thinking DISABLED, temperature 0, tools with verbatim assistant
  echo + role:"tool" replies); the optional vision classifier driver
  is `deepseek` → `DeepSeekClassifier` on
  `deepseek-v4-flash-vision-exp` (the only image-capable DeepSeek
  model) with response_format json_object.
- **Error taxonomy swapped to DeepSeek's documented table**: 401 →
  llm_invalid_key, 402 → llm_insufficient_balance (NEW — pay-as-you-
  go balance blocker with top-up guidance), 404/Model Not Exist →
  llm_model_not_found, 429 → llm_rate_limited, 5xx → llm_unavailable
  (ADR-019 retry class unchanged). llm_region_unsupported is RETIRED
  (no region restriction documented — the OBS-002 sandbox geo-block
  is expected to be moot for LLM calls once a key exists).
- **Config surface**: DEEPSEEK_API_KEY / DEEPSEEK_MODEL /
  DEEPSEEK_VISION_MODEL / DEEPSEEK_TIMEOUT; no GEMINI_* variable
  remains anywhere (code, docs, CI, scripts, tests).
- **CI**: llm-gate reads the `DEEPSEEK_API_KEY` secret — the owner
  must add it (platform.deepseek.com key) to re-arm the live-LLM
  smoke job; until then it skips by design (honest, gated, never
  masked).
- Docs are bilingual-consistent (README/API/LOCAL_MODEL/SCRIPTS EN+ES
  + Postman) — DocumentationTest parity pins hold.

## Known problems / next

- Live E2E against DeepSeek is UNVERIFIED (no key value supplied this
  run). First action once a key exists: `./run llm-check`, then the
  live-llm-smoke CI job; expect the sandbox geo-block class to be
  gone.
- Owner-reported bootcamp item "executing the generated NL query
  throws an error" is NOT reproduced by the mocked suite (all
  FunctionRegistry paths unit-green); re-test against the live
  provider before opening a fix task.
- Billing reality changed: no free tier — 402 blockers are actionable
  (top up), not retryable; the CI gate notice and README say so.
- Standing backlog unchanged: per-class jump nav, GET /reader/me,
  firmware PAIRING.md pointers, security audit, student GUI
  management/CSV import, non-PAE tap handling.
