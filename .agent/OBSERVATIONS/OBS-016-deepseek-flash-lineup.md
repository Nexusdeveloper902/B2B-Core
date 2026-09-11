# OBS-016 — DeepSeek docs re-pull: the model is deepseek-flash (2026-09-11)

Pulled from api-docs.deepseek.com (vision, thinking_mode, json_mode,
pricing, error_codes guides) for TASK-031. Supersedes the model-name
half of OBS-013 (2026-09-06); the wire-format truths there stand.

- **Lineup**: `deepseek-flash` = DeepSeek-V4.1-Flash, vision-capable.
  Legacy `deepseek-v4-flash` + `deepseek-v4-flash-vision-exp` still
  accepted, retired, served + billed as Flash (pricing footnote 1).
- **Thinking**: `{"thinking":{"type":"enabled/disabled"}}`, enabled by
  default (effort high); thinking ignores temperature/presence/
  frequency penalties (no error, no effect). top_p floor 0.95 in
  thinking mode.
- **JSON Output**: response_format + "json" word + example + sane
  max_tokens; empty content is a known occasional flake.
- **Vision**: base64/URL/Files-API in user-message image_url parts
  (system/assistant images 400; non-vision models 400 "does not
  support image"); JPEG/PNG/GIF/WebP content-detected; 32 MiB inline /
  48 MiB body; detail low/high/original/auto; 600 images max; 8192 px
  per side (4096 with 15+ images).
- **Errors**: 400 format · 401 auth · 402 balance · 422 params ·
  429 rate · 500 · 503. No region class, no documented model-404
  (kept our 404 mapping as defense — auditor agreed).
- **Test trap found while pinning**: `Http::fake()` APPENDS stubs
  (stubUrl) — re-faking one URL in a test never replaces; one fake
  per test (or fakeSequence).
- **PHP trap found while pinning**: `filter_var(null, BOOLEAN,
  NULL_ON_FAILURE)` yields false, not null — absent model fields need
  their own branch before the filter.
- **Guard trap (from TASK-030, relevant to any test)**: Sanctum
  RequestGuard memoizes per app lifetime — PAT-wall tests must
  re-read DB truth (our middleware does) or split calls per method.
