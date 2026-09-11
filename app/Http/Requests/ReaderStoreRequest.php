<?php

namespace App\Http\Requests;

use App\Enums\EventType;
use App\Enums\ReaderType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * TASK-030-B (ADR-045) — reader creation from the /admin/readers desk:
 * label + physical type + initial active mode. The API key is NEVER
 * accepted from the client (server-generated, display-once).
 */
class ReaderStoreRequest extends FormRequest
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
            'type' => ['required', 'string', Rule::enum(ReaderType::class)],
            'active_event_type' => ['required', 'string', Rule::enum(EventType::class)],
        ];
    }
}
