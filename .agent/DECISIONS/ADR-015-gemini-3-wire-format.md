# ADR-015 — Gemini 3.x wire format (lowercase OpenAPI types + verbatim model turns)

Date: 2026-09-03
Status: accepted (machine-verified live in run 33786816821)
Context: TASK-006 — first live function-calling round-trip

## Decision

The NL-query function-calling protocol targets the **Gemini 3.x wire
contract** and locks it with tests:

1. **Function-declaration schema types are lowercase OpenAPI types**
   (`object`, `string`, `integer`, …). The legacy UPPERCASE proto enum
   (`OBJECT`, `STRING`, `INTEGER`) is forbidden — Gemini 3.x models reject
   it. Enforced by `FunctionRegistryTest::wire_format_is_lowercase_openapi_types`.

2. **The model turn is echoed back VERBATIM.** `GeminiClient::generate()`
   returns the raw candidate `parts`, and `NlQueryService` appends the
   model turn using those parts unchanged (including any `thoughtSignature`
   parts). Gemini 3.x requires thought signatures to round-trip; dropping
   them breaks multi-turn function calling with a 400. A fallback builds
   the turn from the parsed `functionCall` when raw parts are absent
   (mocked tests / hand-rolled clients) — behavior-identical for them.

## Alternatives considered

- *Keep uppercase types*: works (if at all) only on legacy models; rejected.
- *Parse and re-emit only functionCall + rebuild turns*: what we did
  before; violates the thought-signature contract — root cause of the
  live failure.
- *Pin an older model* (`gemini-2.5-flash`): rejected — owner directive is
  `gemini-3.1-flash-lite`, and the new key's flash family includes it.

## Consequences

- The live smoke CI job is the wire-contract authority: probe (raw curl)
  + live test prove key, model, schema, and multi-turn protocol in one
  run; a skip is a hard failure (see OBS-007).
- Blocked NL queries now log their exact underlying cause
  (`Log::warning` in `NlQueryController`) — ops can read the raw Google
  error from `storage/logs/laravel.log`; the API response stays generic.
