<?php

namespace App\Http\Requests;

use App\Models\Reward;
use App\Rules\OwnedByCurrentSchool;
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
            // TASK-045 (ADR-064) — a school may only redeem its own catalog.
            'reward_id' => ['required', 'integer', new OwnedByCurrentSchool(Reward::class)],
            // TASK-025 item 7 — idempotency key for double-submit
            // protection (spec §20): a replayed request_id returns the
            // original redemption answer, never a second charge.
            'request_id' => ['nullable', 'string', 'max:255'],
        ];
    }
}
