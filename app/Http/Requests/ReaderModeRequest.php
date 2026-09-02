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
            'active_event_type' => ['required', 'string', Rule::enum(EventType::class)],
        ];
    }
}
