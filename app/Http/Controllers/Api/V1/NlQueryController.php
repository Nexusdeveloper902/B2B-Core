<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\NlQueryRequest;
use App\Services\NlQuery\Exceptions\NlQueryException;
use App\Services\NlQuery\NlQueryService;
use Illuminate\Http\JsonResponse;

/**
 * Phase E — natural-language query interface (admin-only).
 *
 * POST /api/v1/nl-query  { "question": "..." }
 *
 * Gemini flash + function-calling. The LLM only selects functions and
 * phrases answers; all numbers come from real backend queries. When no
 * GEMINI_API_KEY is configured the endpoint reports a structured blocker
 * (503) instead of pretending to work — see ADR-005.
 */
class NlQueryController extends Controller
{
    public function __construct(
        private readonly NlQueryService $nlQuery,
    ) {}

    public function store(NlQueryRequest $request): JsonResponse
    {
        try {
            $result = $this->nlQuery->ask((string) $request->validated('question'));
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
            str_starts_with($e->getMessage(), 'nl_query.rate_limited') => 'llm_rate_limited',
            default => 'llm_unavailable',
        };

        $message = match ($reason) {
            'missing_llm_credential' => __('api.nlq_not_configured'),
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
