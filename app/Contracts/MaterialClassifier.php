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
 *   - DeepSeekClassifier    (optional cloud fallback — vision model
 *                          deepseek-flash; ADR-046)
 *
 * Swapping implementations is a .env config change
 * (RECYCLING_CLASSIFIER_DRIVER), not a code change. See ADR-003/ADR-007.
 */
interface MaterialClassifier
{
    /**
     * Classify a material image and return the material class, the
     * classifier's confidence, and the TASK-025 item 8 boundary
     * semantics (spec §9): is_bottle / is_recyclable.
     *
     * The booleans are BOUNDARY knowledge — persisted on the deposit and
     * available to future rules — while every business decision (points,
     * validity) stays backend-owned via material_class + config (§10).
     *
     * @param  string  $imagePath  absolute path to the uploaded image file
     * @return array{material_class: string, confidence: float, is_bottle: bool, is_recyclable: bool}
     *
     * @throws ClassificationException on driver failure
     */
    public function classify(string $imagePath): array;
}
