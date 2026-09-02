<?php

namespace App\Services\Recycling;

use RuntimeException;

/**
 * Raised when a material-classifier driver fails (e.g. the local inference
 * service is unreachable). Deliberately distinct from validation errors:
 * the controller maps this to HTTP 502/503 so devices can retry later.
 */
class ClassificationException extends RuntimeException
{
    public static function driverUnavailable(string $driver, string $reason): self
    {
        return new self("Classifier driver [{$driver}] failed: {$reason}");
    }
}
