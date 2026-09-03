# STATE SNAPSHOT — after RUN-2026-09-03-core-004

## Repository state

- Branch: main at 62d1db1 (+ this records commit)
- TASK-006 commits: 33217ec (model defaults + AQ tripwire), aabaa3d
  (process-env key delivery + skip-detection), ee623b7 (raw-diagnostics
  probe), 62d1db1 (Gemini 3.x wire format)
- Working tree: clean (generated state gitignored: vendor/, .env, .tools/,
  pidfiles, venv)
- Remote: origin/main == local main; every TASK-006 push triggered and
  passed CI (push events alive — OBS-006 note stands: events emitted from
  THIS sandbox work; the old note applied to the earlier sandbox)

## What the project can do now

- Everything from TASK-002/003/004/005 (bilingual Laravel 13 platform,
  `./run` suite, real CI, Event Ledger UI).
- **Live NL queries work for real**: `gemini-3.1-flash-lite` with the
  owner's new AQ-format key — verified live from CI runners (function
  selection → real Eloquent query → phrased answer).
- CI live-LLM smoke is self-verifying and self-diagnosing: skip = hard
  failure, probe prints the raw Google error + reachable flash models,
  laravel.log tail on failure, no continue-on-error.
- LLM defaults: `GEMINI_MODEL=gemini-3.1-flash-lite`,
  `GEMINI_VISION_MODEL=gemini-3.1-flash-lite` (.env.example and config
  defaults; overridable per environment).

## Ops truths (machine-verified)

- New Gemini key is VALID (401 for fakes, geo-400 for the real one from
  the sandbox; 200 from runners).
- Sandbox remains geo-blocked for Gemini (OBS-002) — live LLM work must
  be verified through CI (the smoke job is the designated verifier).
- Gemini 3.x function calling requires: lowercase OpenAPI schema types +
  verbatim model-turn round-trip (ADR-015; unit-locked).
- phpunit.xml env entries (force=false) outrank .env — secrets for tests
  must be delivered as process env (OBS-007 lesson).
- DocumentationTest tripwires now cover AIza…, AQ.…, and ghp_… patterns.

## Repo secrets

- `GEMINI_API_KEY` (Actions only): the owner's new AQ-format key, rotated
  2026-09-03T17:33:44Z via sealed-box REST. Never in git; full-history
  pattern scan = 0 matches.

## Open follow-ups

- The `gemini` vision classifier driver exists but its live path is
  unexercised (classifier defaults to stub/local by design; a live vision
  smoke would need an image fixture — noted, not blocking).
- Live-LLM smoke makes 1 function-calling round-trip per CI run (~2 API
  calls incl. probe) — free-tier friendly, monitor if CI frequency grows.
