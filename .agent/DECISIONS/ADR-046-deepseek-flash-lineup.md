# ADR-046 — DeepSeek Flash model lineup (deepseek-flash is the model)

## Date
2026-09-11

## Context
TASK-022 (ADR-030) pinned `deepseek-v4-flash` (chat) and
`deepseek-v4-flash-vision-exp` (vision) from docs pulled 2026-09-06.
Docs re-pulled 2026-09-11 (vision / thinking_mode / json_mode /
pricing / error_codes guides) show a consolidated lineup: the model is
`deepseek-flash` (DeepSeek-V4.1-Flash, vision-capable); BOTH legacy
names are still accepted but retired, served by V4.1-Flash and billed
at Flash price (pricing footnote 1). Nothing is broken today — but
every default still names a retired ID.

## Decision
1. Defaults move to `deepseek-flash` everywhere (chat client,
   vision classifier, config, llm-check, .env.example, docs).
   Env overrides keep working — a pinned legacy name still serves.
2. Vision request pins `detail: high` explicitly (accuracy-first for
   the verification gap; immune to future `auto` redefinitions) and
   pre-flights images locally (32 MiB cap + content-detected
   JPEG/PNG/GIF/WebP) so limit violations are honest
   driverUnavailable messages, never cryptic 400s.
3. Wire choices reconfirmed current, unchanged: `thinking.disabled`,
   temperature 0, json_object + json-word + example, user-message-only
   image parts, typed error taxonomy (400/401/402/422/429/500/503).

## Alternatives Considered
- Staying on legacy defaults (they still serve): rejected — retired
  IDs are a sunset waiting to happen; the migration is defaults-only.
- `detail: low` (faster/cheaper): rejected — the classifier closes a
  trust gap (wrong award = broken trust); token cost is capped either
  way; latency delta is sub-second against a 15 s timeout.

## Reasoning
Docs-truth (fetched this run) over month-old knowledge. Smallest
correct migration: defaults + pre-flight + tests; zero wire changes.

## Consequences
- `DEEPSEEK_MODEL` / `DEEPSEEK_VISION_MODEL` unset now resolve to
  `deepseek-flash`; explicitly set legacy names keep working.
- First-ever Http::fake test cover for the vision driver (was
  live-only).
- OBS-016 records the pulled truths; ADR-030's model choice is
  SUPERSEDED (record intact).

## Status
ACTIVE (supersedes ADR-030 model choice)
