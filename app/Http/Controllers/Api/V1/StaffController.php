<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StaffStoreRequest;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * TASK-038 (ADR-058) — staff account provisioning: create admin,
 * teacher and kitchen logins from the GUI with an admin-chosen
 * temporary password (display-once), forced first-login rotation,
 * and — for teachers — homeroom class assignment in the SAME
 * transaction.
 *
 * POST /api/v1/admin/staff  { name, email, role, password,
 *   password_confirmation, class_ids? } — admin-only.
 *
 * The temporary password appears ONLY as the top-level
 * `temporary_password` of the minting response — never in the staff
 * object, never in logs (the student-login display-once rule,
 * ADR-044). There is deliberately NO update/delete here (create-only,
 * the ClassController precedent): editing staff and re-homing classes
 * stay a separate task.
 */
class StaffController extends Controller
{
    public function store(StaffStoreRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Normalized once: the desk treats addresses case-insensitively
        // on both engines (see the request rules).
        $email = mb_strtolower(trim((string) $validated['email']));

        if (! empty($validated['class_ids'] ?? []) && $validated['role'] !== UserRole::Teacher->value) {
            return response()->json([
                'status' => 'error',
                'reason' => 'classes_teacher_only',
                'message' => __('api.staff_classes_teacher_only'),
            ], 422);
        }

        // Advisory pre-check, case-insensitive (SQLite's UNIQUE is
        // case-sensitive while MariaDB's utf8mb4_unicode_ci is not —
        // the invariant owns the truth either way, see the 23000
        // catch below).
        $taken = User::query()
            ->whereRaw('lower(email) = lower(?)', [$email])
            ->exists();

        if ($taken) {
            return $this->duplicate($email);
        }

        try {
            $user = DB::transaction(function () use ($validated, $email) {
                $user = User::create([
                    'name' => trim((string) $validated['name']),
                    'email' => $email,
                    'password' => (string) $validated['password'],
                    'role' => $validated['role'],
                    'must_change_password' => true,
                ]);

                // Homeroom assignment rides the same transaction: the
                // account can never commit without its classes (and a
                // reassigned class never points at a rolled-back user).
                // Overwrites any previous homeroom teacher — the desk
                // says so before submitting.
                if ($validated['role'] === UserRole::Teacher->value && ! empty($validated['class_ids'] ?? [])) {
                    SchoolClass::whereIn('id', $validated['class_ids'])->update(['teacher_user_id' => $user->id]);
                }

                return $user;
            });
        } catch (QueryException $e) {
            // Lost race against a concurrent identical create — the
            // users_email_unique invariant owns the truth. A twin row
            // renders as the same honest 422 duplicate; any other
            // integrity failure is a true anomaly and rethrows (never
            // mislabeled — the StudentController 3.2 rule).
            $twin = $e->getCode() === '23000'
                && User::query()
                    ->whereRaw('lower(email) = lower(?)', [$email])
                    ->exists();

            if (! $twin) {
                throw $e;
            }

            return $this->duplicate($email);
        }

        $classes = $user->classes()->orderBy('name')->pluck('name')->all();

        return response()->json([
            'status' => 'ok',
            'staff' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'classes' => $classes,
            ],
            // Display-once credentials (the reader-API-key rule): this
            // response is the ONLY place the temporary password appears.
            'account' => [
                'email' => $user->email,
                'temporary_password' => (string) $validated['password'],
                'must_change_password' => true,
            ],
            'message' => __('api.staff_created', ['name' => $user->name]),
            'account_notice' => __('api.staff_account_notice', [
                'email' => $user->email,
                'password' => (string) $validated['password'],
            ]),
        ]);
    }

    private function duplicate(string $email): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'reason' => 'duplicate',
            'message' => __('api.staff_duplicate', ['email' => trim($email)]),
        ], 422);
    }
}
