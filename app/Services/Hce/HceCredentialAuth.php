<?php

namespace App\Services\Hce;

use App\Models\Card;
use App\Models\HceCredentialKey;
use App\Models\Reader;
use Illuminate\Support\Facades\Cache;

/**
 * TASK-049 (ADR-068) — the backend is the HCE verifier.
 *
 * Wire protocol (unchanged, B2B-Firmware docs/HCE_PROTOCOL.md): the
 * reader sends an 8-byte nonce, the phone answers
 * `HMAC-SHA256(K_cred, credId || nonce)`. What changed is WHO holds K:
 * each phone has its own K (Android Keystore) and only this backend
 * holds the copy (encrypted at rest). The reader is a relay — it
 * forwards `hce_nonce` + `hce_mac` and holds no HCE secret at all, so
 * a reader or APK dump no longer yields a key that works for anyone.
 *
 * Key hand-off (pairing only): the phone releases K once, inside a
 * user-opened enrollment window, to the reader in PAIRING mode. The
 * reader never sends K in clear over Wi-Fi — it sends
 *   hce_key_wrapped = K XOR HMAC-SHA256(reader_api_key,
 *                       "pulse-hce-key-wrap/v1\n" || credId || "\n" || hce_key_nonce)
 * inside a Pulse-HMAC-signed request (ADR-062: body-bound, single-use).
 * The pairing proof (hce_nonce/hce_mac) must verify under the
 * unwrapped K, so a corrupted or substituted key fails closed.
 *
 * Shared vector (pinned in HceCredentialAuthTest, the firmware native
 * suite and the Android unit suite):
 *   K = 00 01 .. 1f, credId = "PLS-K3Y7V3CT0R5Z", nonce = 0123456789abcdef
 *   mac = ba6d0fbf5106f79257727819d19a17b7e15abc4b8a0d1bfe71cd76090488a295
 *   reader key "test-secret-000000000000000001",
 *   hce_key_nonce = 000102030405060708090a0b0c0d0e0f
 *   wrapped = c5bb9fe15dff66a8636366dd4e73e0a3146601e3b3c36472961de142a9a914c2
 */
class HceCredentialAuth
{
    public const KEY_BYTES = 32;

    public const NONCE_BYTES = 8;

    public const WRAP_NONCE_HEX_LEN = 32;

    public const WRAP_LABEL = "pulse-hce-key-wrap/v1\n";

    /**
     * Seen-nonce memory per credential. A replayed transcript still needs
     * a valid reader signature to arrive at all; this closes the rest.
     */
    public const NONCE_TTL_SECONDS = 7 * 86400;

    /** Validation patterns shared by the device-facing form requests. */
    public const NONCE_RULE = 'regex:/^[0-9a-fA-F]{16}$/';

    public const MAC_RULE = 'regex:/^[0-9a-fA-F]{64}$/';

    public const WRAP_NONCE_RULE = 'regex:/^[0-9a-fA-F]{32}$/';

    /** HMAC-SHA256(key, credId || nonce), raw bytes — the phone's CHALLENGE MAC. */
    public static function challengeMac(string $key, string $credentialUid, string $nonce): string
    {
        return hash_hmac('sha256', $credentialUid.$nonce, $key, true);
    }

    /** The one-time pad a reader and this backend both derive for a key hand-off. */
    public static function wrapPad(string $readerSecret, string $credentialUid, string $wrapNonceHex): string
    {
        return hash_hmac('sha256', self::WRAP_LABEL.$credentialUid."\n".strtolower($wrapNonceHex), $readerSecret, true);
    }

    /** Wrap (and, being XOR, unwrap) a 32-byte key. Returns lowercase hex. */
    public static function wrapKey(string $readerSecret, string $credentialUid, string $wrapNonceHex, string $key): string
    {
        return bin2hex($key ^ self::wrapPad($readerSecret, $credentialUid, $wrapNonceHex));
    }

    public static function fingerprint(string $key): string
    {
        return substr(hash('sha256', $key), 0, 16);
    }

