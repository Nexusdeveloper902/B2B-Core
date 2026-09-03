# TASK-006 — Gemini key rotation + gemini-3.1-flash-lite + first-ever live NL query

## Date
2026-09-03

## Status
COMPLETED — see RUN-2026-09-03-core-004

## Request
Owner: *"I tried actually using the GeminiAPI key, and well, nothing, it
said its unaveliable"* — the original AIza… key is dead. Owner supplied a
new-format key (`AQ.Ab8RN6K6-…`) and directed: **use `gemini-3.1-flash-lite`**.
Delivered under the same stateless agent protocol.

## What was actually wrong (three stacked defects, all diagnosed from evidence)

**1. The old key is dead AND the new key was never reaching the tests.**
The CI `live-llm-smoke` job appended the key to `.env` — but `phpunit.xml`
pins `GEMINI_API_KEY=""` (force=false). PHPUnit-level env beats the `.env`
file (immutable dotenv), so the live test **skipped forever** behind a green
job (`continue-on-error` masked it). The "live-LLM smoke PASSED" recorded in
TASK-004's run 2 was a masked skip — the live path had NEVER actually run.

**2. The model default was gone anyway.** Old default `gemini-2.5-flash`;
owner directive: `gemini-3.1-flash-lite`.

**3. Even with key+model delivered, the app's function-calling payload was
rejected by Gemini 3.x** (two wire-format bugs, proven by the CI probe):
- `FunctionRegistry` used the legacy UPPERCASE proto enum types
  (`OBJECT`/`STRING`/`INTEGER`); Gemini 3.x requires lowercase OpenAPI types.
- The service rebuilt the model turn from the parsed `functionCall`,
  dropping `thoughtSignature` parts; Gemini 3.x requires echoing the model
  turn VERBATIM in multi-turn function calling.

## Fix chain (all pushed to main)

| commit | change |
|---|---|
| 33217ec | defaults → `gemini-3.1-flash-lite` everywhere (config, providers, classifier, docs EN/ES, fixtures); `AQ.`-format leak tripwire in DocumentationTest; job renamed flash-lite |
| aabaa3d | key passes as **step-level process env** (phpunit force=false respects it; verified locally: live test FAILS not skips); skip-detection hard-fails the job; `continue-on-error` removed |
| ee623b7 | CI raw-diagnostics probe (bare generateContent + flash-model list) + blocked-payload print in the live test |
| 62d1db1 | Gemini 3.x wire format: lowercase schema types (locked by new contract test), verbatim model turns via raw `parts`, `Log::warning` of the exact blocked cause, CI tails laravel.log on failure |

Repo secret `GEMINI_API_KEY` rotated to the new key via libsodium
sealed-box REST call (value never printed, never committed).

## Proof it works (run 33786816821, commit 62d1db1 — 12/12 jobs success)

- Probe: `HTTP 200 — models/gemini-3.1-flash-lite:generateContent`
- Live test: `✓ live end to end query with a real gemini key — 3.33s`,
  `Tests: 5 passed (10 assertions)` — **no skips**
- The model selected `get_attendance_count`, the backend ran the real
  Eloquent query, the model phrased the answer. First genuinely live
  NL-query in the project's history.

## Sandbox truths (differential evidence)

- Fake `AQ.` key → 401 UNAUTHENTICATED; real new key → 400 geo-block only
  → **the new key is a valid credential**; the sandbox location is the
  sole blocker locally (OBS-002 continues).
- Runners (US) have no geo restriction: probe 200.

## Follow-ups
- None blocking. The live smoke is now self-verifying (skip = failure),
  self-diagnosing (probe + laravel.log tail), and the wire-format contract
  is unit-locked.
