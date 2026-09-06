<?php

namespace App\Services\Recycling\Drivers;

use App\Contracts\MaterialClassifier;
use App\Enums\MaterialClass;
use App\Services\Recycling\ClassificationException;
use Illuminate\Support\Facades\Http;

/**
 * Optional cloud vision fallback using the DeepSeek API
 * (deepseek-v4-flash-vision-exp — the only DeepSeek model that accepts
 * image input; non-vision models reject images with a 400, per
 * api-docs.deepseek.com/guides/vision). Disabled unless a
 * DEEPSEEK_API_KEY is configured.
 *
 * Like every driver, this class is only ever reached through the
 * MaterialClassifier contract — controllers never know which driver ran.
 */
class DeepSeekClassifier implements MaterialClassifier
{
    private const ENDPOINT = 'https://api.deepseek.com/chat/completions';

    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $model = 'deepseek-v4-flash-vision-exp',
        private readonly float $timeout = 15.0,
    ) {}

    public function classify(string $imagePath): array
    {
        if (empty($this->apiKey)) {
            throw ClassificationException::driverUnavailable('deepseek', 'no DEEPSEEK_API_KEY configured');
        }

        if (! is_file($imagePath) || ! is_readable($imagePath)) {
            throw ClassificationException::driverUnavailable('deepseek', "image not readable at [{$imagePath}]");
        }

        $mimeType = mime_content_type($imagePath) ?: 'image/jpeg';

        $payload = [
            'model' => $this->model,
            'messages' => [[
                'role' => 'user',
                'content' => [
                    // JSON Output contract (api-docs.deepseek.com, "JSON
                    // Output"): response_format json_object REQUIRES the
                    // word "json" plus a format example in the prompt.
                    // TASK-025 item 8: the prompt also asks for the
                    // is_bottle / is_recyclable boundary semantics (spec §9)
                    // — the model OPINES, the backend still owns the rules.
                    ['type' => 'text', 'text' => 'Classify the recyclable material shown in this image. '
                        .'Answer ONLY with a json object: {"material_class": "<plastic|paper|metal|glass|other>", "confidence": <0-1>, "is_bottle": <true|false>, "is_recyclable": <true|false>}'],
                    // Vision contract: images ride as data URLs in
                    // image_url content parts (user messages only).
                    [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => 'data:'.$mimeType.';base64,'.base64_encode(file_get_contents($imagePath)),
                        ],
                    ],
                ],
            ]],
            'response_format' => ['type' => 'json_object'],
            'temperature' => 0,
            'thinking' => ['type' => 'disabled'],
            'max_tokens' => 200,
        ];

        try {
            $response = Http::timeout($this->timeout)
                ->withToken($this->apiKey)
                ->post(self::ENDPOINT, $payload);
        } catch (\Throwable $e) {
            throw ClassificationException::driverUnavailable('deepseek', $e->getMessage());
        }

        if ($response->failed()) {
            throw ClassificationException::driverUnavailable(
                'deepseek',
                "API returned HTTP {$response->status()}"
            );
        }

        $raw = $response->json('choices.0.message.content');
        $decoded = json_decode((string) $raw, true);

        $class = $decoded['material_class'] ?? null;
        $confidence = (float) ($decoded['confidence'] ?? 0);

        if (! is_string($class) || MaterialClass::tryFrom($class) === null) {
            throw ClassificationException::driverUnavailable('deepseek', 'model response missing a valid material_class');
        }

        return [
            'material_class' => $class,
            'confidence' => round(min(max($confidence, 0.0), 1.0), 2),
            // TASK-025 item 8 — boundary semantics; defaulted from the
            // class when the model omits them (bottle => plastic shape,
            // recyclable => everything but 'other').
            'is_bottle' => (bool) ($decoded['is_bottle'] ?? ($class === 'plastic')),
            'is_recyclable' => (bool) ($decoded['is_recyclable'] ?? ($class !== 'other')),
        ];
    }
}
