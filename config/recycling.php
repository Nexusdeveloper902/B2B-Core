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
    |   stub   — deterministic/pseudo-random stub (default; MVP contract only)
    |   local  — calls a LOCAL model-inference HTTP endpoint (the intended
    |            driver once the platform runs fully on local hardware; see
    |            docs/LOCAL_MODEL.md for the JSON contract)
    |   gemini — optional cloud fallback using the Gemini API vision models
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

    'materials' => ['plastic', 'paper', 'metal', 'glass', 'other'],

    'classifier' => [
        'driver' => env('RECYCLING_CLASSIFIER_DRIVER', 'stub'),

        'local' => [
            'url' => env('LOCAL_CLASSIFIER_URL', 'http://127.0.0.1:8501/v1/models/material:predict'),
            'timeout' => (float) env('LOCAL_CLASSIFIER_TIMEOUT', 10),
        ],

        'gemini' => [
            'api_key' => env('GEMINI_API_KEY'),
            'model' => env('GEMINI_VISION_MODEL', 'gemini-2.5-flash'),
            'timeout' => (float) env('GEMINI_TIMEOUT', 15),
        ],
    ],

    /*
     | Natural-language query interface (Phase E).
     | Uses the Gemini API with function-calling. Free-tier friendly:
     | gemini-*-flash models only, and the live call is skipped entirely
     | when no API key is configured (endpoint then reports the blocker).
     */
    'nl_query' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
        'timeout' => (float) env('GEMINI_TIMEOUT', 20),
    ],
];
