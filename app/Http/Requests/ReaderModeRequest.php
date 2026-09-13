<?php

namespace App\Http\Requests;

use App\Enums\EventType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReaderModeRequest extends FormRequest
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
            // The same physical reader can be relabeled to any known event
            // type — validated against the canonical EventType set.
            // TASK-037 — PAE_ATTEMPT is engine-written only (flagged rows), never
            // a valid reader mode: see EventType::validReaderModes().
            'active_event_type' => ['required', 'string', Rule::in(EventType::validReaderModes()->all())],
        ];
    }
}
