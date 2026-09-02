<?php

namespace App\Contracts;

use App\Services\Recycling\ClassificationException;

/**
 * Swappable material-classification contract (Phase C).
 *
 * The recycling controller depends on THIS interface, never on a concrete
 * classifier. Implementations:
 *
 *   - StubClassifier       (dev/CI default: plausible, deterministic-ish)
 *   - LocalModelClassifier (local model-inference service; the intended
 *                          production driver when the platform runs fully
 *                          on local hardware — see docs/LOCAL_MODEL.md)
 *   - GeminiClassifier     (optional cloud fallback)
 *
 * Swapping implementations is a .env config change
 * (RECYCLING_CLASSIFIER_DRIVER), not a code change. See ADR-003/ADR-007.
 */
interface MaterialClassifier
{
    /**
     * Classify a material image and return the material class and the
     * classifier's confidence.
     *
     * @param  string  $imagePath  absolute path to the uploaded image file
     * @return array{material_class: string, confidence: float}
     *
     * @throws ClassificationException on driver failure
     */
    public function classify(string $imagePath): array;
}
