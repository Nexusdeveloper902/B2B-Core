{{--
    TASK-016 — the live activity feed panel (admin + teacher dashboards).
    TASK-017 — Calm Ledger polish: initial avatars + event chips.

    SSR-first (the project convention): rows are server-rendered from
    the same shape the hello frame sends, so the panel is useful with
    the realtime server DOWN. realtime.js then takes over: badge
    honesty (live/connecting/offline), live prepend with slide-in +
    "just now" relative time, `realtime:tap` page hooks.

    The event chip carries data-event-type="RAW_TYPE" — the tone
    mapping lives ONLY in CSS attribute selectors (app.css), shared
    by these SSR rows and realtime.js's rowFor().
    Live-arrived rows get their relative time client-side (only the
    arrival moment is knowable client-side — history stays absolute).

    Receives: $recentEvents (array from RealtimeFeed::recent()),
    $realtimeToken, $realtimeTokenExpires.
    JS string literals ride the unescaped json_encode echo (TASK-014
    Blade lesson — escaped echo kills scripts); the bootstrap on
    #live-list[data-realtime] is an HTML-ATTRIBUTE context, so there
    the ESCAPED echo is the correct one (the browser entity-decodes
    the attribute before dataset reads it).
--}}
<x-panel :label="__('app.live_activity')" rule class="live-panel">
    <div class="live-head">
        <span class="live-panel-sub muted small">{{ __('app.live_panel_sub') }}</span>
        <span id="live-badge" class="live-badge" data-state="connecting" role="status">
            <span class="live-dot" aria-hidden="true"></span><span id="live-badge-text">{{ __('app.live_state_connecting') }}</span>
        </span>
    </div>

    <ul class="live-list" id="live-list"
        data-realtime="{{ json_encode([
            'token' => $realtimeToken,
            'expires_at' => $realtimeTokenExpires,
            'port' => (int) config('realtime.port'),
            'max_rows' => (int) config('realtime.history_limit'),
            'strings' => [
                'state_live' => __('app.live_state_live'),
                'state_connecting' => __('app.live_state_connecting'),
                'state_offline' => __('app.live_state_offline'),
                'rel_now' => __('app.live_rel_now'),
                'rel_min' => __('app.live_rel_min'),
                // TASK-027 — i18n: live-arriving chips render the localized
                // label (SSR rows use the same keys below).
                'event_types' => collect(\App\Enums\EventType::cases())
                    ->mapWithKeys(fn ($type) => [$type->value => __('app.event_type_'.$type->value)])
                    ->all(),
            ],
        ]) }}">
        @forelse($recentEvents as $event)
            @php($initials = strtoupper(mb_substr($event['student_name'], 0, 1) . mb_substr(explode(' ', $event['student_name'])[1] ?? '', 0, 1)))
            <li class="live-row" data-event-id="{{ $event['id'] }}">
                <span class="avatar" aria-hidden="true">{{ $initials }}</span>
                <span class="live-main">
                    <span class="live-student">{{ $event['student_name'] }}</span>
                    <span class="live-context">{{ $event['class_name'] ?? '—' }} · {{ $event['reader_label'] ?? '—' }}</span>
                </span>
                <span class="live-side">
                    <span class="live-chip" data-event-type="{{ $event['type'] }}">{{ __('app.event_type_'.$event['type']) }}</span>
                    <span class="live-time">{{ $event['time'] }}</span>
                </span>
            </li>
        @empty
            <li class="live-row live-empty" id="live-empty">{{ __('app.live_waiting') }}</li>
        @endforelse
    </ul>

    <p class="live-hint hidden" id="live-hint">{{ __('app.live_offline_hint') }}</p>
</x-panel>
