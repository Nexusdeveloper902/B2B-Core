<?php

namespace Tests\Unit;

use App\Services\NlQuery\DeepSeekClient;
use App\Services\NlQuery\Exceptions\NlQueryException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * DeepSeek's DOCUMENTED error contract (pulled from the live docs,
 * api-docs.deepseek.com — "Error Codes"):
 *
 *   401 Authentication Fails · 402 Insufficient Balance ·
 *   422 Invalid Parameters · 429 Rate Limit · 500 Server Error ·
 *   503 Server Overloaded, body {"error": {"message": …}}
 *   (a nonexistent model answers 404 "Model Not Exist").
 *
 * Every failure class must map to its OWN typed exception. The old generic
 * "service unavailable" masked real causes (invalid key, region refusal,
 * wrong model) for weeks — OBS-007 / TASK-007 lock this taxonomy in;
 * TASK-022 re-locks it for the DeepSeek provider (ADR-030).
 *
 * Fixture bodies below follow the docs page's documented causes.
 */
class DeepSeekClientTest extends TestCase
{
    private function client(): DeepSeekClient
    {
        return new DeepSeekClient('test-key', 'deepseek-flash', 5.0);
    }

    private function ask(): array
    {
        return $this->client()->generate([['role' => 'user', 'content' => 'hi']]);
    }

    #[Test]
    public function invalid_api_key_maps_to_invalid_key(): void
    {
        // 401 — "Authentication fails due to the wrong API key."
        Http::fake(['*' => Http::response([
            'error' => [
                'message' => 'Authentication Fails, Your api key: ***key is invalid',
                'type' => 'authentication_error',
                'code' => 'invalid_request_error',
            ],
        ], 401)]);

        $this->expectException(NlQueryException::class);
        $this->expectExceptionMessage('nl_query.invalid_key');

        $this->ask();
    }

    #[Test]
    public function empty_balance_maps_to_insufficient_balance(): void
    {
        // 402 — "Insufficient Balance": the key is VALID; the account has
        // no balance left. Its own class because the fix is topping up,
        // not rotating the key or retrying.
        Http::fake(['*' => Http::response([
            'error' => [
                'message' => 'Insufficient Balance',
                'type' => 'insufficient_balance',
                'code' => 'insufficient_balance',
            ],
        ], 402)]);

        $this->expectException(NlQueryException::class);
        $this->expectExceptionMessage('nl_query.insufficient_balance');

        $this->ask();
    }

    #[Test]
    public function unknown_model_maps_to_model_not_found(): void
    {
        // DeepSeek answers a nonexistent model with 404 "Model Not Exist".
        Http::fake(['*' => Http::response([
            'error' => [
                'message' => 'Model Not Exist',
                'type' => 'invalid_request_error',
                'code' => 'invalid_request_error',
            ],
        ], 404)]);

        $this->expectException(NlQueryException::class);
        $this->expectExceptionMessage('nl_query.model_not_found');

        $this->ask();
    }

    #[Test]
    public function quota_maps_to_rate_limited(): void
    {
        // 429 — "Rate Limit Reached ... sending requests too quickly."
        Http::fake(['*' => Http::response([
            'error' => [
                'message' => 'You are sending requests too quickly, and the rate limit has been reached',
                'type' => 'rate_limit_error',
            ],
        ], 429)]);

        $this->expectException(NlQueryException::class);
        $this->expectExceptionMessage('nl_query.rate_limited');

        $this->ask();
    }

    #[Test]
    public function server_errors_stay_transport_failures(): void
    {
        // 503 — "Server Overloaded ... retry after a brief wait" — the
        // documented-transient class the CI live-gate retries (ADR-019).
        Http::fake(['*' => Http::response([
            'error' => [
                'message' => 'The server is overloaded due to high traffic',
                'type' => 'server_error',
            ],
        ], 503)]);

        $this->expectException(NlQueryException::class);
        $this->expectExceptionMessage('nl_query.transport_failure');

        $this->ask();
    }

