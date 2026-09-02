<?php

namespace App\Services\Recycling\Drivers;

use App\Contracts\MaterialClassifier;
use App\Enums\MaterialClass;

/**
 * Stub material classifier (Phase C — sanctioned stub, see ADR-003).
 *
 * Deterministic per-image: the class is derived from a SHA-256 hash of the
 * image bytes (same image => same class, which keeps tests stable), and
 * confidence is derived from the same hash. This is intentional: it lets
 * the entire downstream pipeline (points, ledger, dashboards, NL query)
 * be built and exercised today with zero model dependencies, exactly like
 * the rest of the Hardware Abstraction Principle.
 */
class StubClassifier implements MaterialClassifier
{
    public function classify(string $imagePath): array
    {
        if (! is_file($imagePath) || ! is_readable($imagePath)) {
            throw new \RuntimeException("StubClassifier: image not readable at [{$imagePath}]");
        }

        $hash = hash_file('sha256', $imagePath);
        $materials = MaterialClass::cases();

        $class = $materials[hexdec(substr($hash, 0, 4)) % count($materials)];
        $confidence = round(0.55 + (hexdec(substr($hash, 4, 4)) % 45) / 100, 2); // 0.55–0.99

        return [
            'material_class' => $class->value,
            'confidence' => $confidence,
        ];
    }
}
