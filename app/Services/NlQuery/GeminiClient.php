<?php

namespace App\Services\NlQuery;

use App\Services\NlQuery\Exceptions\NlQueryException;
use Illuminate\Support\Facades\Http;

/**
 * Minimal Gemini generateContent client with native function-calling
 * support. Flash models only (free-tier friendly). The API key is passed
 * via the x-goog-api-key header, never in URLs.
 */
class GeminiClient
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models';

    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $model,
        private readonly float $timeout = 20.0,
    ) {}

    public function isConfigured(): bool
    {
        return ! empty($this->apiKey);
    }

    /**
     * One round-trip to the Gemini API.
     *
     * @param  array<int, mixed>  $contents  the full conversation contents (may include prior function calls/responses)
     * @param  array<int, mixed>|null  $tools  function declarations
     * @return array{text: ?string, function_call: ?array{name: string, args: array<string, mixed>}}
     *
     * @throws NlQueryException
     */
    public function generate(array $contents, ?array $tools = null): array
    {
        if (! $this->isConfigured()) {
            throw NlQueryException::notConfigured();
        }

        $payload = [
            'contents' => $contents,
            'generationConfig' => ['temperature' => 0],
        ];

        if ($tools !== null) {
            $payload['tools'] = [['function_declarations' => $tools]];
        }

        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders(['x-goog-api-key' => $this->apiKey])
                ->acceptJson()
                ->post(self::ENDPOINT.'/'.urlencode($this->model).':generateContent', $payload);
        } catch (\Throwable $e) {
            throw NlQueryException::transportFailure($e->getMessage());
        }

        if ($response->status() === 429) {
            throw NlQueryException::rateLimited();
        }

        if ($response->failed()) {
            throw NlQueryException::transportFailure("HTTP {$response->status()}: ".substr((string) $response->body(), 0, 300));
        }

        $blocked = $response->json('candidates.0.finishReason');
        if ($blocked === 'SAFETY' || $blocked === 'RECITATION') {
            throw NlQueryException::transportFailure("model refused (finishReason: {$blocked})");
        }

        $parts = $response->json('candidates.0.content.parts', []);

        $text = null;
        $functionCall = null;

        foreach ((array) $parts as $part) {
            if (isset($part['functionCall']['name'])) {
                $functionCall = [
                    'name' => (string) $part['functionCall']['name'],
                    'args' => (array) ($part['functionCall']['args'] ?? []),
                ];
            } elseif (isset($part['text'])) {
                $text = $text.(string) $part['text'];
            }
        }

        return ['text' => $text, 'function_call' => $functionCall];
    }
}
