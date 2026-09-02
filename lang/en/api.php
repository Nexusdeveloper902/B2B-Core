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

    // NL query endpoint
    'nlq_not_configured' => 'Natural-language query is not configured: no GEMINI_API_KEY set (blocked, not failed).',
    'nlq_rate_limited' => 'The language model quota is exhausted, please retry later.',
    'nlq_unavailable' => 'The language model service is unavailable, please retry later.',

    // Generic
    'forbidden_role' => 'You do not have permission to perform this action.',
    'not_found' => 'Resource not found',
];
