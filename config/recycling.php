<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Recycling — points & classification configuration
    |--------------------------------------------------------------------------
    |
    | Points are awarded per material class using this fixed table
    | (configurable via config, not hardcoded in controllers).
    |
    | The material classifier is resolved through the MaterialClassifier
    | contract. Three drivers ship today:
    |
    |   stub     — deterministic/pseudo-random stub (default; MVP contract only)
    |   local    — calls a LOCAL model-inference HTTP endpoint (the intended
    |              driver once the platform runs fully on local hardware; see
    |              docs/LOCAL_MODEL.md for the JSON contract)
    |   deepseek — optional cloud fallback using the DeepSeek vision model
    |              (deepseek-v4-flash-vision-exp — image-capable; ADR-030)
    |
    | Swapping drivers is a .env change only — no controller or route edits.
    | See ADR-003 and ADR-007 in .agent/DECISIONS/.
    |
    */

    'points' => [
        'plastic' => 10,
        'paper' => 5,
        'metal' => 15,
        'glass' => 8,
        'other' => 0,
    ],

    /*
     | TASK-025 item 2 — the bottle-first capture window (spec §5/§32):
     | how long an image captured WITHOUT a card stays resolvable before
     | the lazy sweep expires it (no award, no leak), and how long a
     | card-first tap event may wait for its classify call.
     */
    'capture' => [
        'ttl_seconds' => (int) env('RECYCLING_CAPTURE_TTL', 300),
        'classify_window_seconds' => (int) env('RECYCLING_CLASSIFY_WINDOW', 600),
    ],

    /*
     | TASK-025 item 4 — leaderboard shape (spec §22/§28): default top-N
     | served by GET /api/v1/recycling/leaderboard (client may request
     | any 1..100).
     */
    'leaderboard' => [
        'top' => (int) env('RECYCLING_LEADERBOARD_TOP', 10),
    ],

    /*
     | TASK-025 item 7 — redemption guards (spec §20): the duplicate
     | window for double-submit protection (same student + same reward
     | within N seconds is a repeat click, not a new wish).
     */
    'redemption' => [
        'duplicate_window_seconds' => (int) env('RECYCLING_REDEMPTION_DUPLICATE_WINDOW', 10),
    ],

    'classifier' => [
        'driver' => env('RECYCLING_CLASSIFIER_DRIVER', 'stub'),

        'local' => [
            'url' => env('LOCAL_CLASSIFIER_URL', 'http://127.0.0.1:8501/v1/models/material:predict'),
            'timeout' => (float) env('LOCAL_CLASSIFIER_TIMEOUT', 10),
        ],

        'deepseek' => [
            'api_key' => env('DEEPSEEK_API_KEY'),
            'model' => env('DEEPSEEK_VISION_MODEL', 'deepseek-v4-flash-vision-exp'),
            'timeout' => (float) env('DEEPSEEK_TIMEOUT', 15),
        ],
    ],

    /*
     | Natural-language query interface (Phase E).
     | Uses the DeepSeek API (OpenAI-compatible Chat Completions) with
     | tool-calling. Default model deepseek-v4-flash (the legacy
     | deepseek-chat name was discontinued 2026-07-24); the live call is
     | skipped entirely when no API key is configured (endpoint then
     | reports the blocker).
     */
    'nl_query' => [
        'api_key' => env('DEEPSEEK_API_KEY'),
        'model' => env('DEEPSEEK_MODEL', 'deepseek-v4-flash'),
        'timeout' => (float) env('DEEPSEEK_TIMEOUT', 20),
    ],
];
