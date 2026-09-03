<?php

namespace App\Services\Recycling\Drivers;

use App\Contracts\MaterialClassifier;
use App\Enums\MaterialClass;
use App\Services\Recycling\ClassificationException;
use Illuminate\Support\Facades\Http;

/**
 * Optional cloud vision fallback using the Gemini API (flash-family
 * models only — free-tier friendly). Disabled unless a GEMINI_API_KEY
 * is configured.
 *
 * Like every driver, this class is only ever reached through the
 * MaterialClassifier contract — controllers never know which driver ran.
 */
class GeminiClassifier implements MaterialClassifier
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models';

    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $model = 'gemini-3.1-flash-lite',
        private readonly float $timeout = 15.0,
    ) {}

    public function classify(string $imagePath): array
    {
        if (empty($this->apiKey)) {
            throw ClassificationException::driverUnavailable('gemini', 'no GEMINI_API_KEY configured');
        }

        if (! is_file($imagePath) || ! is_readable($imagePath)) {
            throw ClassificationException::driverUnavailable('gemini', "image not readable at [{$imagePath}]");
        }

        $payload = [
            'contents' => [[
                'parts' => [
                    ['text' => 'Classify the recyclable material shown in this image. '
                        .'Answer ONLY with a JSON object: {"material_class": "<plastic|paper|metal|glass|other>", "confidence": <0-1>}'],
                    [
                        'inline_data' => [
                            'mime_type' => mime_content_type($imagePath) ?: 'image/jpeg',
                            'data' => base64_encode(file_get_contents($imagePath)),
                        ],
                    ],
                ],
            ]],
            'generationConfig' => [
                'temperature' => 0,
                'response_mime_type' => 'application/json',
            ],
        ];

        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders(['x-goog-api-key' => $this->apiKey])
                ->post(self::ENDPOINT.'/'.urlencode($this->model).':generateContent', $payload);
        } catch (\Throwable $e) {
            throw ClassificationException::driverUnavailable('gemini', $e->getMessage());
        }

        if ($response->failed()) {
            throw ClassificationException::driverUnavailable(
                'gemini',
                "API returned HTTP {$response->status()}"
            );
        }

        $raw = $response->json('candidates.0.content.parts.0.text');
        $decoded = json_decode((string) $raw, true);

        $class = $decoded['material_class'] ?? null;
        $confidence = (float) ($decoded['confidence'] ?? 0);

        if (! is_string($class) || MaterialClass::tryFrom($class) === null) {
            throw ClassificationException::driverUnavailable('gemini', 'model response missing a valid material_class');
        }

        return [
            'material_class' => $class,
            'confidence' => round(min(max($confidence, 0.0), 1.0), 2),
        ];
    }
}
