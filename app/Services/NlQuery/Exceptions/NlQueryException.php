<?php

namespace App\Services\NlQuery\Exceptions;

use RuntimeException;

class NlQueryException extends RuntimeException
{
    public static function notConfigured(): self
    {
        return new self('nl_query.not_configured');
    }

    /**
     * Google rejected the credential itself — the canonical body is
     * 400 INVALID_ARGUMENT with ErrorInfo reason API_KEY_INVALID (see
     * ai.google.dev/gemini-api/docs/generate-content/api-errors).
     * Distinct from transport noise: the fix is a new key, not a retry.
     */
    public static function invalidKey(string $detail): self
    {
        return new self('nl_query.invalid_key: '.$detail);
    }

    /**
     * 400 FAILED_PRECONDITION "User location is not supported for the
     * API use." — the key AUTHENTICATED fine; Google refuses the region
     * the request egresses from. Nothing is wrong with the credential.
     */
    public static function regionUnsupported(string $detail): self
    {
        return new self('nl_query.region_unsupported: '.$detail);
    }

    /** 404 NOT_FOUND — the configured GEMINI_MODEL does not exist for
     *  this account / API version. */
    public static function modelNotFound(string $detail): self
    {
        return new self('nl_query.model_not_found: '.$detail);
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
