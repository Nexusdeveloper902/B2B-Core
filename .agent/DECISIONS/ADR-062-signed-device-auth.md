# ADR-062 — signed device authentication (Pulse-HMAC)

## Date
2026-09-16

## Context
The red-team audit (RT-001) demonstrated that every device secret travels
the competition hotspot in cleartext HTTP (`Authorization: Bearer
<api_key>` on every tap/classify/capture/pair), so one sniffed packet
buys full reader impersonation — including forged timestamps and floods
(RT-002/RT-005). TLS on the ESP32 was evaluated and rejected for the
demo path: certificate provisioning/pinning per reader is heavier than
the threat it counters on a LAN the school already physically controls,
and it would break the mDNS-discovery/no-hardcoded-IP workflow (ADR-061).

## Decision
1. **Readers sign every request (Pulse-HMAC)** — `Authorization:
   Pulse-HMAC <kid>:<nonce>:<sig>`, where `kid = sha256(api_key)[0:16]`
   (public fingerprint: no migration, no provisioning change, rotates
   with the key), `nonce` is fresh per request and single-use
   server-side (`Cache::add`, 24 h), and `sig = HMAC-SHA256(api_key,
   "METHOD\npath[?query]\nnonce\nsha256hex(body)")`. The secret never
   rides the wire; captures cannot be replayed (nonce) or retargeted
   (body-bound signature). Amends (not deletes) ADR-002.
2. **Nonce-only freshness, no timestamps** — the ESP32 has no wall clock
   (millis() only, no NTP on a phone hotspot), so timestamp schemes are
   unworkable. Requests stay self-contained and IP-agnostic: mDNS
   discovery, DHCP churn survival, and reconnect behavior are untouched.
3. **Legacy Bearer stays for bench tools** (Postman/curl/e2e/Postman
   collection) behind `DEVICE_AUTH_ALLOW_LEGACY_BEARER` (default true);
   `=false` enforces signatures only (demo-day lockdown). Rotate keys
   after any rehearsal — pre-fix captures hold Bearer secrets.
4. **One canonical string, one place** — `DeviceRequestSigner` owns both
   `sign()` (devices/tests) and `verify()` (middleware); the firmware
   mirrors it in `PresenceCore/RequestSigner` with a shared golden
   vector pinned in tests on both sides.
5. **First tap counts (RT-002)** rides the same task: classroom taps
   dedup per student/type/day inside a student-row-locked transaction
   (recycling exempt — every tap is a deposit); duplicates answer
   `200 duplicate:true` with the original event.

## Alternatives Considered
- mTLS client certificates — rejected: provisioning weight, breaks Postman-exercisable principle (same verdict as ADR-002).
- Timestamp-based HMAC — rejected: no device wall clock; NTP needs internet the hotspot may not have.
- Kill Bearer immediately — rejected: breaks bench/e2e/Postman workflows; the flag gives the same lockdown without the breakage.
- Hashed-key storage (ADR-045 follow-up) — partially answered: the secret is still at rest in plaintext, but it is now
  never transmitted; lookup-by-fingerprint IS the lookup redesign ADR-045 deferred. At-rest hashing stays future work.

## Reasoning
Smallest change that removes the sniff-and-own property: no new tables,
no new provisioning steps, no firmware config change (kid derives from
the existing key), no discovery/reconnect change. Endpoint-level
idempotency (classify/pair/redeem/meal-duplicate/classroom dedup) is the
documented second layer behind the nonce cache.

## Consequences
- `reader.auth` accepts two schemes; 401s for HMAC failures share one
  message (`api.invalid_device_signature`, EN+ES) to avoid oracles.
- Nonce cache grows ~1 entry per device request, TTL 24 h (database
  store by default; negligible at school scale).
- `Reader::all()` per HMAC request (fingerprint match) — fine for tens
  of readers; revisit past hundreds.
- Demo-day runbook: controlled hotspot + `APP_DEBUG=false` +
  non-default `STUDENT_INITIAL_PASSWORD` + `DEVICE_AUTH_ALLOW_LEGACY_BEARER=false`
  + key rotation after rehearsal.

## Status
ACTIVE

## Supersedes
Amends ADR-002 (Bearer-only contract)
