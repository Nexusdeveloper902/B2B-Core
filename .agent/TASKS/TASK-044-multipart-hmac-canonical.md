# TASK-044 — multipart Pulse-HMAC canonical (classify/capture 401 fix)

## Ask
Field report (ESP32-CAM station `hce.17`): HCE tap authenticates, tap
logs `RECYCLING_DEPOSIT` with `next_step: awaiting_classification`,
then the card-first `POST /api/v1/recycling/classify` answers `401
{"status":"error","message":"Invalid device signature"}` on every
retry while the arm is kept. Tap (JSON) always passes; image posts
(multipart) always fail.

## Root cause
Both sides signed "the body" but meant different bytes: the firmware
signed the exact multipart wire bytes (per ADR-016 §3), while
`DeviceRequestSigner::verify()` hashed `$request->getContent()` —
which is ALWAYS empty for `multipart/form-data` (PHP never exposes
raw multipart via `php://input`). Empty-hash vs real-hash = mismatch
on every image post. Uncaught because `DeviceHmacAuthTest` only signed
`/events/tap` (JSON) and `ClassificationTest`/`CaptureFlowTest` only
used Bearer for multipart.

## Backend (this repo) — DONE
- `DeviceRequestSigner`: new `multipartCanonical(?eventId, imageSha256)`
  (`classify: "event_id=<id>\nimage.sha256=<hex>"`, `capture:
  "image.sha256=<hex>"`, ids normalized to plain ints) + new
  `canonicalBody(Request)` (multipart canonical when
  `hasFile('image')`, raw bytes otherwise); `verify()` signs the
  canonical. JSON endpoints byte-identical to before.
- Contract: ADR-063 (amends ADR-062). Docs: `docs/API.md` + `.es.md`
  auth-models row + classify/capture auth lines.
- Tests (`DeviceHmacAuthTest`, +4): canonical format pin (shared
  `sha256('abc')` literal with firmware), signed classify 200,
  swapped-image 401 with zero deposits, signed capture 200.

## Firmware (B2B-Firmware TASK-014) — DONE (separate repo)
- `CapturePayload::classifySigningBody()/captureSigningBody()` mirror
  the canonical byte-for-byte (pinned by shared literals);
  `EspApiClient::postMultipart()` signs the canonical while sending
  the real multipart bytes; `Station` classify + capture use it
  (tap/associate unchanged). Build `hce.17` → `hce.18`.

## Out of scope (recorded, not forgotten)
- `DEVICE_AUTH_ALLOW_LEGACY_BEARER=false` lockdown still leaves Bearer
  for bench tools by default; unchanged.
- At-rest key hashing (ADR-045 future work); unchanged.
