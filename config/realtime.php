<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Realtime dashboard feed (TASK-016, ADR-026)
    |--------------------------------------------------------------------------
    |
    | The hand-rolled WebSocket server (`php artisan realtime:serve`)
    | pushes new tap events to the dashboards. It polls the events
    | table — the event-type spine stays the single source of truth —
    | and broadcasts to authenticated dashboard clients. All values
    | are env-overridable for tests and exotic LAN setups.
    |
    */

    // Bind host. `./run serve` passes its own --host through so the
    // feed is reachable from exactly the interfaces the web server is.
    'host' => env('REALTIME_HOST', '0.0.0.0'),

    // Bind port. Must differ from the web port; serve.sh prints the
    // resulting ws:// URL. Override with B2B_REALTIME_PORT at the
    // script layer or REALTIME_PORT here.
    'port' => (int) env('REALTIME_PORT', 8081),

    // How often the server polls the events table for new taps (ms).
    // 300 ms feels instant at a school while costing ~3 lightweight
    // queries/second against SQLite.
    'poll_ms' => (int) env('REALTIME_POLL_MS', 300),

    // How many recent events the hello frame (and the server-rendered
    // feed panel) carries.
    'history_limit' => (int) env('REALTIME_HISTORY_LIMIT', 20),

    // Feed-token lifetime in seconds (re-minted on reconnect by the
    // session-authed /realtime/token endpoint).
    'token_ttl_seconds' => (int) env('REALTIME_TOKEN_TTL_SECONDS', 900),

];
