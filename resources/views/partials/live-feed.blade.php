{{--
    TASK-016 — the live activity feed panel (admin + teacher dashboards).

    SSR-first (the project convention): rows are server-rendered from
    the same RealtimeFeed::recent() query the hello frame sends, so the
    panel is useful with the realtime server DOWN. realtime.js then
    takes over: badge honesty (live/connecting/offline), live prepend,
    `realtime:tap` page hooks.

    Receives: $recentEvents (array from RealtimeFeed::recent()),
    $realtimeToken, $realtimeTokenExpires.
    JS string literals ride the unescaped json_encode echo (TASK-014
    Blade lesson — escaped echo kills scripts).
--}}
<x-panel :label="__('app.live_activity')" rule class="live-panel">
    <div class="live-head">
        <span class="live-panel-sub muted small">{{ __('app.live_panel_sub') }}</span>
        <span id="live-badge" class="live-badge" data-state="connecting" role="status">
            <span class="live-dot" aria-hidden="true"></span><span id="live-badge-text">{{ __('app.live_state_connecting') }}</span>
        </span>
    </div>

    <ul class="live-list" id="live-list"
        {{-- HTML-ATTRIBUTE context: the ESCAPED echo is the correct one here
             (the browser entity-decodes the attribute before dataset reads
             it — TASK-014's unescaped rule applies to <script> literals,
             where raw quotes would terminate the attribute instead). --}}
        data-realtime="{{ json_encode([
            'token' => $realtimeToken,
            'expires_at' => $realtimeTokenExpires,
            'port' => (int) config('realtime.port'),
            'max_rows' => (int) config('realtime.history_limit'),
            'strings' => [
                'state_live' => __('app.live_state_live'),
                'state_connecting' => __('app.live_state_connecting'),
                'state_offline' => __('app.live_state_offline'),
            ],
        ]) }}">
        @forelse($recentEvents as $event)
            <li class="live-row" data-event-id="{{ $event['id'] }}">
                <span class="live-time">{{ $event['time'] }}</span>
                <span class="live-student">{{ $event['student_name'] }}</span>
                <span class="live-context">{{ $event['class_name'] ?? '—' }} · {{ $event['reader_label'] ?? '—' }} · <code>{{ $event['type'] }}</code></span>
            </li>
        @empty
            <li class="live-row live-empty" id="live-empty">{{ __('app.live_waiting') }}</li>
        @endforelse
    </ul>

    <p class="live-hint hidden" id="live-hint">{{ __('app.live_offline_hint') }}</p>
</x-panel>
