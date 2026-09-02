<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\RedeemRequest;
use App\Models\Reward;
use App\Models\Student;
use App\Services\PointsService;
use Illuminate\Http\JsonResponse;

/**
 * Phase D — points redemption (the "spend" half of the earn-and-spend loop;
 * the explicit differentiator vs. a points display with no spend mechanism).
 *
 * POST /api/v1/students/{id}/redeem — admin or teacher (desk interaction).
 */
class RedemptionController extends Controller
{
    public function __construct(
        private readonly PointsService $points,
    ) {}

    public function store(RedeemRequest $request, Student $student): JsonResponse
    {
        $reward = Reward::findOrFail($request->validated('reward_id'));

        $result = $this->points->spendOnReward($student, $reward);

        if (! $result['ok']) {
            return response()->json([
                'status' => 'error',
                'message' => __('api.insufficient_points', [
                    'shortfall' => $result['shortfall'],
                ]),
                'current_balance' => $result['balance'],
                'reward_cost' => $reward->point_cost,
                'shortfall' => $result['shortfall'],
            ], 422);
        }

        return response()->json([
            'status' => 'ok',
            'student_id' => $student->id,
            'reward' => [
                'id' => $reward->id,
                'name' => $reward->name,
                'point_cost' => $reward->point_cost,
            ],
            'new_balance' => $result['balance'],
            'ledger_id' => $result['ledger_id'],
        ]);
    }
}
