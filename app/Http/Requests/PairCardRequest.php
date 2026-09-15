<?php

namespace App\Http\Requests;

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
        ];
    }
}
