# ADR-060: Android HCE phones are cards with a kind, not a new identity system

## Status
**§4 (trust model) superseded by ADR-068 (2026-09-16):** per-credential keys, verified here; no shared HCE secret anywhere. The rest stands.

Accepted (2026-09-14, TASK-042)

## Context
The verified standalone HCE prototype (`RC522-Android/`) proves the NFC
path: phone → SELECT AID `F0010203040506` → CHALLENGE → HMAC → reader.
The integration question was how much backend to build around it. The
`cards` table already stores any `credential_uid` string and every
lifecycle (pair / tap / revoke / unpair / status feed / realtime) keys
off it — so a second credential subsystem would be pure duplication.

## Decision
1. **One column: `cards.kind` (`physical` | `hce`, default `physical`)**
   recording HOW the credential was captured — display/audit metadata
   only. Tap lookup stays `credential_uid`-only; the pair endpoint takes
   an optional `credential_kind` (omitted = `physical`, old firmware
   byte-identical).
2. **The RF UID is never identity, enforced by absence**: no `rf_uid`
   column, no `rf_uid` input, and HCE ids (non-hex, e.g.
   `TEST-ANDROID-001`) could never be RF UIDs anyway. Pinned by
   `HceCredentialTest::identification_does_not_depend_on_the_rf_uid`.
3. **Same endpoints, same spine**: phone taps resolve
   `credential → student → attendance / PAE / recycling` through the
   unchanged tap endpoint; revocation is the existing `CardStatus`;
   unpair is the existing row-delete. The desk badges `hce` rows
   (“Phone” / “Teléfono”).
4. **Trust model unchanged**: the reader's HMAC check + Bearer key vouch
   for the phone exactly as a physical UID is vouched for. No secrets
   server-side. Per-credential keys / replay protection are recorded
   future work in the firmware spec, not silent gaps.

## Consequences
Migration `2026_09_14_000003` (reversible drop-column); `CardKind` enum;
`PairingService::pair()` gains a defaulted `$kind`; `statusPayload()`
gains additive `card_kind` (WS pairing channel unchanged — same
payload); API/DATABASE docs + Postman + e2e HCE phase (40 checks).
