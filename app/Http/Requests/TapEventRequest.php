<?php

namespace App\Http\Requests;

use App\Services\Hce\HceCredentialAuth;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class TapEventRequest extends FormRequest
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
            'credential_uid' => ['required', 'string', 'max:255'],
            // Optional device clock (ISO 8601). Absent => server time.
            'client_timestamp' => ['nullable', 'date'],
            // TASK-049 (ADR-068) — the relayed HCE CHALLENGE transcript
            // (hex). Required in practice for phone credentials — the
            // service rejects an hce card without it — and ignored for
            // physical cards, so old readers keep their exact contract.
            'hce_nonce' => ['nullable', 'string', HceCredentialAuth::NONCE_RULE, 'required_with:hce_mac'],
            'hce_mac' => ['nullable', 'string', HceCredentialAuth::MAC_RULE, 'required_with:hce_nonce'],
        ];
    }
}
