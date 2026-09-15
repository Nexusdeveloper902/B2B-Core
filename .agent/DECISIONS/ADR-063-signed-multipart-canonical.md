# ADR-063 — signed multipart canonical (Pulse-HMAC image posts)

## Date
2026-09-15

## Context
ADR-062 defined the signed body as "the raw request bytes" and the
firmware implemented exactly that (ADR-016 §3: sign the EXACT bytes
POSTed, JSON and multipart alike). But PHP never exposes raw multipart
bytes — `$request->getContent()` / `php://input` is empty for
`multipart/form-data` — so the backend hashed `""` while the device
hashed the real multipart body. Result, field-proven on the ESP32-CAM
station: JSON taps verify, every classify/capture 401s with `Invalid
device signature`. The test suite never covered signed multipart
(HMAC tests only tapped, multipart tests only bore Bearer).

## Decision
1. **Multipart image posts sign a canonical, not the wire bytes** —
   `DeviceRequestSigner::multipartCanonical()`: classify =
   `"event_id=<id>\nimage.sha256=<hex>"`, capture =
   `"image.sha256=<hex>"` (event ids normalized to plain ints, hashes
   lowercase). The hash covers the raw image bytes only, never the
   multipart framing — the only bytes both sides can reconstruct.
2. **One function picks the body**: `canonicalBody(Request)` returns
   the multipart canonical when the request carries `image`, else the
   raw content bytes. `verify()` signs its output; `sign()` is
   unchanged (callers pass raw JSON or the canonical). JSON endpoints
   are byte-identical to before — no migration, no reprovisioning.
3. **Security properties preserved**: image-bound (one flipped byte =
   different signature), reader-bound (HMAC key), replay-proof
   (single-use nonce unchanged). An invalid/missing upload fails closed
   (401); Bearer-path validation still owns the 422s.
4. **Pinned, not hoped**: the canonical literals are asserted on both
   sides (`DeviceHmacAuthTest` + firmware `test_capture_payload.cpp`
   share the `sha256('abc')` vector), plus signed-classify-200,
   swapped-image-401, and signed-capture-200 over real HTTP.

## Alternatives Considered
- Read raw multipart server-side — rejected: impossible, PHP discards
  it before Laravel boots (this was the bug, not a fix).
- Device falls back to Bearer for images — rejected: puts the secret
  back on the hotspot wire for exactly the largest posts (re-opens
  RT-001).
- Sign the empty body on both sides for multipart — rejected: drops
  body-binding; any image could ride any signature.
- Base64 the image inside JSON — rejected: breaks the stable
  multipart contract (ADR-007) and bloats ESP32 RAM.

## Reasoning
Smallest change that restores the ADR-062 promise for image posts: two
pure functions, no tables, no provisioning, no discovery/reconnect
touch, Bearer bench path untouched. The firmware mirrors with one
`postMultipart` choke point; tap/associate paths are diff-free.

## Consequences
- `hce.17` stations 401 on classify/capture until reflashed to
  `hce.18`; the kept-arm retry makes the failure visible, not silent.
- `docs/API.md` (+`.es.md`) auth-models row + classify/capture lines
  now state the canonical.

## Status
ACTIVE

## Amends
ADR-062 §Decision-1 (body definition for multipart posts)
