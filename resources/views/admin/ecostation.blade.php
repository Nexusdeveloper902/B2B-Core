{{--
    TASK-026 — mockup "EcoStation & Recycling Hub": impact metrics row,
    the deposit ledger (event-spine truth: when / student / reader /
    material / confidence / points), the material rate card (config
    truth), and the recycling reader network. Everything is real data.

    TASK-027 — the hub is LIVE and shows the capture: recycling frames
    (realtime.js, realtime:recycling CustomEvents) prepend ledger rows,
    bump the impact metrics and refresh the latest-capture panel with
    no reload; the latest capture image streams through the admin-
    authed route (gap #E1 closed — the private disk stays private).

    Still omitted (docs/FRONTEND.md): terminal telemetry pill, hardware
    firmware status snippet, compliance note box, interactive terminal
    simulation modal, cryptographic ledger footer (gaps #E2/#E3).
--}}
@extends('layouts.app')
@use('Illuminate\Support\Js', 'Js')

@section('title', __('app.ecostation'))

@section('content')
<div class="lede">
    <span class="kicker">{{ __('app.ecostation') }}</span>
    <h1>{{ __('app.ecostation') }}</h1>
    <p class="lede-sub">{{ __('app.ecostation_sub') }}</p>
    <span id="live-badge" class="live-badge" data-state="connecting" role="status">
        <span class="live-dot" aria-hidden="true"></span><span id="live-badge-text">{{ __('app.live_state_connecting') }}</span>
    </span>
</div>

{{-- TASK-027 — realtime boot node (no feed list here; the page script
      consumes realtime:recycling CustomEvents; the badge above shows
      the connection state with the same honesty grammar). --}}
<div id="ecostation-realtime" class="hidden"
     data-realtime="{{ json_encode([
         'token' => $realtimeToken,
         'expires_at' => $realtimeTokenExpires,
         'port' => (int) config('realtime.port'),
         'max_rows' => 15,
         'strings' => [
             'state_live' => __('app.live_state_live'),
             'state_connecting' => __('app.live_state_connecting'),
             'state_offline' => __('app.live_state_offline'),
             'event_types' => collect(\App\Enums\EventType::cases())
                 ->mapWithKeys(fn ($type) => [$type->value => __('app.event_type_'.$type->value)])
                 ->all(),
         ],
     ]) }}"></div>

{{-- Impact metrics tonal row (all-time, ledger truth — bumped live by
     the page script when points are awarded). --}}
<section class="bento" data-reveal-stagger aria-label="{{ __('app.ecostation_metrics') }}">
    <div class="metric">
        <div class="metric-head">
            {{ __('app.recycling_items') }}
            <span class="material-symbols-outlined is-20" aria-hidden="true">recycling</span>
        </div>
        <div><span class="metric-value" id="metric-items">{{ $totalItems }}</span></div>
        <div class="metric-foot"><span class="dot" aria-hidden="true"></span>{{ __('app.ecostation_items_foot') }}</div>
    </div>
    <div class="metric">
        <div class="metric-head">
            {{ __('app.ecostation_materials') }}
            <span class="material-symbols-outlined is-20" aria-hidden="true">category</span>
        </div>
        <div><span class="metric-value">{{ $byMaterial->count() }}</span></div>
        <div class="metric-foot"><span class="dot" aria-hidden="true"></span>{{ __('app.ecostation_materials_foot') }}</div>
    </div>
    <div class="metric-hero">
        <div class="metric-head">
            {{ __('app.recycling_points') }}
            <span class="material-symbols-outlined is-20" aria-hidden="true">eco</span>
        </div>
        <div class="metric-value"><span id="metric-points">{{ $totalPoints }}</span><span class="unit">{{ __('app.points_unit') }}</span></div>
        <p class="metric-sub">{{ __('app.ecostation_points_sub') }}</p>
    </div>
</section>

