<?php

namespace App\Http\Requests;

use App\Services\Hce\HceCredentialAuth;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PairCardRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // reader identity is enforced by the reader.auth middleware
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Same shape the tap endpoint accepts: the card UID.
            'credential_uid' => ['required', 'string', 'max:255'],
            // HCE integration: HOW the credential was captured (physical
            // MIFARE UID vs application-level Android HCE credential id).
            // Optional — omitted means 'physical' (old firmware keeps
            // working byte-for-byte); stored on the cards row as audit
            // metadata, never part of the tap lookup.
            'credential_kind' => ['nullable', 'string', 'in:physical,hce'],
            // TASK-049 (ADR-068) — an hce pairing IS the key hand-off: the
            // relayed CHALLENGE proof plus the reader-wrapped key. No
            // phone credential is ever created without its own key.
            'hce_nonce' => ['required_if:credential_kind,hce', 'nullable', 'string', HceCredentialAuth::NONCE_RULE],
            'hce_mac' => ['required_if:credential_kind,hce', 'nullable', 'string', HceCredentialAuth::MAC_RULE],
            'hce_key_wrapped' => ['required_if:credential_kind,hce', 'nullable', 'string', HceCredentialAuth::MAC_RULE],
            'hce_key_nonce' => ['required_if:credential_kind,hce', 'nullable', 'string', HceCredentialAuth::WRAP_NONCE_RULE],
        ];
    }
}
