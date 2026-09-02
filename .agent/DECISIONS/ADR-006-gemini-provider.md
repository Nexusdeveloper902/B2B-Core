# ADR-006

## Date
2026-09-02

## Context
The original task text assumed Azure OpenAI for the NL query interface. The
project owner instead supplied a **Gemini API key (free tier)** with the
constraints: flash models only, light usage.

## Decision
Implement the NL-query LLM transport against the **Gemini API**
(`generativelanguage.googleapis.com/v1beta/...:generateContent`) using native
Gemini function-calling (`tools.function_declarations` /
`functionCall` / `functionResponse` parts). Default model:
`gemini-2.5-flash` (configurable via `GEMINI_MODEL` / `GEMINI_VISION_MODEL`).
The API key travels in the `x-goog-api-key` header (never in URLs), and live
calls are opt-in for tests/CI to respect the free tier.

## Alternatives Considered
- Wait for Azure OpenAI credits — rejected: the owner explicitly supplied
  Gemini for this purpose.
- OpenAI-compatible proxy layer — rejected: an extra abstraction with no
  current second provider.

## Reasoning
Function-calling is the feature the phase is built around; Gemini flash models
support it directly and are free-tier friendly. The transport is isolated in
`GeminiClient`, so a future provider swap touches one class.

## Consequences
- One live end-to-end NL call was attempted from the build environment and was
  blocked by Google's regional restriction on that network (HTTP 400
  "User location is not supported for the API use") — recorded honestly; the
  code path is fully covered by mocked-transport tests.
- Free-tier 429s map to the honest `llm_rate_limited` blocked state.

## Status
ACTIVE

## Supersedes
none (the Azure OpenAI assumption existed only in task text, never as an ADR)
