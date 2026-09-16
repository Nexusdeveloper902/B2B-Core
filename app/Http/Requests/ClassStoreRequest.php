<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Rules\OwnedByCurrentSchool;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * TASK-029 — class creation from the /admin/students desk (the owner
 * hit the hole directly: the class select existed but there was no
 * way to create a class). Teacher assignment is OPTIONAL — a class
 * can exist before its homeroom teacher is chosen.
 */
class ClassStoreRequest extends FormRequest
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
            // Nullable: only TEACHER users may be assigned (a student or
            // admin id is a client bug, not a valid homeroom teacher).
            'teacher_user_id' => [
                'sometimes',
                'nullable',
                'integer',
                // TASK-045 — and only a teacher of THIS school.
                new OwnedByCurrentSchool(
                    User::class,
                    fn ($query) => $query->inCurrentSchool()->where('role', 'teacher'),
                ),
            ],
        ];
    }
}
