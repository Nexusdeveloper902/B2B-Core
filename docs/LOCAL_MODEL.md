# Running the Classification Model Locally (English)

> Also available in: [Español](LOCAL_MODEL.es.md)

The platform is designed to **run fully locally** at later stages — including
the material classifier. The Laravel backend never calls a model directly: it
depends on the `App\Contracts\MaterialClassifier` interface, and the active
implementation is chosen by **one .env variable**:

```dotenv
# stub   → deterministic pseudo-classifier (default; for dev/CI/demos)
# local  → YOUR local model-inference service   ← intended production driver
# deepseek → optional cloud fallback (deepseek-flash — DeepSeek-V4.1-Flash,
#          the vision-capable Flash model; needs DEEPSEEK_API_KEY; ADR-046)
RECYCLING_CLASSIFIER_DRIVER=local

LOCAL_CLASSIFIER_URL=http://127.0.0.1:8501/v1/models/material:predict
LOCAL_CLASSIFIER_TIMEOUT=10
```

Switching drivers requires **zero code changes** — no controller, route, or
test edits (see `.agent/DECISIONS/ADR-003` and `ADR-007`).

## The local-model HTTP contract

The `local` driver speaks a deliberately tiny, framework-agnostic contract
that any inference server can implement (TensorFlow Serving, TorchServe, ONNX
Runtime serving, or a 40-line FastAPI sidecar):

```text
POST {LOCAL_CLASSIFIER_URL}
Content-Type: multipart/form-data

image=<binary image file>

→ 200 OK  {"material_class": "plastic", "confidence": 0.87}
```

- `material_class` MUST be one of: `plastic`, `paper`, `metal`, `glass`,
  `other` (anything else → the backend returns 502/503 and awards nothing).
- `confidence` SHOULD be a float in `[0, 1]`.
- Any non-200 or timeout → the classify endpoint answers
  `503 {"status":"error", ...}` and **no points are touched** — the device
  can retry later.

## Reference sidecar server

`scripts/local-model-server/server.py` is a working FastAPI reference
implementation of this contract (hash-based placeholder logic by design —
swap in your real ONNX/TFLite model where marked). Run it:

```bash
cd scripts/local-model-server
python3 -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt
uvicorn server:app --port 8501

# then, in the app's .env:
#   RECYCLING_CLASSIFIER_DRIVER=local
#   LOCAL_CLASSIFIER_URL=http://127.0.0.1:8501/v1/models/material:predict
```

When a real model (e.g. a MobileNet fine-tuned on material photos) is trained
and exported, deploy it behind the same URL and the whole earn loop keeps
working untouched.

## The `deepseek` cloud fallback (ADR-046)

`RECYCLING_CLASSIFIER_DRIVER=deepseek` (+ `DEEPSEEK_API_KEY`) sends the
capture to `deepseek-flash` (DeepSeek-V4.1-Flash) via OpenAI-compatible
Chat Completions — re-verified against api-docs.deepseek.com 2026-09-11:

- image as a base64 data URL in an `image_url` part of a **user**
  message (system/assistant images 400), `detail: high` pinned
  (accuracy-first for the verification gap), `thinking.disabled` +
  temperature 0 (thinking is on by default and ignores temperature).
- `response_format: json_object` with the word "json" + a format
  example in the prompt; the model returns `{material_class,
  confidence, is_bottle, is_recyclable}` — the backend still owns the
  points rules (config table only).
- local pre-flight: JPEG/PNG/GIF/WebP detected from file **content**,
  32 MiB single-image ceiling — violations answer
  driver-unavailable (503, retryable), never cryptic 400s.
- `DEEPSEEK_VISION_MODEL` overrides the model ID (the retired
  `deepseek-v4-flash-vision-exp` still serves if pinned).

## Why the stub exists

Today there is no training data and no model, so the default `stub` driver
returns a plausible, deterministic-per-image class + confidence. This is
sanctioned (ADR-003): it lets every downstream feature (points, ledger,
dashboards, NL query, CI) be built and exercised now, exactly like the
Hardware Abstraction Principle does for readers.
