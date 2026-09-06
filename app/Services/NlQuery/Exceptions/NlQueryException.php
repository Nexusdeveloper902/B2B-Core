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
     * DeepSeek rejected the credential itself — 401 Authentication
     * Fails due to a wrong API key (see api-docs.deepseek.com,
     * "Error Codes"). Distinct from transport noise: the fix is a new
     * key, not a retry.
     */
    public static function invalidKey(string $detail): self
    {
        return new self('nl_query.invalid_key: '.$detail);
    }

    /**
     * 402 Insufficient Balance — the key authenticated fine, but the
     * DeepSeek account has no remaining balance (pay-as-you-go; there
     * is no free tier). The fix is topping up at platform.deepseek.com,
     * not a retry.
     */
    public static function insufficientBalance(string $detail): self
    {
        return new self('nl_query.insufficient_balance: '.$detail);
    }

    /** 404 / "Model Not Exist" — the configured DEEPSEEK_MODEL does not
     *  exist for this account / API version. */
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
