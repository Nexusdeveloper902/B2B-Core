# ADR-016 — LLM error taxonomy from Google's documented contract + llm-check self-diagnosis

Date: 2026-09-04
Status: accepted (CI-verified, run 33811543860)
Context: TASK-007 — owner still saw "unavailable" after TASK-006 proved
the pipeline works; asked for verification against the ACTUAL docs.

## Decision

**1. Error mapping follows Google's documented error contract, not
string luck.** `GeminiClient` parses `{error: {code, message, status,
details[].reason}}` (per ai.google.dev/gemini-api/docs/generate-content/
api-errors) and maps to typed exceptions, in this ORDER (both key and
region failures are plain 400s — message/reason is the discriminator):

| Signal | Exception | blocked_reason |
|---|---|---|
| message "User location is not supported" / FAILED_PRECONDITION+location | regionUnsupported | `llm_region_unsupported` |
| reason API_KEY_INVALID / 401 / 403 / UNAUTHENTICATED | invalidKey | `llm_invalid_key` |
| 429 / RESOURCE_EXHAUSTED | rateLimited | `llm_rate_limited` |
| 404 / NOT_FOUND | modelNotFound | `llm_model_not_found` |
| anything else | transportFailure | `llm_unavailable` |

Every UI/API message is bilingual, names the actual cause, and names the
fix (including `./run llm-check`). The exact raw cause additionally lands
in `storage/logs/laravel.log` via `Log::warning`.

**2. `./run llm-check` is the designated LOCAL diagnosis tool.** One bare
`generateContent` call with the same key+model the app uses; prints
Google's exact verdict for the machine it runs on (works / invalid key /
region refused / model missing / quota / network) with EN/ES guidance;
exit 0/1/2. Pure bash+curl — no PHP, no app boot. Rationale: the CI
runner can prove the DEPLOYED config works, but only a local probe can
diagnose a LOCAL environment (stale .env, VPN/ISP egress) — the two
truths are complementary, not redundant.

**3. Failure fixtures in tests are verbatim from the docs/live probes**
(`GeminiClientTest`), not invented — the taxonomy is locked against
Google's actual wire format.

## Alternatives considered

- Keep the single generic "unavailable" (status quo): rejected — it is
  exactly what made failures undiagnosable (OBS-007 pattern repeated at
  the UX layer).
- Ship a PHP artisan command instead of a bash script: rejected —
  diagnosis must work on machines where PHP isn't even installed yet
  (pre-setup), consistent with ADR-010/011 hermetic principles.
- Migrate to the Interactions API now: deferred — generateContent works
  today (CI 200); see OBS-008 for the trigger conditions.

## Consequences

- The dashboard/API now distinguishes "your key was rejected" from "your
  region was refused" from "wrong model" — actionable feedback instead
  of a shrug.
- Region truth is two-sided: CI runner (US) proves the config; local
  llm-check proves the environment. A "works in CI, blocked locally"
  outcome now has a NAME (llm_region_unsupported) and a documented next
  step instead of "it still ain't working".
- New Gemini error classes only need: parse in mapApiFailure + one
  exception factory + one lang pair + one table row (docs + tests).
