<?php

/*
|--------------------------------------------------------------------------
| API Language Lines (English) — device-facing + endpoint messages
|--------------------------------------------------------------------------
*/

return [
    // Reader authentication
    'missing_bearer_token' => 'Missing bearer token',
    'invalid_bearer_token' => 'Invalid bearer token',

    // Tap endpoint
    'card_not_recognized' => 'Card not recognized',
    'card_not_active' => 'Card is not active',

    // Classification endpoint
    'event_not_owned_by_reader' => 'Event does not belong to this reader',
    'event_not_recycling' => 'Event is not a recycling deposit event',
    'classifier_unavailable' => 'Material classifier is unavailable, please retry later',

    // Redemption endpoint
    'insufficient_points' => 'Insufficient points: :shortfall more needed',

    // Card pairing endpoint (TASK-010)
    'pairing_no_active_session' => 'No pairing session active',
    'pairing_card_already_paired' => 'Card already paired',

    // NL query endpoint
    'nlq_not_configured' => 'Natural-language query is not configured: no GEMINI_API_KEY set (blocked, not failed).',
    'nlq_invalid_key' => 'Google rejected the configured GEMINI_API_KEY (invalid or revoked). Create a fresh key in Google AI Studio, put it in .env, and verify with: ./run llm-check',
    'nlq_region_unsupported' => 'Google refuses Gemini API calls from this network or region ("User location is not supported"). The key itself is valid — run ./run llm-check from this machine and see the Gemini API "Available regions" page.',
    'nlq_model_not_found' => 'The configured GEMINI_MODEL was not found for this account or API version. Use the default (gemini-3.1-flash-lite) and verify with: ./run llm-check',
    'nlq_rate_limited' => 'The language model quota is exhausted, please retry later.',
    'nlq_unavailable' => 'The language model service is unavailable, please retry later.',

    // Generic
    'forbidden_role' => 'You do not have permission to perform this action.',
    'not_found' => 'Resource not found',
];
