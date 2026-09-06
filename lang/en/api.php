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

    // TASK-025 item 7 — redemption catalog rules (spec §19/§20)
    'reward_inactive' => 'This reward is currently inactive',
    'reward_out_of_stock' => 'This reward is out of stock',
    'duplicate_redemption' => 'This redemption was already received just now — no points charged again',

    // TASK-025 item 2 — bottle-first capture endpoints (spec §3/§32)
    'no_pending_capture' => 'No pending capture available for this reader',
    'capture_not_owned_by_reader' => 'Capture does not belong to this reader',
    'capture_already_associated' => 'Capture was already associated with a card',
    'event_expired' => 'The tap event is too old to classify',

    // Card pairing endpoint (TASK-010)
    'pairing_no_active_session' => 'No pairing session active',
    'pairing_card_already_paired' => 'Card already paired',

    // NL query endpoint
    'nlq_not_configured' => 'Natural-language query is not configured: no DEEPSEEK_API_KEY set (blocked, not failed).',
    'nlq_invalid_key' => 'DeepSeek rejected the configured DEEPSEEK_API_KEY (invalid or revoked). Create a fresh key at platform.deepseek.com, put it in .env, and verify with: ./run llm-check',
    'nlq_insufficient_balance' => 'The key is valid but the DeepSeek account balance is empty (pay-as-you-go, no free tier). Top up at platform.deepseek.com and verify with: ./run llm-check',
    'nlq_model_not_found' => 'The configured DEEPSEEK_MODEL was not found for this account or API version. Use the default (deepseek-v4-flash) and verify with: ./run llm-check',
    'nlq_rate_limited' => 'The language model quota is exhausted, please retry later.',
    'nlq_unavailable' => 'The language model service is unavailable, please retry later.',

    // Generic
    'forbidden_role' => 'You do not have permission to perform this action.',
    'not_found' => 'Resource not found',
];
