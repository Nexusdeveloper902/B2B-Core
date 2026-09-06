<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RedeemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role gate happens via role middleware
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reward_id' => ['required', 'integer', 'exists:rewards,id'],
            // TASK-025 item 7 — idempotency key for double-submit
            // protection (spec §20): a replayed request_id returns the
            // original redemption answer, never a second charge.
            'request_id' => ['nullable', 'string', 'max:255'],
        ];
    }
}
