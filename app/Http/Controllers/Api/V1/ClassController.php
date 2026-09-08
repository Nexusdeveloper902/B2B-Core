<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClassStoreRequest;
use App\Models\RosterUpdate;
use App\Models\SchoolClass;
use App\Services\Realtime\RosterUpdateLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * TASK-029 — class management through the GUI (no more "create the
 * class by hand-written SQL before you can even import a roster").
 *
 * POST /api/v1/admin/classes  { name, teacher_user_id? } — admin-only.
 *
 * The duplicate rule mirrors the student desk's (same name in the
 * same table = 422 with a bilingual message, never a silent update),
 * and the roster channel frame rides the same transaction so the
 * students desk's class select goes live the moment the class exists.
 */
class ClassController extends Controller
{
    public function store(ClassStoreRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $duplicate = SchoolClass::query()
            ->whereRaw('lower(name) = lower(?)', [trim((string) $validated['name'])])
            ->exists();

        if ($duplicate) {
            return response()->json([
                'status' => 'error',
                'reason' => 'duplicate',
                'message' => __('api.class_duplicate', ['name' => $validated['name']]),
            ], 422);
        }

        $class = DB::transaction(function () use ($validated) {
            $class = SchoolClass::create([
                'name' => trim((string) $validated['name']),
                'teacher_user_id' => isset($validated['teacher_user_id'])
                    ? (int) $validated['teacher_user_id']
                    : null,
            ]);

            RosterUpdateLog::record(RosterUpdate::TYPE_CLASS_CREATED, [
                'id' => $class->id,
                'name' => $class->name,
                'teacher_name' => $class->teacher?->name,
            ]);

            return $class;
        });

        return response()->json([
            'status' => 'ok',
            'class' => [
                'id' => $class->id,
                'name' => $class->name,
                'teacher_name' => $class->teacher?->name,
            ],
            'message' => __('api.class_created', ['name' => $class->name]),
        ]);
    }
}
