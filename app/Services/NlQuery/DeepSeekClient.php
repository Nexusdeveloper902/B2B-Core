<?php

namespace App\Services\NlQuery;

use App\Services\NlQuery\Exceptions\NlQueryException;
use Illuminate\Support\Facades\Http;

/**
 * Minimal DeepSeek chat-completions client with native tool-calling
 * support (OpenAI-compatible wire format — see api-docs.deepseek.com,
 * "Your First API Call" / "Tool Calls"). The API key is passed via the
 * Authorization: Bearer header, never in URLs.
 *
 * Default model: deepseek-v4-flash (DeepSeek-V4-Flash-0731 — public beta
 * API since 2026-07-31). The legacy deepseek-chat/deepseek-reasoner names
 * were discontinued on 2026-07-24 and must not be used.
 *
 * Thinking mode is DISABLED for every call: V4 models think by default
 * (effort "high"), which multiplies latency and cost for a
 * function-selection task that does not need chain-of-thought — and
 * thinking mode silently ignores the temperature parameter, so the
 * deterministic temperature=0 contract only holds with thinking off.
 */
class DeepSeekClient
{
    private const ENDPOINT = 'https://api.deepseek.com/chat/completions';

    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $model = 'deepseek-v4-flash',
        private readonly float $timeout = 20.0,
    ) {}

    public function isConfigured(): bool
    {
        return ! empty($this->apiKey);
    }

    /**
     * One round-trip to the DeepSeek Chat Completions API.
     *
     * @param  array<int, mixed>  $messages  full conversation (system/user/assistant/tool messages; assistant turns echo verbatim)
     * @param  array<int, mixed>|null  $tools  provider-neutral declarations (name/description/parameters, lowercase JSON-Schema types)
     * @return array{
     *   text: ?string,
     *   tool_calls: array<int, array{id: string, name: string, arguments: array<string, mixed>}>,
     *   message: array<string, mixed>
     * }
     *
     * @throws NlQueryException
     */
    public function generate(array $messages, ?array $tools = null): array
    {
        if (! $this->isConfigured()) {
            throw NlQueryException::notConfigured();
        }

        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => 0,
            'thinking' => ['type' => 'disabled'],
        ];

        if ($tools !== null && $tools !== []) {
            // OpenAI tools shape: {type: "function", function: {name,
            // description, parameters}} — the registry's neutral
            // declarations carry exactly those inner keys.
            $payload['tools'] = array_map(
                fn (array $declaration): array => ['type' => 'function', 'function' => $declaration],
                $tools
            );
            $payload['tool_choice'] = 'auto';
        }

        try {
            $response = Http::timeout($this->timeout)
                ->withToken($this->apiKey)
                ->acceptJson()
                ->post(self::ENDPOINT, $payload);
        } catch (\Throwable $e) {
            throw NlQueryException::transportFailure($e->getMessage());
        }

        if ($response->failed()) {
            throw $this->mapApiFailure($response);
        }

        $message = (array) $response->json('choices.0.message', []);

        $finishReason = (string) $response->json('choices.0.finish_reason', '');
        if ($finishReason === 'content_filter') {
            throw NlQueryException::transportFailure('model refused (finish_reason: content_filter)');
        }

        $text = isset($message['content']) && is_string($message['content'])
            ? $message['content']
            : null;

        $toolCalls = [];
        foreach ((array) ($message['tool_calls'] ?? []) as $call) {
            $name = (string) ($call['function']['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $rawArguments = (string) ($call['function']['arguments'] ?? 'null');
            $arguments = json_decode($rawArguments, true);
            if (! is_array($arguments)) {
                // The docs warn the model "does not always generate valid
                // JSON" for arguments — fail loudly instead of executing a
                // half-parsed call against the real database.
                throw NlQueryException::transportFailure("tool call [{$name}] arguments are not valid JSON");
            }

            $toolCalls[] = [
                'id' => (string) ($call['id'] ?? ''),
                'name' => $name,
                'arguments' => $arguments,
            ];
        }

        // 'message' carries the assistant turn VERBATIM (content,
        // tool_calls with their raw string arguments, any reasoning
        // fields). The DeepSeek docs require echoing it back unchanged
        // on the next round — the OpenAI-format analogue of the Gemini
        // 3.x thoughtSignature contract (ADR-015; now ADR-030).
        return ['text' => $text, 'tool_calls' => $toolCalls, 'message' => $message];
    }

    /**
     * Map a failed HTTP response onto a TYPED exception using DeepSeek's
     * documented error contract (api-docs.deepseek.com, "Error Codes"):
     * 400 Invalid Format · 401 Authentication Fails · 402 Insufficient
     * Balance · 422 Invalid Parameters · 429 Rate Limit · 500 Server
     * Error · 503 Server Overloaded — body {"error": {message, ...}}.
     *
     * Typed = actionable: an invalid key, an empty account balance, a bad
     * model name and a quota hit each get their own exception so the
     * controller can tell the user what to actually DO (ADR-016 taxonomy,
     * provider-swap edition). The Gemini-era region class is gone —
     * DeepSeek documents no region restriction for the API.
     */
    private function mapApiFailure($response): NlQueryException
    {
        $code = (int) $response->status();
        $message = (string) $response->json('error.message', '');

        $summary = "HTTP {$code}"
            .($message !== '' ? ': '.$message : ': '.substr((string) $response->body(), 0, 200));

        if ($code === 401) {
            return NlQueryException::invalidKey($summary);
        }

        if ($code === 402) {
            return NlQueryException::insufficientBalance($summary);
        }

        if ($code === 404 || str_contains($message, 'Model Not Exist')) {
            return NlQueryException::modelNotFound($summary);
        }

        if ($code === 429) {
            return NlQueryException::rateLimited();
        }

        return NlQueryException::transportFailure($summary);
    }
}
