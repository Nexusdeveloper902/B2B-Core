# ADR-045 — reader creation + API-key-once lifecycle

## Date
2026-09-11

## Context
TASK-027 restored the readers desk as rename + mode-switch only:
provisioning a reader still meant hand-written SQL (`INSERT` + a
hand-rolled `api_key`), and the key lifecycle was "printed once by the
seeder, then console history." The Pulse product rundown requires the
real workflow: create from the GUI, key generated at creation,
displayed exactly once, never exposed again.

## Decision
1. **`POST /api/v1/admin/readers`** (admin-only): `{label, type,
   active_event_type}` → creates the row with a server-generated
   32-char key (`Str::random(32)` — the seeder's standard, one key
   shape everywhere) + writes a `reader_created` roster frame in the
   same transaction (the desk prepends live, like students).
2. **`POST /api/v1/admin/readers/{reader}/rotate-key`**
   (admin-only): replaces the key (lost-key recovery without SQL),
   returns the new key exactly once, logs the rotation WITHOUT key
   material (reader id + admin id to laravel.log). Emits no roster
   frame — no displayed state changes (compare ADR-042: frames
   describe rendered state, not secrets).
3. **Display-once, enforced by shape**: the key appears ONLY as the
   top-level `api_key` of the minting/rotating response. `Reader.api_key`
   stays `$hidden`; no index/show/frame/log carries it (pinned by
   tests that grep the desk HTML + every reader response for the key).
4. **Desk** (`/admin/readers`): create panel (label + type + mode) +
   display-once key box + per-row rotate buttons behind a `confirm()`
   (rotation kills the deployed reader until re-flashed — same
   confirm grammar as card unpair). Table always renders (empty-row
   instead of no-table) so live-prepends work from zero; save/rotate
   handlers are delegated (dynamically prepended rows work).
5. **A generated key is proven, not assumed**: tests tap with the fresh
   key (device flow, `POST /api/v1/events/tap`) and with the rotated
   key, and prove the old key 401s after rotation.

## Alternatives Considered
- Longer keys (40+/64 chars): rejected — length-agnostic Bearer
  transport, and one 32-char standard (seeder included) beats two.
- Hashed-key storage (bcrypt/HMAC): rejected FOR NOW — the device
  lookup is `api_key` equality per tap; hashing adds a lookup
  redesign for a threat (DB read) already countered by LAN scope +
  rotation. Recorded as follow-up, not silently dropped.
- Auto-rotating on every settings save: rejected — rotation must be
  deliberate (it bricks the fielded reader until re-flashed).

## Reasoning
Smallest honest lifecycle: create → display-once → use → rotate on
suspicion/loss. No new tables, no new channels (the roster channel
carries the birth announcement; secrets never ride frames).

## Consequences
- `RosterUpdate::TYPE_READER_CREATED` joins the channel; the WS server
  broadcasts it with zero changes (type-agnostic poll).
- Residual: keys at rest are plaintext (see Alternatives); rotation is
  manual (no expiry); the plaintext hole is the documented follow-up.

## Status
ACTIVE
