# ADR-002

## Date
2026-09-02

## Context
NFC readers must authenticate to POST tap events. No hardware exists yet, so the
auth scheme must be exercisable by Postman/curl/tests today and by ESP32
firmware later with zero backend redesign. A client-supplied reader ID would let
any caller impersonate any reader.

## Decision
Every reader row gets a static, unique `api_key` (32 random chars, seeded and
printed by the seeder). Device endpoints (`/api/v1/events/tap`,
`/api/v1/recycling/classify`) require `Authorization: Bearer <api_key>`; the
`reader.auth` middleware resolves the reader FROM the key — client-supplied
reader IDs are never trusted. Unknown/missing keys → 401 JSON.

## Alternatives Considered
- mTLS client certificates — rejected: heavyweight, hard to demo from Postman.
- Per-request signed payloads (HMAC) — rejected: adds clock/nonce state, which
  conflicts with the "each request is stateless and self-contained" requirement.
- No auth — explicitly forbidden by the task ("do not skip auth entirely").

## Reasoning
A static bearer key is the simplest real auth that satisfies the Hardware
Abstraction Principle: anything that can send a header works, and rotating a key
is a DB update. Statelessness keeps device firmware trivial.

## Consequences
- Keys must be treated as secrets (rotation = update the reader row).
- Compromised key scope = one reader's endpoints (blast radius is small).
- The seeder MUST print keys (implemented) or there is no practical hand-testing.

## Status
ACTIVE

## Supersedes
none
