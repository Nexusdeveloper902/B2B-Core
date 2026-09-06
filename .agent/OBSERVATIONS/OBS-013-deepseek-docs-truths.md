# OBS-013: DeepSeek API docs truths (pulled live 2026-09-06)

Pages pulled from api-docs.deepseek.com this run: Your First API Call,
Models & Pricing, Error Codes, Change Log, Chat Completions API
(reference), Vision (guide), Tool Calls (guide), JSON Output (guide),
Thinking Mode (guide). Facts that shaped TASK-022:

- **The requested model is real and current**: `deepseek-v4-flash`
  (public-beta official API since 2026-07-31; the name serves
  DeepSeek-V4-Flash-0731 — "same architecture and size as the preview,
  re-post-trained"). `deepseek-v4-pro` GA'd 2026-08-13 (serves
  V4-Pro-0813).
- **The legacy names are DEAD**: `deepseek-chat` and
  `deepseek-reasoner` were discontinued 2026-07-24 (announced
  2026-04-24). Any doc/agent/tutorial still recommending them is
  stale.
- **Exactly one image-capable model**:
  `deepseek-v4-flash-vision-exp` (released 2026-08-21). Images ride as
  OpenAI-style content parts (`image_url` with base64 data URLs, user
  messages only; JPEG/PNG/GIF/WebP). NON-vision models reject images
  with a 400 ("This model does not support image") — the vision
  classifier must pin the vision model explicitly.
- **OpenAI-compatible wire format**: base_url
  `https://api.deepseek.com`, `POST /chat/completions`,
  `Authorization: Bearer`; tools as `{type:"function",
  function:{name, description, parameters}}`; tool results as
  `role:"tool"` messages with `tool_call_id`; `finish_reason`
  values: stop / length / content_filter / tool_calls /
  insufficient_system_resource. Tool-call `arguments` come back as a
  JSON STRING — decode, and validate (the docs themselves warn the
  model "does not always generate valid JSON").
- **Thinking mode is ON by default** (effort "high") for V4 models —
  and it IGNORES temperature/top_p silently (compat no-error). Toggle
  off with `"thinking": {"type": "disabled"}` (top-level body param;
  SDKs need extra_body). For deterministic, latency-bounded calls
  (NL query, classification) thinking must be disabled explicitly.
- **JSON Output contract**: `response_format {"type":"json_object"}`
  REQUIRES the word "json" + a format example in the prompt, plus a
  sane `max_tokens` — otherwise the model can stream whitespace until
  the token limit. Empty content is a known occasional glitch.
- **Error table** (no region class anywhere): 400 Invalid Format ·
  401 Authentication Fails · 402 Insufficient Balance · 422 Invalid
  Parameters · 429 Rate Limit · 500 Server Error · 503 Server
  Overloaded; unknown model → 404 "Model Not Exist". Body shape
  `{"error": {"message": …}}`.
- **No free tier**: pay-as-you-go balance, peak/off-peak pricing
  (off-peak = half; peak = 01:00–04:00 & 06:00–10:00 UTC weekdays).
  V4-flash: $0.14–0.44 per 1M input, $0.66–1.32 per 1M output.
  Concurrency limit 2500 for flash. Balance API exists
  (GET /user/balance) — unused so far.
- **Geo**: no region restriction is documented for the API (contrast
  Gemini's OBS-002) — the build sandbox geo-block should be moot for
  DeepSeek; verify with `./run llm-check` when a key exists.
