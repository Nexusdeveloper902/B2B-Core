<?php

namespace App\Services\Recycling\Drivers;

use App\Contracts\MaterialClassifier;
use App\Enums\MaterialClass;
use App\Services\Recycling\ClassificationException;
use Illuminate\Support\Facades\Http;

/**
 * Local model-inference driver (the intended production driver).
 *
 * The platform is designed to run fully locally at later stages, including
 * the classification model. This driver performs an HTTP call to a LOCAL
 * inference endpoint (TF Serving / ONNX Runtime service / a small FastAPI
 * sidecar — see scripts/local-model-server/ for a reference server) using
 * a stable multipart contract:
 *
 *   POST {LOCAL_CLASSIFIER_URL}
 *   multipart/form-data: image=<binary file>
 *   200 JSON: { "material_class": "plastic", "confidence": 0.87 }
 *
 * material_class must be one of: plastic, paper, metal, glass, other.
 * Zero backend changes are needed when a real model is deployed — only the
 * endpoint behind this contract starts returning real predictions.
 * See ADR-007 and docs/LOCAL_MODEL.md.
 */
class LocalModelClassifier implements MaterialClassifier
{
    public function __construct(
        private readonly string $url,
        private readonly float $timeout = 10.0,
    ) {}

    public function classify(string $imagePath): array
    {
        if (! is_file($imagePath) || ! is_readable($imagePath)) {
            throw ClassificationException::driverUnavailable('local', "image not readable at [{$imagePath}]");
        }

        try {
            $response = Http::timeout($this->timeout)
                ->attach('image', file_get_contents($imagePath), basename($imagePath))
                ->post($this->url);
        } catch (\Throwable $e) {
            throw ClassificationException::driverUnavailable('local', $e->getMessage());
        }

        if ($response->failed()) {
            throw ClassificationException::driverUnavailable(
                'local',
                "inference endpoint returned HTTP {$response->status()}"
            );
        }

        $class = $response->json('material_class');
        $confidence = $response->json('confidence');

        if (! is_string($class) || MaterialClass::tryFrom($class) === null) {
            throw ClassificationException::driverUnavailable(
                'local',
                'endpoint response missing a valid material_class (expected one of: plastic, paper, metal, glass, other)'
            );
        }

        return [
            'material_class' => $class,
            'confidence' => (float) $confidence,
        ];
    }
}
