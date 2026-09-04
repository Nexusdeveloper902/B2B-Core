<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Services\PairingService;
use Illuminate\Http\JsonResponse;

/**
 * TASK-010 — arm a pending pairing (dashboard side of the two-step
 * arm-then-pair flow). POST /api/v1/admin/students/{id}/arm-pairing —
 * admin-only, same dual-auth convention (session or PAT) as the other
 * admin-side endpoints.
 */
class ArmPairingController extends Controller
{
    public function __construct(
        private readonly PairingService $pairings,
    ) {}

    public function store(Student $student): JsonResponse
    {
        $pairing = $this->pairings->arm($student);

        return response()->json([
            'status' => 'ok',
            'student_id' => $student->id,
            'expires_at' => $pairing->expires_at->toIso8601String(),
        ]);
    }
}
