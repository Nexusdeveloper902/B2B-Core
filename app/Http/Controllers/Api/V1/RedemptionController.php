<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\RedeemRequest;
use App\Models\Reward;
use App\Models\Student;
use App\Services\PointsService;
use App\Services\StudentScope;
use Illuminate\Http\JsonResponse;

/**
 * Phase D — points redemption (the "spend" half of the earn-and-spend loop;
 * the explicit differentiator vs. a points display with no spend mechanism).
 *
 * POST /api/v1/students/{id}/redeem — admin or teacher (desk interaction).
 *
 * TASK-025 item 7: rejections are now reason-shaped — insufficient /
 * inactive / out_of_stock / duplicate — and every success records a
 * first-class reward_redemptions row (see PointsService::spendOnReward).
 *
 * TASK-027 — the data wall: teachers may only redeem for students in
 * the classes they teach (admins stay school-wide).
 */
class RedemptionController extends Controller
{
    public function __construct(
        private readonly PointsService $points,
    ) {}

    public function store(RedeemRequest $request, Student $student): JsonResponse
    {
        $scope = StudentScope::forUser($request->user());
        abort_unless($scope->allowsStudent($student), 403, __('api.forbidden_role'));

        $reward = Reward::findOrFail($request->validated('reward_id'));

        $result = $this->points->spendOnReward($student, $reward, $request->validated('request_id'));

        if (! $result['ok']) {
            $body = [
                'status' => 'error',
                'reason' => $result['reason'],
                'current_balance' => $result['balance'],
                'reward_cost' => $reward->point_cost,
            ];

            $body['message'] = match ($result['reason']) {
                'insufficient' => __('api.insufficient_points', [
                    'shortfall' => $result['shortfall'],
                ]),
                'inactive' => __('api.reward_inactive'),
                'out_of_stock' => __('api.reward_out_of_stock'),
                'duplicate' => __('api.duplicate_redemption'),
                default => __('api.forbidden_role'),
            };

            if (isset($result['shortfall'])) {
                $body['shortfall'] = $result['shortfall'];
            }

            return response()->json($body, 422);
        }

        return response()->json([
            'status' => 'ok',
            'student_id' => $student->id,
            'reward' => [
                'id' => $reward->id,
                'name' => $reward->name,
                'point_cost' => $reward->point_cost,
            ],
            'redemption_id' => $result['redemption_id'],
            'replayed' => (bool) $result['replay'],
            'new_balance' => $result['balance'],
            'ledger_id' => $result['ledger_id'],
        ]);
    }
}
