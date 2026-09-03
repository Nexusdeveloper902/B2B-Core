# STATE SNAPSHOT — after RUN-2026-09-04-core-005

## Repository state

- Branch: main at 9287391 (+ this records commit)
- TASK-007 commits: b526db2 (error taxonomy + llm-check), 9287391
  (fileMode +x fix, OBS-009)
- Working tree: clean; git `core.fileMode=false` still active — new
  executable scripts need `git update-index --chmod=+x` (OBS-009)
- Remote: origin/main == local main; CI green on the tip

## What the project can do now

- Everything from TASK-002..006 (bilingual platform, ./run suite, real
  CI, Event Ledger UI, live NL queries on gemini-3.1-flash-lite).
- **Every LLM failure is self-explaining**: distinct blocked_reasons
  (`missing_llm_credential`, `llm_invalid_key`,
  `llm_region_unsupported`, `llm_model_not_found`, `llm_rate_limited`,
  `llm_unavailable`) with bilingual actionable messages; raw cause in
  laravel.log.
- **`./run llm-check`**: one-command local Gemini diagnosis (works /
  invalid key / region refused / model missing / quota / network) with
  EN/ES guidance; exit 0/1/2; no PHP required.
- Error mapping follows Google's documented contract (ADR-016) and is
  locked by GeminiClientTest with fixtures verbatim from the docs.

## Ops truths (machine-verified this run)

- The OLD AIza key is STILL VALID (region error ≠ invalid-key error) —
  OBS-008 corrects TASK-006's "dead key" framing.
- Colombia is a supported Gemini region; region refusals indicate the
  egress path (VPN/proxy/ISP), not the country.
- generateContent is labeled Legacy by Google; the Interactions API is
  the strategic endpoint — migrate ONLY on sunset/404 (OBS-008).
- `core.fileMode=false` strips +x from NEW scripts at commit time;
  ScriptSuiteTest catches it in CI (OBS-009).

## Repo secrets

- `GEMINI_API_KEY` (Actions only): owner's AQ-format key, unchanged
  this run (rotation was TASK-006). Never in git; scans clean.

## Open follow-ups

- `gemini` vision-classifier live path unexercised (stub/local by
  design; needs an image fixture to smoke).
- Interactions API migration deferred (OBS-008 trigger conditions).
- If the owner reports `llm_region_unsupported` locally despite being
  in a supported country, next probe = egress IP (`curl ifconfig.me`)
  vs Google's refusal — note it in a future observation.
