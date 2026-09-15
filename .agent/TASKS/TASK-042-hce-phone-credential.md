# TASK-042 — Android HCE phone-as-credential (Pulse integration)

## Ask
Turn the working standalone HCE prototype into a first-class Pulse
credential without breaking physical cards: same pair/tap/revoke/unpair
lifecycle, same endpoints, phone identity from the APDU exchange — never
the RF UID.

## Backend (this repo) — DONE
- `cards.kind` (`physical` | `hce`, default `physical`) + `CardKind` enum.
- `POST /api/v1/admin/cards/pair` accepts optional `credential_kind`.
- Tap path untouched (uid-only by design). Status feed + WS frames carry
  additive `card_kind`. Desk badges (“Phone” / “Teléfono”) in roster
  chips, history (SSR + live JS rows) and students desk.
- Docs: API.md/.es.md (§Android HCE credentials, AID `F0010203040506`),
  DATABASE.md/.es.md, Postman pair body. Doc-test needles added.
- Tests: `HceCredentialTest` (7: pair hce, default physical, bad kind
  422, revoke→unpair→repair, UID-independence, status kind, desk badge
  EN+ES). e2e Fase B-HCE (+7 checks: arm → pair hce → kind stored →
  tap resolves → re-pair 422).

## Firmware (B2B-Firmware TASK-011) — DONE
- `HceProtocol` (pure C++, host-tested: builders byte-exact, parsers,
  own SHA-256/HMAC pinned by RFC 4231 vector, UID-independence test).
- `Rc522NfcReader` on `MFRC522Extended` (drop-in): SAK-bit-6 + ATS →
  SELECT → fresh-nonce CHALLENGE → constant-time verify; MIFARE path
  untouched. `NfcReader::lastKind()` → pair `credential_kind`.
- Secrets: `HCE_SECRET` in both `.example` templates (+ `#ifndef`
  fallback for pre-HCE local files). Docs: `HCE_PROTOCOL.md` + `.es.md`
  (canonical), API_INTEGRATION/PAIRING/CHECKLIST updates both languages.

## Android (B2B-App/pulse-credential) — DONE
- Prototype copied in (minus build artifacts); Pulse identity (name,
  theme, strings, README); protocol values behind BuildConfig `PULSE_*`
  fields (dev defaults = prototype values); default-pinning unit test.
- Builds: `:app:assembleDebug` APK + `testDebugUnitTest` 3/3 green.

## Out of scope (recorded, not forgotten)
Per-credential keys, replay protection, mutual authentication, key
rotation (firmware HCE_PROTOCOL.md §hardening). Bench §10 (real phone +
board) is human-verified — checklist written, run pending.
