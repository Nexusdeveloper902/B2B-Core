<?php

namespace App\Services\Recycling;

use App\Contracts\MaterialClassifier;
use App\Services\Recycling\Drivers\GeminiClassifier;
use App\Services\Recycling\Drivers\LocalModelClassifier;
use App\Services\Recycling\Drivers\StubClassifier;
use InvalidArgumentException;

/**
 * Resolves the configured MaterialClassifier driver from config/recycling.php.
 * Registering a new driver = adding it here + config — no controller edits.
 */
class ClassifierFactory
{
    public static function make(): MaterialClassifier
    {
        $driver = config('recycling.classifier.driver', 'stub');

        return match ($driver) {
            'stub' => app(StubClassifier::class),
            'local' => new LocalModelClassifier(
                (string) config('recycling.classifier.local.url'),
                (float) config('recycling.classifier.local.timeout'),
            ),
            'gemini' => new GeminiClassifier(
                config('recycling.classifier.gemini.api_key'),
                (string) config('recycling.classifier.gemini.model'),
                (float) config('recycling.classifier.gemini.timeout'),
            ),
            default => throw new InvalidArgumentException("Unknown classifier driver [{$driver}]"),
        };
    }
}
