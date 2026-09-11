# TASK-031 — DeepSeek Flash model-lineup migration + vision re-verification

## Date opened
2026-09-11

## Origin
Owner (chat, 2026-09-11): "the cv stuff is being remade for deepseek
specifically so you might wanna check the docs online and re check
your implementation." Docs re-pulled same day (vision, thinking_mode,
json_mode, pricing, error_codes).

## Docs truths (OBS-016)
- The model is `deepseek-flash` (V4.1-Flash, vision-capable). Legacy
  `deepseek-v4-flash` + `deepseek-v4-flash-vision-exp` still accepted,
  retired, served + billed as Flash (pricing footnote 1).
- Thinking toggle `{"thinking":{"type":"enabled/disabled"}}` current;
  enabled-by-default (high); thinking ignores temperature (no error).
- JSON Output: response_format + "json" word + example + sane
  max_tokens; empty content is a known occasional flake.
- Vision: base64/URL/Files-API, user-messages-only (400 otherwise),
  JPEG/PNG/GIF/WebP content-detected, 32 MiB inline / 48 MiB body,
  `detail` low/high/original/auto, 600 images max.
- Errors: 400 format · 401 auth · 402 balance · 422 params · 429 rate ·
  500 · 503. No region class. No model-404 documented anymore.

## Work
- [ ] Defaults → `deepseek-flash` (chat client, vision classifier,
      config, provider, llm-check, .env.example, lang, docs READMEs/
      API/LOCAL_MODEL, Postman if pinned)
- [ ] Vision: `detail: high` + local pre-flight (size cap +
      content-type check) + explicit empty-content diagnosis
- [ ] First Http::fake test cover for DeepSeekClassifier (was live-only)
- [ ] Default-model pins in tests; DocumentationTest `deepseek-flash`
      needles EN+ES
- [ ] Full pyramid per change; adversarial audit before commit

## Explicitly out of scope
- Firmware/ESP32-CAM-CV repos (backend owns DeepSeek; firmware has no
  DeepSeek code — verified by grep).
- New CV architecture (bottle-count events — TASK-030-G).
- Live round-trip (no key in this environment; Http::fake instead).

## Acceptance
- [ ] No default/mention of retired IDs outside .agent history +
      legacy-name compat notes
- [ ] Suite + quality + e2e green; audit verdict SHIP/SHIP-WITH-FIXES
      with zero open MAJOR+
