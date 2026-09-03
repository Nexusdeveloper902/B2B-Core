<?php

namespace Tests\Unit;

use App\Services\NlQuery\Exceptions\NlQueryException;
use App\Services\NlQuery\GeminiClient;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Google's DOCUMENTED error contract (pulled from the live docs,
 * ai.google.dev/gemini-api/docs/generate-content/api-errors):
 *
 *   {"error": {"code", "message", "status" (gRPC SCREAMING_CASE),
 *              "details": [{"reason": "API_KEY_INVALID", ...}]}}
 *
 * Every failure class must map to its OWN typed exception. The old generic
 * "service unavailable" masked real causes (invalid key, region refusal,
 * wrong model) for weeks — OBS-007 / TASK-007 lock this taxonomy in.
 *
 * Fixture bodies below are verbatim from the docs page / live probes.
 */
class GeminiClientTest extends TestCase
{
    private function client(): GeminiClient
    {
        return new GeminiClient('test-key', 'gemini-3.1-flash-lite', 5.0);
    }

    private function ask(): array
    {
        return $this->client()->generate([['role' => 'user', 'parts' => [['text' => 'hi']]]]);
    }

    #[Test]
    public function invalid_api_key_maps_to_invalid_key(): void
    {
        Http::fake(['*' => Http::response([
            'error' => [
                'code' => 400,
                'message' => 'API key not valid. Please pass a valid API key.',
                'status' => 'INVALID_ARGUMENT',
                'details' => [[
                    '@type' => 'type.googleapis.com/google.rpc.ErrorInfo',
                    'reason' => 'API_KEY_INVALID',
                    'domain' => 'googleapis.com',
                ]],
            ],
        ], 400)]);

        $this->expectException(NlQueryException::class);
        $this->expectExceptionMessage('nl_query.invalid_key');

        $this->ask();
    }

    #[Test]
    public function region_refusal_maps_to_region_unsupported(): void
    {
        // Verbatim from the live probes: plain 400 FAILED_PRECONDITION,
        // no ErrorInfo reason — the message is the only signal.
        Http::fake(['*' => Http::response([
            'error' => [
                'code' => 400,
                'message' => 'User location is not supported for the API use.',
                'status' => 'FAILED_PRECONDITION',
            ],
        ], 400)]);

        $this->expectException(NlQueryException::class);
        $this->expectExceptionMessage('nl_query.region_unsupported');

        $this->ask();
    }

    #[Test]
    public function unknown_model_maps_to_model_not_found(): void
    {
        Http::fake(['*' => Http::response([
            'error' => [
                'code' => 404,
                'message' => 'models/gemini-99-bogus is not found for API version v1beta',
                'status' => 'NOT_FOUND',
            ],
        ], 404)]);

        $this->expectException(NlQueryException::class);
        $this->expectExceptionMessage('nl_query.model_not_found');

        $this->ask();
    }

    #[Test]
    public function quota_maps_to_rate_limited(): void
    {
        Http::fake(['*' => Http::response([
            'error' => [
                'code' => 429,
                'message' => 'Resource has been exhausted (e.g. check quota).',
                'status' => 'RESOURCE_EXHAUSTED',
            ],
        ], 429)]);

        $this->expectException(NlQueryException::class);
        $this->expectExceptionMessage('nl_query.rate_limited');

        $this->ask();
    }

    #[Test]
    public function server_errors_stay_transport_failures(): void
    {
        Http::fake(['*' => Http::response([
            'error' => ['code' => 503, 'message' => 'The model is overloaded.', 'status' => 'UNAVAILABLE'],
        ], 503)]);

        $this->expectException(NlQueryException::class);
        $this->expectExceptionMessage('nl_query.transport_failure');

        $this->ask();
    }

    #[Test]
    public function success_returns_parts_verbatim_for_the_round_trip(): void
    {
        Http::fake(['*' => Http::response([
            'candidates' => [[
                'content' => [
                    'role' => 'model',
                    'parts' => [
                        ['text' => 'OK', 'thoughtSignature' => 'EnEKbwERTTIPlWKTGYVxi0W7=='],
                    ],
                ],
                'finishReason' => 'STOP',
            ]],
        ], 200)]);

        $result = $this->ask();

        $this->assertSame('OK', $result['text']);
        $this->assertNull($result['function_call']);
        // The raw parts (including thoughtSignature) must round-trip
        // verbatim for Gemini 3.x multi-turn function calling (ADR-015).
        $this->assertSame('EnEKbwERTTIPlWKTGYVxi0W7==', $result['parts'][0]['thoughtSignature']);
    }

    #[Test]
    public function no_key_fails_fast_before_any_network_call(): void
    {
        Http::fake();

        $client = new GeminiClient(null, 'gemini-3.1-flash-lite');

        $this->expectException(NlQueryException::class);
        $this->expectExceptionMessage('nl_query.not_configured');

        $client->generate([['role' => 'user', 'parts' => [['text' => 'hi']]]]);

        Http::assertNothingSent();
    }
}
