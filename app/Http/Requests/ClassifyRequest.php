<?php

namespace App\Http\Requests;

use App\Models\PresenceEvent;
use App\Rules\OwnedByCurrentSchool;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ClassifyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // reader identity is enforced by the reader.auth middleware
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // TASK-045 (ADR-064) — a device may only classify an event
            // from its OWN organization (the reader's, resolved from its
            // key — never from the payload).
            'event_id' => ['required', 'integer', new OwnedByCurrentSchool(PresenceEvent::class)],
            'image' => ['required', 'file', 'image', 'max:10240'], // any test image works for the MVP contract
        ];
    }
}
