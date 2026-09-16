# TASK-049 — per-credential HCE keys + revocation (remove the shared HCE secret)

Decision: ADR-068. Companion work: B2B-Firmware TASK-015 (ADR-018, the
reader relays the proof and sends ENROLL) and B2B-App TASK-003 (ADR-004,
the Keystore key and the link window).

## Ask
Remediate the CRITICAL audit finding: a shared HMAC secret was compiled
into every APK, so one extraction impersonated any phone. Required:
- per-credential keys;
- backend verification with no global secret;
- server-side revocation;
- provisioning through authenticated pairing;
- independent known-answer tests;
- no secret left in any build.

## Backend (this repo) — DONE
- Migration `2026_09_16_000002_create_hce_credential_keys_table` and
  model `HceCredentialKey` (encrypted, hidden). New
  `Card::hceKey()` and `Card::isHce()`.
- `App\Services\Hce\HceCredentialAuth`: `challengeMac`, `wrapPad`,
  `wrapKey`, `fingerprint`, `verifyTap`, `acceptProvisioning`,
  `storeKey`, and the single-use nonce claims.
- Tap path:
  - `TapService::registerTap` gains `hceNonce`/`hceMac`, and
    `TapEventRequest` validates them;
  - `TapEventController` maps `hce_auth_failed` → 403.
- Capture association: `CaptureService::associate`,
  `CaptureAssociateRequest` and `RecyclingCaptureController` get the
  same proof check (403).
- Pairing:
  - `PairingService::pair` adds the key hand-off, proof of possession,
    the same-student re-key and the `consume()` helper;
  - `PairCardRequest` makes the four fields `required_if` kind is hce;
  - `CardPairingController` returns `403 hce_proof_invalid` and
    `rekeyed`.
- Revocation: `CardRevokeController` +
  `POST /api/v1/admin/cards/{card}/revoke` (admin). The pairing desk
  has a **Revoke** button and a Revoked badge, plus the
  `hce_proof_invalid` rejection reason.
- Lang (EN + ES): `api.hce_credential_unverified`, `api.card_revoked`,
  `app.revoke*`, `app.card_status_revoked`, `app.toast_card_revoked`,
  `app.pairing_reason_hce_proof_invalid`.
- `scripts/e2e.sh` HCE phase rewritten around real keys, with
  keyless/replay/unproven/revoked/re-key checks. 49 checks in total.
- Docs (EN + ES):
  - `API.md`: tap fields and 403, pair key hand-off, the HCE section,
    the revoke endpoint;
  - `DATABASE.md`: `hce_credential_keys`;
  - Postman: the pair description and a revoke request.
- `.gitleaks.toml`: allowlists the two published shared-vector hex
  literals.
- Tests:
  - `tests/Unit/HceCredentialAuthTest` (6): RFC 4231 TC1/TC2, the shared
    MAC, wrap and fingerprint literals, and binding;
  - `tests/Feature/Api/HceCredentialTest` (24, rewritten) covers:
    - provisioning, encryption at rest, no key in responses;
    - keyless, bad-proof, wrong-reader-wrap and replayed pairings;
    - unproven tap, wrong key/nonce/id, A-cannot-be-B, replayed tap,
      missing or corrupt key;
    - meal readers and capture association;
    - revoke (key destroyed, status hand-flip still fails, no re-key)
      and revoke authorization (guest 401, teacher/kitchen 403,
      foreign-school admin 404, reader 401);
    - the desk revoke button, same-student re-key with history kept,
      and no overwrite of another student or a physical card;
    - a foreign-school reader cannot provision;
    - RF UID independence, the status feed and the desk badge;
  - `tests/Support/FakeHcePhone`, a test-only phone plus reader relay.

## Operational notes
- Deploy order: migrate, then serve. Existing phone cards must be
  re-linked at the desk.
- Do not rotate `APP_KEY` without re-linking every phone.
