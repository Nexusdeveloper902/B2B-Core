<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * TASK-045 (ADR-064) — "this id exists" is not the question; "this id
 * exists INSIDE the organization this request acts for" is.
 *
 * Laravel's `exists:` rule queries the table directly and therefore
 * walks straight past the model's organization scope. Every foreign key
 * a client may supply is validated through the scoped MODEL instead, so
 * a foreign id fails validation with the ordinary "invalid" message —
 * it never leaks the fact that the row exists somewhere else.
 */
class OwnedByCurrentSchool implements ValidationRule
{
    /**
     * @param  class-string<Model>  $model
     * @param  (Closure(Builder): mixed)|null  $constrain  extra constraints (e.g. a role)
     */
    public function __construct(
        private readonly string $model,
        private readonly ?Closure $constrain = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $query = $this->model::query()->whereKey($value);

        if ($this->constrain !== null) {
            ($this->constrain)($query);
        }

        if (! $query->exists()) {
            $fail('validation.exists')->translate(['attribute' => $attribute]);
        }
    }
}
