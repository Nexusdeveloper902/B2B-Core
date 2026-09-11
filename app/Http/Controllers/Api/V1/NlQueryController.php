<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\NlQueryRequest;
use App\Services\NlQuery\Exceptions\NlQueryException;
use App\Services\NlQuery\NlQueryService;
use App\Services\StudentScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Phase E — natural-language query interface.
 *
 * POST /api/v1/nl-query  { "question": "..." }
 *
 * TASK-027 — admin AND teacher. A teacher's questions are fenced by
 * their StudentScope (own classes) at every function execution — the
 * data wall is server-side, never prompt-side. Students stay 403.
 *
 * DeepSeek (deepseek-flash) tool-calling. The LLM only selects
 * functions and phrases answers; all numbers come from real backend
 * queries. When no DEEPSEEK_API_KEY is configured the endpoint reports
 * a structured blocker (503) instead of pretending to work — see
 * ADR-005.
 */
class NlQueryController extends Controller
{
    public function __construct(
        private readonly NlQueryService $nlQuery,
    ) {}

    public function store(NlQueryRequest $request): JsonResponse
    {
        $scope = StudentScope::forUser($request->user());

        try {
            $result = $this->nlQuery->ask((string) $request->validated('question'), $scope);
        } catch (NlQueryException $e) {
            return $this->blockedResponse($e);
        }

        return response()->json([
            'status' => 'ok',
            'answer' => $result['answer'],
            'functions_called' => $result['functions_called'],
        ]);
    }

    private function blockedResponse(NlQueryException $e): JsonResponse
    {
        $reason = match (true) {
            str_starts_with($e->getMessage(), 'nl_query.not_configured') => 'missing_llm_credential',
            str_starts_with($e->getMessage(), 'nl_query.invalid_key') => 'llm_invalid_key',
            str_starts_with($e->getMessage(), 'nl_query.insufficient_balance') => 'llm_insufficient_balance',
            str_starts_with($e->getMessage(), 'nl_query.model_not_found') => 'llm_model_not_found',
            str_starts_with($e->getMessage(), 'nl_query.rate_limited') => 'llm_rate_limited',
            default => 'llm_unavailable',
        };

        // Ops visibility: the exact underlying cause (including the raw
        // HTTP status/body excerpt from DeepSeekClient) lands in the log —
        // never in the API response (no internals leak to clients).
        Log::warning('NL query blocked', [
            'reason' => $reason,
            'detail' => $e->getMessage(),
        ]);

        $message = match ($reason) {
            'missing_llm_credential' => __('api.nlq_not_configured'),
            'llm_invalid_key' => __('api.nlq_invalid_key'),
            'llm_insufficient_balance' => __('api.nlq_insufficient_balance'),
            'llm_model_not_found' => __('api.nlq_model_not_found'),
            'llm_rate_limited' => __('api.nlq_rate_limited'),
            default => __('api.nlq_unavailable'),
        };

        return response()->json([
            'status' => 'blocked',
            'blocked_reason' => $reason,
            'message' => $message,
        ], 503);
    }
}
