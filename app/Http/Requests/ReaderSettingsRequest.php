<?php

namespace App\Http\Requests;

use App\Enums\EventType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * TASK-027 — reader management settings (label + active mode),
 * backing the /admin/readers page. Same validation grammar as
 * ReaderModeRequest (canonical EventType set) plus the name.
 */
class ReaderSettingsRequest extends FormRequest
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
            'label' => ['required', 'string', 'min:3', 'max:255'],
            'active_event_type' => ['required', 'string', Rule::enum(EventType::class)],
        ];
    }
}
