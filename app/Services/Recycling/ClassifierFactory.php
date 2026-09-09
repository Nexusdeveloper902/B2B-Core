<?php

namespace App\Services\Recycling;

use App\Contracts\MaterialClassifier;
use App\Services\Recycling\Drivers\DeepSeekClassifier;
use App\Services\Recycling\Drivers\LocalModelClassifier;
use App\Services\Recycling\Drivers\StubClassifier;

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
            'deepseek' => new DeepSeekClassifier(
                config('recycling.classifier.deepseek.api_key'),
                (string) config('recycling.classifier.deepseek.model'),
                (float) config('recycling.classifier.deepseek.timeout'),
            ),
            // A typo'd RECYCLING_CLASSIFIER_DRIVER must surface as the
            // codebase's standard classifier failure (HTTP 503 with a
            // device-displayable message), not an unhandled 500.
            default => throw ClassificationException::driverUnavailable(
                'factory',
                "unknown classifier driver [{$driver}] (expected stub | local | deepseek)",
            ),
        };
    }
}
