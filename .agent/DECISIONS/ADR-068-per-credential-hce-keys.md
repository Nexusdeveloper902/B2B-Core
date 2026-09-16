# ADR-068 — per-credential HCE keys; the backend is the HCE verifier

## Date
2026-09-16

## Context
The engineering audit (CRITICAL; red-team RT-006) found one shared HMAC
secret (`dev-only-prototype-secret-001`) compiled into every phone APK
and every reader, and the backend never saw any proof. ADR-060 §4
recorded that model: "the reader's HMAC check plus Bearer key vouch for
the phone… no secrets server-side". The consequences:
- Anyone who extracted one APK could answer a CHALLENGE for any
  credential id.
- Anyone with one reader key could post a phone tap without any phone at
  all.

The CHALLENGE already authenticates `credId || nonce`, so the phone
names the credential before verification. Per-credential keys fit the
existing wire format. What must move is the verifier: readers cannot
hold every phone's key.

## Decision
1. **This backend verifies phone taps.** Readers relay `hce_nonce` and
   `hce_mac` (B2B-Firmware ADR-018).
   - `HceCredentialAuth::verifyTap` recomputes
     `HMAC-SHA256(K_card, credential_uid || nonce)`, compares in
     constant time, and claims the nonce once per credential
     (`Cache::add`, 7 days).
   - `TapService` and `CaptureService::associate` call it for
     `cards.kind = hce`, after the existing active check (revocation
     wins first) and before the meal engine.
   - Failure → `403 hce_auth_failed`, no event row, and a
     `tap_feedback` rejected cue.
   - Physical cards are unchanged.
2. **One key per phone card: `hce_credential_keys`.**
   - Columns: `card_id` unique, `secret` hex under the `encrypted` cast,
     `$hidden`, `fingerprint`, `provisioned_by_reader_id`,
     `provisioned_at`.
   - No global HCE secret exists in config or code.
   - The key is never logged and never returned by any endpoint.
3. **Provisioning is the existing, human-authorized pairing:**
   admin arm → the holder's "Link this phone" window → a reader in PAIRING
   mode.
   - An `hce` pair must carry `hce_nonce`, `hce_mac`, `hce_key_nonce` and
     `hce_key_wrapped`. Otherwise it is `422`.
   - The key is unwrapped with the reader's own `api_key` (a pad from
     `"pulse-hce-key-wrap/v1\n" || uid || "\n" || nonce`) inside a
     Pulse-HMAC-signed request.
   - It is accepted only if `hce_mac` verifies under it (proof of
     possession). Otherwise `403 hce_proof_invalid`: the window stays
     armed and the desk shows the reason.
   - Both nonces are single-use.
   - No new endpoint accepts a key.
4. **Re-key, never overwrite.** An existing id is re-keyed only when it
   is an active `hce` card of the student this window was armed for (the
   reinstall case; history is kept). Every other existing row stays
   `422 already_paired`. Revoked cards are never re-keyed.
5. **Revocation:** `POST /api/v1/admin/cards/{card}/revoke` (admin,
   school-scoped by route binding) sets `revoked` and deletes the key in
   one transaction, keeping history. The pairing desk has a **Revoke**
   button.
6. **Shared vector.** The key, credential, nonce, MAC, reader key, wrap
   nonce and wrapped value are pinned as literals in
   `HceCredentialAuthTest`, the firmware native suite and the Android
   unit suite, along with RFC 4231.

## Alternatives Considered
- **Keep reader-side verification and sync per-credential keys to
  readers.** Rejected: every reader becomes a key vault, and revocation
  needs a push channel.
- **An asymmetric phone credential (ECDSA, a non-exportable Keystore
  key; only the public key stored here).** This is the strongest
  custody, because a database leak exposes nothing. Deferred: the
  signature grows CHALLENGE beyond one RC522 I-block (unproven on the
  bench), and it changes the wire format of all three repos. It is the
  recorded successor.
- **A phone → backend enrollment endpoint with a desk-issued code.**
  Rejected: it adds a new auth surface, and the LAN deployment has no
  TLS for the phone, so the key would cross Wi-Fi unwrapped.
- **Hashing the stored key (like a password).** Impossible: HMAC
  verification needs the key itself.

## Reasoning
This is the smallest change that removes the shared secret and makes the
backend observe the proof. The pieces:
- one table, one service, one new admin endpoint;
- existing endpoints gain optional or conditional fields;
- no new device auth, no new pairing ceremony on the backend side, and
  physical-card contracts are byte-identical.

## Consequences
- **Migration:** phone cards paired before this change have no key and
  fail closed (`403`) until they are re-linked (arm the same student and
  pair again). No data loss.
- **`APP_KEY` becomes load-bearing for HCE.** Rotating it without
  re-encrypting makes every phone key unreadable, so every phone would
  need re-linking.
- **This database plus `APP_KEY` are the trust root** for phone keys
  (symmetric scheme).
- **Documented residual risks** (`B2B-Firmware/docs/HCE_PROTOCOL.md`
  §Security boundaries):
  - a reader-key holder can use a skimmed transcript once (unilateral
    authentication, reader-chosen nonce);
  - the key crosses NFC once, at enrollment;
  - a locked stolen phone still taps until it is revoked.
- **Bench and scripting:** Postman cannot hand-write an `hce` pair or
  tap any more. `scripts/e2e.sh` computes them (`hce_body`).

## Status
ACTIVE

## Supersedes
Amends ADR-060 §4 (trust model). The kind column, UID-independence and
shared endpoints stand.