<div class="grid-2 grid-2-wide-left" data-reveal>
    {{-- Hardware transaction ledger (~60%) — rows prepend live. --}}
    <x-panel :label="__('app.ecostation_ledger')" rule>
        <div class="ledger-wrap">
            <table class="ledger-table" data-stack id="deposit-ledger">
                <thead>
                <tr>
                    <th scope="col">{{ __('app.tapped_at') }}</th>
                    <th scope="col">{{ __('app.student') }}</th>
                    <th scope="col">{{ __('app.reader_label') }}</th>
                    <th scope="col">{{ __('app.material') }}</th>
                    <th scope="col">{{ __('app.ecostation_confidence') }}</th>
                    <th scope="col" class="ta-right">{{ __('app.points') }}</th>
                </tr>
                </thead>
                <tbody id="deposit-ledger-body">
                @forelse($recentDeposits as $deposit)
                    <tr data-event-id="{{ $deposit->event_id }}">
                        <td class="num mono" data-label="{{ __('app.tapped_at') }}">{{ $deposit->event?->occurred_at?->format('Y-m-d H:i') ?? '—' }}</td>
                        <td data-label="{{ __('app.student') }}">{{ $deposit->event?->student?->name ?? '—' }}</td>
                        <td data-label="{{ __('app.reader_label') }}">{{ $deposit->event?->reader?->label ?? '—' }}</td>
                        <td data-label="{{ __('app.material') }}">
                            <span class="live-chip" data-event-type="RECYCLING_DEPOSIT">{{ __('app.material_'.$deposit->material_class?->value) }}</span>
                        </td>
                        <td class="num mono" data-label="{{ __('app.ecostation_confidence') }}">{{ $deposit->confidence !== null ? round(100 * $deposit->confidence) . '%' : '—' }}</td>
                        <td class="num ta-right" data-label="{{ __('app.points') }}">
                            <span class="points-badge">+{{ $deposit->points_awarded }} {{ __('app.points_unit') }}</span>
                        </td>
                    </tr>
                @empty
                    <tr id="deposit-ledger-empty"><td colspan="6" class="muted">{{ __('app.ecostation_no_deposits') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="table-note table-note--inset">
            <span class="material-symbols-outlined is-16" aria-hidden="true">shield</span>
            <span>{{ __('app.ecostation_ledger_note') }}</span>
        </div>
    </x-panel>

    {{-- Hardware network + material rules (~40%) --}}
    <div class="stack">
        <x-panel :label="__('app.ecostation_readers')">
            <ul class="ruled">
                @forelse($readers as $reader)
                    <li>
                        <span class="rate-name">
                            <span class="dot" aria-hidden="true"></span>{{ $reader->label }}
                        </span>
                        <span class="meta mono">{{ $reader->active_event_type }}</span>
                    </li>
                @empty
                    <li class="muted">{{ __('app.ecostation_no_readers') }}</li>
                @endforelse
            </ul>
        </x-panel>

        <x-panel :label="__('app.ecostation_rates')">
            <ul class="rates-list">
                @foreach($rates as $material => $points)
                    <li>
                        <span class="rate-name"><span class="dot" aria-hidden="true"></span>{{ __('app.material_'.$material) }}</span>
                        <span class="rate-value {{ $points === 0 ? 'is-zero' : '' }}">+{{ $points }} {{ __('app.points_unit') }}</span>
                    </li>
                @endforeach
            </ul>
            <div class="table-note table-note--inset">
                <span class="material-symbols-outlined is-16" aria-hidden="true">tune</span>
                <span>{{ __('app.ecostation_rates_note') }}</span>
            </div>
        </x-panel>

        {{-- Sensor audit spotlight — latest classified capture. TASK-027
             (gap #E1 closed): the REAL image streams through the admin-
             authed route (session-cookie same-origin fetch); a deposit
             without a stored image keeps the honest private-storage note. --}}
        <x-panel :label="__('app.ecostation_capture')">
            @if($latestCapture)
                <div class="capture-shot" id="latest-capture" data-deposit="{{ $latestCapture->id }}">
                    @if($latestCapture->image_path)
                        <img id="capture-image" src="{{ route('api.v1.captures.image', $latestCapture) }}"
                             alt="{{ __('app.ecostation_capture') }}" loading="lazy">
                    @else
                        <div class="capture-none" aria-hidden="true">
                            <span class="material-symbols-outlined is-24">photo_camera</span>
                            <span>{{ __('app.ecostation_capture_private') }}</span>
                        </div>
                    @endif
                    <div class="capture-caption">
                        <span id="capture-caption-material">{{ __('app.material_'.$latestCapture->material_class?->value) }} · {{ round(100 * (float) $latestCapture->confidence) }}%</span>
                        <span id="capture-caption-time">{{ $latestCapture->event?->occurred_at?->format('Y-m-d H:i') }}</span>
                    </div>
                </div>
                <p class="muted small capture-meta" id="capture-meta">
                    {{ __('app.ecostation_capture_meta', [
                        'student' => $latestCapture->event?->student?->name ?? '—',
                        'bottle' => $latestCapture->is_bottle ? __('app.yes') : __('app.no'),
                        'recyclable' => $latestCapture->is_recyclable ? __('app.yes') : __('app.no'),
                    ]) }}
                </p>
            @else
                <div class="empty" id="capture-empty">
                    <span class="empty-icon"><span class="material-symbols-outlined is-24" aria-hidden="true">photo_camera</span></span>
                    <p>{{ __('app.ecostation_capture_none') }}</p>
                </div>
            @endif
        </x-panel>
    </div>
</div>

<script src="{{ asset('js/realtime.js') }}"></script>
<script>
    (function () {
        'use strict';

        // TASK-030 (Fix 3) — server strings ride JSON-encoded vars
        // only (the TASK-014 Blade lesson: translators' quotes must
        // never meet a JS literal).
        var labels = {
            material: {!! Js::from(collect(\App\Enums\MaterialClass::cases())->mapWithKeys(fn ($m) => [$m->value => __('app.material_'.$m->value)])->all()) !!},
            yes: {!! Js::from(__('app.yes')) !!},
            no: {!! Js::from(__('app.no')) !!},
            meta: {!! Js::from(__('app.ecostation_capture_meta')) !!},
            pointsUnit: {!! Js::from(__('app.points_unit')) !!},
            captureAlt: {!! Js::from(__('app.ecostation_capture')) !!},
            dash: '—'
        };

        // TASK-027 — live recycling updates, no reload: 'validated' frames
        // prepend the deposit row + refresh the latest-capture panel;
        // 'points_awarded' frames fill the points badge and bump metrics.
        document.addEventListener('realtime:recycling', function (e) {
            var update = e.detail || {};
            var payload = update.payload || {};

            if (update.type === 'validated') { onValidated(payload, update.at); }
            if (update.type === 'points_awarded') { onPointsAwarded(payload); }
        });

        function onValidated(payload, at) {
            // Ledger row (only if not already rendered — SSR or replay).
            var body = document.getElementById('deposit-ledger-body');
            if (body && payload.event_id) {
                var existing = body.querySelector('tr[data-event-id="' + payload.event_id + '"]');
                if (!existing) {
                    var emptyRow = document.getElementById('deposit-ledger-empty');
                    if (emptyRow) { emptyRow.remove(); }
                    var tr = document.createElement('tr');
                    tr.dataset.eventId = String(payload.event_id);
                    tr.setAttribute('data-event-id', String(payload.event_id));
                    tr.className = 'js-row-flash';
                    tr.innerHTML =
                        '<td class="num mono">' + esc(payload.occurred_at || timeOf(at)) + '</td>' +
                        '<td>' + esc(payload.student_name || '—') + '</td>' +
                        '<td>' + esc(payload.reader_label || '—') + '</td>' +
                        '<td><span class="live-chip" data-event-type="RECYCLING_DEPOSIT">' +
                            esc(labels.material[payload.material_class] || payload.material_class || '—') + '</span></td>' +
                        '<td class="num mono">' + (payload.confidence != null
                            ? Math.round(100 * payload.confidence) + '%' : '—') + '</td>' +
                        '<td class="num ta-right"><span class="points-badge" id="points-' +
                            esc(String(payload.event_id)) + '">+… ' + labels.pointsUnit + '</span></td>';
                    body.insertBefore(tr, body.firstChild);
                    while (body.children.length > 15) { body.removeChild(body.lastChild); }
                }
            }

            // Latest capture panel: real image through the authorized route.
            if (payload.deposit_id) {
                var panel = document.getElementById('latest-capture');
                if (panel) {
                    panel.dataset.deposit = String(payload.deposit_id);
                    var img = document.getElementById('capture-image');
                    if (!img) {
                        img = document.createElement('img');
                        img.id = 'capture-image';
                        img.alt = labels.captureAlt;
                        img.loading = 'lazy';
                        var slot = panel.querySelector('.capture-none');
                        if (slot) { slot.remove(); }
                        panel.insertBefore(img, panel.firstChild);
                    }
                    img.src = '/api/v1/admin/captures/' + payload.deposit_id + '/image';
                    var materialEl = document.getElementById('capture-caption-material');
                    if (materialEl) {
                        materialEl.textContent = (labels.material[payload.material_class] ||
                            payload.material_class || '—') + ' · ' +
                            (payload.confidence != null ? Math.round(100 * payload.confidence) + '%' : '—');
                    }
                    var timeEl = document.getElementById('capture-caption-time');
                    if (timeEl) { timeEl.textContent = payload.occurred_at || timeOf(at); }
                }
            }
        }

        function onPointsAwarded(payload) {
            if (payload.event_id) {
                var badge = document.getElementById('points-' + payload.event_id);
                if (badge) { badge.textContent = '+' + payload.points + ' ' + labels.pointsUnit; }
            }
            bump('metric-items', 1);
            bump('metric-points', payload.points || 0);
        }

        function bump(id, delta) {
            var el = document.getElementById(id);
            if (!el) { return; }
            var current = parseInt(el.textContent.replace(/[^0-9-]/g, ''), 10);
            if (!isNaN(current)) { el.textContent = String(current + delta); }
        }

        function timeOf(at) {
            // The recycling update's `at` is the committed created_at
            // datetime string ("Y-m-d H:i:s" — naive school-local time).
            var raw = String(at || '');
            if (!raw) { return '—'; }
            if (/^\d+$/.test(raw)) { return new Date(raw * 1000).toISOString().replace('T', ' ').substring(0, 16); }
            return raw.length > 16 ? raw.substring(0, 16) : raw;
        }

        function esc(value) {
            return String(value == null ? '' : value)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }
    })();
</script>
@endsection
