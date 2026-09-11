<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * TASK-027 — single student creation from the /admin/students desk.
 *
 * TASK-030-A (ADR-044) — the 1:1 student account IS created here now:
 * enrollment mints the login (convention email + initial password +
 * forced rotation) in the same transaction. The seeder's manual pattern
 * remains only for demo fixtures.
 */
class StudentStoreRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'grade' => ['required', 'string', 'max:64'],
            'class_id' => ['required', 'integer', 'exists:classes,id'],
            'pae_enrolled' => ['sometimes', 'boolean'],
        ];
    }
}
