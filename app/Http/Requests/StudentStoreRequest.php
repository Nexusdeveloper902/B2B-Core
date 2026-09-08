<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * TASK-027 — single student creation from the /admin/students desk.
 * (The 1:1 student account layer is NOT created here: accounts are a
 * separate, deliberate step — the seeder's pattern — not a silent
 * side effect of enrollment.)
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