    /**
     * Verify a tap's proof against the card's own key.
     *
     * Callers MUST have rejected inactive cards first (revocation wins
     * before any crypto). Every failure is the same device-facing
     * rejection; the reason is for tests and the audit trail only.
     *
     * @return null|'missing_proof'|'no_key'|'mismatch'|'replay'
     */
    public function verifyTap(Card $card, ?string $nonceHex, ?string $macHex): ?string
    {
        if (! self::wellFormed($nonceHex, $macHex)) {
            return 'missing_proof';
        }

        /** @var HceCredentialKey|null $row */
        $row = $card->hceKey()->first();
        $key = is_string($row?->secret) && ctype_xdigit($row->secret) ? hex2bin($row->secret) : false;

        if (! is_string($key) || strlen($key) !== self::KEY_BYTES) {
            return 'no_key';
        }

        $expected = self::challengeMac($key, $card->credential_uid, (string) hex2bin($nonceHex));

        if (! hash_equals($expected, (string) hex2bin($macHex))) {
            return 'mismatch';
        }

        return $this->claimNonce($card->credential_uid, $nonceHex) ? null : 'replay';
    }

    /**
     * Unwrap a pairing hand-off and check proof of possession.
     *
     * @return array{ok: true, key: string}|array{ok: false, reason: 'missing_proof'|'mismatch'|'replay'}
     */
    public function acceptProvisioning(Reader $reader, string $credentialUid, array $input): array
    {
        $nonceHex = $input['hce_nonce'] ?? null;
        $macHex = $input['hce_mac'] ?? null;
        $wrapped = $input['hce_key_wrapped'] ?? null;
        $wrapNonce = $input['hce_key_nonce'] ?? null;

        if (! self::wellFormed($nonceHex, $macHex)
            || ! is_string($wrapped) || ! preg_match('/^[0-9a-fA-F]{64}$/', $wrapped)
            || ! is_string($wrapNonce) || ! preg_match('/^[0-9a-fA-F]{32}$/', $wrapNonce)) {
            return ['ok' => false, 'reason' => 'missing_proof'];
        }

        $key = hex2bin(self::wrapKey($reader->api_key, $credentialUid, $wrapNonce, (string) hex2bin($wrapped)));
        $expected = self::challengeMac($key, $credentialUid, (string) hex2bin($nonceHex));

        if (! hash_equals($expected, (string) hex2bin($macHex))) {
            return ['ok' => false, 'reason' => 'mismatch'];
        }

        // Both nonces are single-use: the proof cannot later pass as a tap,
        // and a pad is never derived twice for the same reader.
        if (! Cache::add('hce-wrap-nonce:'.$reader->id.':'.strtolower($wrapNonce), 1, self::NONCE_TTL_SECONDS)
            || ! $this->claimNonce($credentialUid, $nonceHex)) {
            return ['ok' => false, 'reason' => 'replay'];
        }

        return ['ok' => true, 'key' => $key];
    }

    /** Store (or replace, on an authorized re-key) the key for a card. */
    public function storeKey(Card $card, string $key, Reader $reader): HceCredentialKey
    {
        return HceCredentialKey::updateOrCreate(
            ['card_id' => $card->id],
            [
                'secret' => bin2hex($key),
                'fingerprint' => self::fingerprint($key),
                'provisioned_by_reader_id' => $reader->id,
                'provisioned_at' => now(),
            ],
        );
    }

    private function claimNonce(string $credentialUid, string $nonceHex): bool
    {
        return Cache::add(
            'hce-nonce:'.hash('sha256', $credentialUid).':'.strtolower($nonceHex),
            1,
            self::NONCE_TTL_SECONDS
        );
    }

    private static function wellFormed(?string $nonceHex, ?string $macHex): bool
    {
        return is_string($nonceHex) && preg_match('/^[0-9a-fA-F]{16}$/', $nonceHex) === 1
            && is_string($macHex) && preg_match('/^[0-9a-fA-F]{64}$/', $macHex) === 1;
    }
}
