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
     * @param  array<int, mixed>|null  $tools  function declarations (lowercase OpenAPI types)
     * @return array{text: ?string, function_call: ?array{name: string, args: array<string, mixed>}, parts: array<int, mixed>}
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

        if ($response->failed()) {
            throw $this->mapApiFailure($response);
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

        // 'parts' carries the model turn VERBATIM (including any
        // thoughtSignature parts). Gemini 3.x requires echoing those back
        // unchanged on the next round — dropping them breaks multi-turn
        // function calling with a 400.
        return ['text' => $text, 'function_call' => $functionCall, 'parts' => (array) $parts];
    }

    /**
     * Map a failed HTTP response onto a TYPED exception using Google's
     * documented error contract: {code, message, status (gRPC
     * SCREAMING_CASE), details[{reason, ...}]} — see
     * ai.google.dev/gemini-api/docs/generate-content/api-errors.
     *
     * Typed = actionable: an invalid key, an unsupported region, a bad
     * model name and a quota hit each get their own exception so the
     * controller can tell the user what to actually DO (previously they
     * all collapsed into one misleading "service unavailable").
     */
    private function mapApiFailure($response): NlQueryException
    {
        $code = (int) $response->status();
        $status = (string) $response->json('error.status', '');
        $message = (string) $response->json('error.message', '');
        $reason = '';
        foreach ((array) $response->json('error.details', []) as $detail) {
            if (isset($detail['reason']) && is_string($detail['reason'])) {
                $reason = $detail['reason'];
                break;
            }
        }

        $summary = "HTTP {$code}".($reason !== '' ? " [{$reason}]" : '').($status !== '' ? " {$status}" : '')
            .': '.($message !== '' ? $message : substr((string) $response->body(), 0, 200));

        // Order matters: the region refusal and the invalid-key rejection
        // are BOTH plain 400s — the message/reason is what distinguishes
        // them (region errors carry no ErrorInfo reason; key errors do).
        if (str_contains($message, 'User location is not supported')
            || $reason === 'USER_LOCATION_UNSUPPORTED'
            || ($status === 'FAILED_PRECONDITION' && str_contains($message, 'location'))) {
            return NlQueryException::regionUnsupported($summary);
        }

        if ($reason === 'API_KEY_INVALID'
            || $code === 401 || $code === 403
            || $status === 'UNAUTHENTICATED' || $status === 'PERMISSION_DENIED'
            || str_contains($message, 'API key not valid')) {
            return NlQueryException::invalidKey($summary);
        }

        if ($code === 429 || $status === 'RESOURCE_EXHAUSTED') {
            return NlQueryException::rateLimited();
        }

        if ($code === 404 || $status === 'NOT_FOUND') {
            return NlQueryException::modelNotFound($summary);
        }

        return NlQueryException::transportFailure($summary);
    }
}