    #[Test]
    public function success_returns_the_assistant_message_verbatim_for_the_round_trip(): void
    {
        Http::fake(['*' => Http::response([
            'choices' => [[
                'index' => 0,
                'finish_reason' => 'stop',
                'message' => [
                    'role' => 'assistant',
                    'content' => 'OK',
                ],
            ]],
            'model' => 'DeepSeek-V4-Flash-0731',
        ], 200)]);

        $result = $this->ask();

        $this->assertSame('OK', $result['text']);
        $this->assertSame([], $result['tool_calls']);
        // The raw assistant message must round-trip verbatim for
        // multi-turn tool calling (ADR-030 — the OpenAI-format analogue
        // of the Gemini thoughtSignature contract).
        $this->assertSame('assistant', $result['message']['role']);
        $this->assertSame('OK', $result['message']['content']);
    }

    #[Test]
    public function tool_calls_are_parsed_and_the_raw_message_preserved(): void
    {
        // DeepSeek returns arguments as a JSON STRING (documented in the
        // Chat Completions API reference) — the client must decode them
        // while echoing the raw message (string arguments intact) back.
        Http::fake(['*' => Http::response([
            'choices' => [[
                'index' => 0,
                'finish_reason' => 'tool_calls',
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_0_9abc123',
                        'type' => 'function',
                        'function' => [
                            'name' => 'get_attendance_count',
                            'arguments' => '{"date":"2026-09-06"}',
                        ],
                    ]],
                ],
            ]],
        ], 200)]);

        $result = $this->ask();

        $this->assertNull($result['text']);
        $this->assertCount(1, $result['tool_calls']);
        $this->assertSame('call_0_9abc123', $result['tool_calls'][0]['id']);
        $this->assertSame('get_attendance_count', $result['tool_calls'][0]['name']);
        $this->assertSame(['date' => '2026-09-06'], $result['tool_calls'][0]['arguments']);
        // Verbatim echo: the raw message keeps the JSON-string arguments.
        $this->assertSame(
            '{"date":"2026-09-06"}',
            $result['message']['tool_calls'][0]['function']['arguments']
        );
    }

    #[Test]
    public function invalid_tool_call_arguments_fail_loudly(): void
    {
        // The docs warn: "the model does not always generate valid JSON"
        // for arguments — a half-parsed call must never reach the real
        // database.
        Http::fake(['*' => Http::response([
            'choices' => [[
                'index' => 0,
                'finish_reason' => 'tool_calls',
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_broken',
                        'type' => 'function',
                        'function' => [
                            'name' => 'get_attendance_count',
                            'arguments' => '{not json',
                        ],
                    ]],
                ],
            ]],
        ], 200)]);

        $this->expectException(NlQueryException::class);
        $this->expectExceptionMessage('nl_query.transport_failure');

        $this->ask();
    }

    #[Test]
    public function tools_are_sent_in_the_deepseek_envelope(): void
    {
        Http::fake(['*' => Http::response([
            'choices' => [[
                'index' => 0,
                'finish_reason' => 'stop',
                'message' => ['role' => 'assistant', 'content' => 'OK'],
            ]],
        ], 200)]);

        $this->client()->generate(
            [['role' => 'user', 'content' => 'hi']],
            [['name' => 'get_attendance_count', 'description' => 'count', 'parameters' => ['type' => 'object', 'properties' => []]]]
        );

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $body['tools'][0]['type'] === 'function'
                && $body['tools'][0]['function']['name'] === 'get_attendance_count'
                && $body['tool_choice'] === 'auto'
                && $body['thinking'] === ['type' => 'disabled']
                && $body['temperature'] === 0;
        });
    }

    #[Test]
    public function no_key_fails_fast_before_any_network_call(): void
    {
        Http::fake();

        $client = new DeepSeekClient(null, 'deepseek-flash');

        $this->expectException(NlQueryException::class);
        $this->expectExceptionMessage('nl_query.not_configured');

        $client->generate([['role' => 'user', 'content' => 'hi']]);

        Http::assertNothingSent();
    }

    #[Test]
    public function the_default_model_is_the_canonical_flash_id(): void
    {
        // ADR-046 — api-docs.deepseek.com (Models & Pricing): the model
        // is deepseek-flash; legacy deepseek-v4-flash still serves.
        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
        ], 200)]);

        $result = (new DeepSeekClient('test-key'))->generate([['role' => 'user', 'content' => 'hi']]);

        $this->assertSame('ok', $result['text']);

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return ($payload['model'] ?? null) === 'deepseek-flash'
                && ($payload['thinking'] ?? []) === ['type' => 'disabled']
                && ($payload['temperature'] ?? null) === 0;
        });
    }
}
