{{--
    TASK-025 item 5 (spec §11/§12/§30) — the student self-service desk:
    own balance, own rank, own recent movements, the shared top board.
    Server-side authorized: $student comes from the authenticated
    account, never a URL parameter. Live balance: a tiny WS listener
    updates the balance stat when a points_awarded / reward_redeemed
    frame names this student (the token route serves students now).

    TASK-026 — mockup styling ("Student Hub" family): black+gold balance
    hero + rank card, activity rows, top-board list. Data contract
    unchanged; the WS script below is byte-identical in behavior.
--}}
@extends('layouts.app')

@section('title', __('app.student_dashboard'))

@section('content')
<div class="lede">
    <span class="kicker">{{ __('app.student_dashboard') }}</span>
    <h1>{{ $student->name }}</h1>
    <p class="lede-sub">{{ $student->schoolClass?->name ?? '—' }} · {{ __('app.student_hub_sub') }}</p>
</div>

<div class="stat-strip stat-strip-2" data-reveal-stagger>
    <x-stat :label="__('app.student_points_balance')" stat="balance">
        <x-slot:icon>
            <span class="material-symbols-outlined is-16" aria-hidden="true">eco</span>
        </x-slot:icon>
        {{ $balance }}
    </x-stat>
    <x-stat :label="__('app.student_rank')">
        <x-slot:icon>
            <span class="material-symbols-outlined is-16" aria-hidden="true">leaderboard</span>
        </x-slot:icon>
        {{ $rank ?? __('app.student_rank_none') }}
    </x-stat>
</div>

<div class="grid-2" data-reveal>
    <x-panel :label="__('app.student_recent_activity')" rule>
        <ul class="activity-list">
            @forelse($recentLedger as $row)
                <li class="activity-row">
                    <span class="live-main">
                        <span class="live-student">{{ __('app.student_ledger_reason_'.$row->reason) }}</span>
                        <span class="live-context mono">{{ $row->created_at?->format('Y-m-d H:i') }}</span>
                    </span>
                    <span class="live-side">
                        <span class="live-chip" data-event-type="{{ $row->delta >= 0 ? 'RECYCLING_DEPOSIT' : 'REDEMPTION' }}">
                            {{ $row->delta >= 0 ? '+' : '' }}{{ $row->delta }}
                        </span>
                    </span>
                </li>
            @empty
                <li class="activity-row live-empty">{{ __('app.student_no_activity') }}</li>
            @endforelse
        </ul>
        <p class="muted small"><a class="tiny-link" href="{{ route('student.history') }}">{{ __('app.student_history') }} →</a></p>
    </x-panel>

    <x-panel :label="__('app.student_leaderboard')">
        <ol class="board-list">
            @forelse($leaderboard as $entry)
                <li class="board-row {{ $entry['student_id'] === $student->id ? 'is-me' : '' }}">
                    <span class="board-rank">{{ $entry['rank'] }}</span>
                    <span class="board-name">{{ $entry['student_name'] }}</span>
                    <span class="board-points">{{ $entry['points'] }}</span>
                </li>
            @empty
                <li class="activity-row live-empty">{{ __('app.student_no_activity') }}</li>
            @endforelse
        </ol>
        <p class="muted small"><a class="tiny-link" href="{{ route('student.leaderboard') }}">{{ __('app.student_standings') }} →</a></p>
    </x-panel>
</div>

<p class="muted small"><a class="tiny-link" href="{{ route('student.rewards') }}">{{ __('app.student_rewards') }} →</a></p>

{{-- Live balance: WS points_awarded / reward_redeemed frames for THIS student --}}
<div id="student-live" data-student-id="{{ $student->id }}" hidden></div>
<script>
    (function () {
            var el = document.getElementById('student-live');
            if (!el || !window.fetch) { return; }
            fetch('{{ route('realtime.token') }}', { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (cfg) {
                    if (!cfg || !window.WebSocket) { return; }
                    var ws = new WebSocket(cfg.url + '?token=' + encodeURIComponent(cfg.token));
                    // TASK-029 — fixed latent shape bug: the server sends
                    // {type:'recycling', update:{payload}} — the old code
                    // read frame.payload (always undefined, so the balance
                    // NEVER went live). Also targets the balance stat by
                    // hook, not by "first .stat-value on the page".
                    var stat = document.querySelector('[data-stat="balance"] .stat-value');
                    ws.onmessage = function (e) {
                        try {
                            var frame = JSON.parse(e.data);
                            if (frame.type !== 'recycling' || !frame.update) { return; }
                            var p = frame.update.payload || {};
                            if (p.student_id !== Number(el.dataset.studentId)) { return; }
                            if (typeof p.new_balance === 'number' && stat) { stat.textContent = p.new_balance; }
                        } catch (err) { /* not JSON — ignore */ }
                    };
                })
                .catch(function () { /* live updates are optional polish */ });
        })();
</script>
@endsection
