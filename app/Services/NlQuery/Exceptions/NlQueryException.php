<?php

namespace App\Services\NlQuery\Exceptions;

use RuntimeException;

class NlQueryException extends RuntimeException
{
    public static function notConfigured(): self
    {
        return new self('nl_query.not_configured');
    }

    public static function rateLimited(): self
    {
        return new self('nl_query.rate_limited');
    }

    public static function transportFailure(string $reason): self
    {
        return new self('nl_query.transport_failure: '.$reason);
    }

    public static function noFunctionSelected(): self
    {
        return new self('nl_query.no_function_selected');
    }

    public static function maxRoundsExceeded(): self
    {
        return new self('nl_query.max_rounds_exceeded');
    }
}
