@extends('layouts.app')

{{--
    TASK-026 — mockup "Parent View — Jerónimo's Timeline": scope banner,
    student profile card, telemetry bento (class / PAE / points), filter
    pills + search, the event ledger with chips and points badges, empty
    state, admin-only note. Every number is real (controller data).
    Mockup parts with no data source are omitted and documented in
    docs/FRONTEND.md: student photo + VERIFIED badge (gap #P2), PROTOCOL
    number (replaced by the real student id), milestone tier progress
    (gap #P3), campus geofence map + advisor contact card (gaps #P4/#P5),
    "Store & Milestones" filter (gap #P6 — redemptions are ledger rows,
    not presence events), crypto-sealed footers (gap #F6).
--}}

@section('title', __('app.parent_timeline', ['name' => $student->name]))

@section('content')
<div class="lede">
    <span class="kicker">{{ __('app.parent_timeline', ['name' => $student->name]) }}</span>
    <h1>{{ $student->name }}</h1>
</div>

{{-- 01 // Bilingual scope notice banner --}}
<section class="scope-banner" aria-label="{{ __('app.parent_timeline', ['name' => $student->name]) }}">
    <span class="scope-icon" aria-hidden="true"><span class="material-symbols-outlined is-20">info</span></span>
    <div class="min-w-0">
        <div class="scope-tags">
            <span class="scope-tag">{{ __('app.scope_tag') }}</span>
            <span class="scope-tag mono">{{ __('app.student_record') }} #{{ $student->id }}</span>
        </div>
        <p lang="en"><span class="lang-key">{{ __('app.scope_lang_en') }}</span>{{ __('app.scope_note_en') }}</p>
        <p lang="es"><span class="lang-key">{{ __('app.scope_lang_es') }}</span>{{ __('app.scope_note_es') }}</p>
    </div>
</section>

{{-- 02 // Student profile header — monogram (no photo data: gap #P2) --}}
<section class="profile-card" aria-label="{{ $student->name }}">
    <div class="profile-id">
        <div class="profile-avatar" aria-hidden="true">
            {{ strtoupper(mb_substr(explode(' ', trim($student->name))[0], 0, 1) . mb_substr(explode(' ', trim($student->name))[1] ?? '', 0, 1)) }}
        </div>
        <div class="min-w-0">
            <div class="profile-eyebrow">
                {{ __('app.student_record') }}
                <span class="sep">·</span>
                {{ __('app.student_id') }} #{{ $student->id }}
            </div>
            <h2 class="profile-name">{{ $student->name }}</h2>
            <div class="profile-meta">
                <span>{{ __('app.class') }}: <strong>{{ $student->schoolClass?->name ?? '—' }}</strong></span>
                <span class="sep">/</span>
                <span>{{ __('app.pae_enrolled') }}: <strong>{{ $student->pae_enrolled ? '✓' : '—' }}</strong></span>
            </div>
        </div>
    </div>
    {{-- Honest "live anchor": TASK-029 makes this view live (tap frames
         prepend events as they happen); the anchor keeps stating the SSR
         render moment — the truth about what the server painted. --}}
    <div class="live-anchor">
        <span class="dot" aria-hidden="true"></span>
        <span class="live-anchor-label">
            {{ __('app.record_generated') }}
            <span class="live-anchor-value mono">{{ now()->format('Y-m-d · H:i') }}</span>
        </span>
    </div>
</section>

{{-- 03 // Telemetry metrics ribbon (asymmetrical bento) --}}
<section class="bento" data-reveal-stagger aria-label="{{ __('app.parent_timeline', ['name' => $student->name]) }}">
    <div class="metric">
        <div class="metric-head">
            {{ __('app.class') }}
            <span class="material-symbols-outlined is-20" aria-hidden="true">school</span>
        </div>
        <div>
            <span class="metric-value">{{ $student->schoolClass?->name ?? '—' }}</span>
        </div>
        <div class="metric-foot"><span class="dot" aria-hidden="true"></span>{{ __('app.parent_metric_class_foot') }}</div>
    </div>
    <div class="metric">
        <div class="metric-head">
            {{ __('app.pae_enrolled') }}
            <span class="material-symbols-outlined is-20" aria-hidden="true">restaurant</span>
        </div>
        <div>
            @if($student->pae_enrolled)
                <span class="metric-value metric-value--inline">
                    <span class="material-symbols-outlined is-20" aria-hidden="true">check_circle</span>
                    {{ __('app.pae_enrolled_yes') }}
                </span>
            @else
                <span class="metric-value">{{ __('app.pae_enrolled_no') }}</span>
            @endif
        </div>
        <div class="metric-foot"><span class="dot" aria-hidden="true"></span>{{ __('app.parent_metric_pae_foot') }}</div>
    </div>
    <div class="metric-hero">
        <div class="metric-head">
            {{ __('app.student_points_balance') }}
            <span class="material-symbols-outlined is-20" aria-hidden="true">eco</span>
        </div>
        <div class="metric-value">{{ $points }}<span class="unit">{{ __('app.points_unit') }}</span></div>
        <p class="metric-sub">{{ __('app.parent_points_sub') }}</p>
    </div>
</section>

{{-- 04 + 05 // Interactive filters, search, and the read-only ledger --}}
<div class="filterbar">
    <div class="pills" role="group" aria-label="{{ __('app.filter_events') }}" data-filter-pills>
        <button type="button" class="pill is-active" data-filter="all" aria-pressed="true"
                data-all-template="{{ __('app.filter_all', ['n' => ':n']) }}">{{ __('app.filter_all', ['n' => count($timeline)]) }}</button>
        <button type="button" class="pill" data-filter="attendance" aria-pressed="false">{{ __('app.filter_attendance') }}</button>
        <button type="button" class="pill" data-filter="pae" aria-pressed="false">{{ __('app.filter_pae') }}</button>
        <button type="button" class="pill" data-filter="recycling" aria-pressed="false">{{ __('app.filter_recycling') }}</button>
    </div>
    <div class="searchbox">
        <span class="material-symbols-outlined is-18" aria-hidden="true">search</span>
        <input type="search" id="event-search" aria-label="{{ __('app.search_events') }}"
               placeholder="{{ __('app.search_events') }}" autocomplete="off">
    </div>
</div>

<div class="stack" data-reveal data-student-live="{{ $student->id }}"
     data-event-labels="{{ json_encode(collect(\App\Enums\EventType::cases())->mapWithKeys(fn ($t) => [$t->value => __('app.event_type_'.$t->value)])->all()) }}">
    <x-panel :label="__('app.event_type')" rule>
        @if(empty($timeline))
            <div class="empty">
                <span class="empty-icon"><span class="material-symbols-outlined is-24" aria-hidden="true">event_busy</span></span>
                <p>{{ __('app.no_events') }}</p>
                {{-- The "no match for the filters" line belongs to the
                     filtered variant only (rendered below when rows exist
                     but pills/search hide them all). --}}
            </div>
        @else
            <div class="empty" data-filter-empty hidden>
                <p>{{ __('app.no_events_filter') }}</p>
            </div>
            <div class="ledger-wrap">
                <table class="ledger-table" data-stack data-ledger>
                    <thead>
                    <tr>
                        <th scope="col">{{ __('app.event_type') }}</th>
                        <th scope="col">{{ __('app.tapped_at') }}</th>
                        <th scope="col">{{ __('app.reader_label') }}</th>
                        <th scope="col">{{ __('app.material') }}</th>
                        <th scope="col" class="ta-right">{{ __('app.points') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($timeline as $event)
                        <tr data-category="{{ str_starts_with($event['type'], 'CLASS_') || $event['type'] === 'ENTRY' ? 'attendance' : (str_starts_with($event['type'], 'PAE_') ? 'pae' : (str_starts_with($event['type'], 'RECYCLING_') ? 'recycling' : 'other')) }}"
                            data-search="{{ mb_strtolower($event['type'] . ' ' . ($event['reader'] ?? '') . ' ' . ($event['material'] ?? '')) }}">
                            <td data-label="{{ __('app.event_type') }}">
                                <span class="live-chip" data-event-type="{{ $event['type'] }}">{{ __('app.event_type_'.$event['type']) }}</span>
                            </td>
                            <td class="num" data-label="{{ __('app.tapped_at') }}">
                                {{ \Illuminate\Support\Carbon::parse($event['occurred_at'])->format('Y-m-d') }}
                                <span class="muted">{{ \Illuminate\Support\Carbon::parse($event['occurred_at'])->format('H:i') }}</span>
                            </td>
                            <td data-label="{{ __('app.reader_label') }}">{{ $event['reader'] ?? '—' }}</td>
                            <td data-label="{{ __('app.material') }}">{{ $event['material'] ?? '—' }}</td>
                            <td class="num ta-right" data-label="{{ __('app.points') }}">
                                @if($event['points'] !== null && $event['points'] > 0)
                                    <span class="points-badge">+{{ $event['points'] }} {{ __('app.points_unit') }}</span>
                                @else
                                    <span class="points-badge is-none">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-panel>

    {{-- Ledger note — TRUE for this view (staff-owned read-only stand-in) --}}
    <div class="table-note">
        <span class="material-symbols-outlined is-16" aria-hidden="true">shield</span>
        <span>{{ __('app.timeline_note_admin_only') }}</span>
    </div>
</div>

{{-- TASK-029 — the timeline is LIVE: tap frames (already role-scoped on
      the wire) prepend THIS student's events the moment they happen;
      the filter pills and the search stay owners of visibility. --}}
<div id="timeline-realtime" hidden data-realtime="{{ json_encode([
    'token' => $realtimeToken,
    'expires_at' => $realtimeTokenExpires,
    'port' => (int) config('realtime.port'),
    'max_rows' => (int) config('realtime.history_limit'),
]) }}"></div>
<script src="{{ asset('js/realtime.js') }}"></script>
{{-- Client-side filtering + search over the REAL server-rendered rows
     (mockup micro-interaction; no new backend needed). --}}
<script>
    (function () {
        var pills = document.querySelectorAll('[data-filter-pills] .pill');
        var search = document.getElementById('event-search');
        var rows = Array.prototype.slice.call(document.querySelectorAll('[data-ledger] tbody tr'));
        var emptyNote = document.querySelector('.empty');
        var filter = 'all';
        var query = '';

        function apply() {
            var visible = 0;
            rows.forEach(function (row) {
                var okCategory = filter === 'all' || row.dataset.category === filter;
                var okSearch = !query || (row.dataset.search || '').indexOf(query) !== -1;
                var show = okCategory && okSearch;
                row.hidden = !show;
                if (show) { visible += 1; }
            });
            if (emptyNote) {
                emptyNote.hidden = visible !== 0 || rows.length !== 0;
            }
            var counter = document.querySelector('[data-filter="all"]');
            if (counter && counter.dataset.allTemplate) {
                counter.textContent = counter.dataset.allTemplate.replace(':n', String(rows.length));
            }
            var emptyFiltered = document.querySelector('[data-filter-empty]');
            if (emptyFiltered) {
                emptyFiltered.hidden = !(rows.length > 0 && visible === 0);
            }
        }

        pills.forEach(function (pill) {
            pill.addEventListener('click', function () {
                pills.forEach(function (p) {
                    p.classList.remove('is-active');
                    p.setAttribute('aria-pressed', 'false');
                });
                pill.classList.add('is-active');
                pill.setAttribute('aria-pressed', 'true');
                filter = pill.dataset.filter;
                apply();
            });
        });

        if (search) {
            search.addEventListener('input', function () {
                query = search.value.trim().toLowerCase();
                apply();
            });
        }

        // The template lives in data-all-template (SSR); nothing to capture here.

        // TASK-029 — live prepend: a tap frame naming THIS student adds
        // its row to the ledger (built with the same DOM grammar the SSR
        // rows use; textContent everywhere — a label can never inject
        // markup). The pills and the search stay owners of visibility;
        // the all-pill count follows the row list.
        var liveStack = document.querySelector('.stack[data-student-live]');
        if (liveStack) {
            var liveStudentId = Number(liveStack.dataset.studentLive);
            var eventLabels = {};
            try { eventLabels = JSON.parse(liveStack.dataset.eventLabels || '{}'); } catch (err) { /* labels stay raw */ }

            // Mobile stacked tables label cells via data-label (CSS attr());
            // live rows reuse the SSR headers so the labels never drift.
            var headers = liveStack.querySelectorAll('[data-ledger] thead th');
            function label(i) { return headers[i] ? headers[i].textContent : ''; }

            document.addEventListener('realtime:tap', function (e) {
                var ev = e.detail || {};
                if (Number(ev.student_id) !== liveStudentId) { return; }

                var table = document.querySelector('[data-ledger]');
                if (!table) { return; } // empty-state page: reload renders it

                var type = String(ev.type || '');
                var category = type.indexOf('CLASS_') === 0 || type === 'ENTRY' ? 'attendance'
                    : (type.indexOf('PAE_') === 0 ? 'pae'
                    : (type.indexOf('RECYCLING_') === 0 ? 'recycling' : 'other'));

                if (table.tBodies[0].querySelector('tr[data-live-id="' + ev.id + '"]')) { return; } // idempotent

                var tr = document.createElement('tr');
                tr.setAttribute('data-category', category);
                tr.setAttribute('data-live-id', String(ev.id));
                tr.setAttribute('data-search', (type + ' ' + (ev.reader_label || '')).toLowerCase());
                tr.className = 'js-row-flash';

                var chipCell = document.createElement('td');
                chipCell.setAttribute('data-label', label(0));
                var chip = document.createElement('span');
                chip.className = 'live-chip';
                chip.setAttribute('data-event-type', type);
                chip.textContent = eventLabels[type] || type;
                chipCell.appendChild(chip);
                tr.appendChild(chipCell);

                var timeCell = document.createElement('td');
                timeCell.className = 'num';
                timeCell.setAttribute('data-label', label(1));
                timeCell.textContent = (ev.date || '') + ' ';
                var timeSpan = document.createElement('span');
                timeSpan.className = 'muted';
                timeSpan.textContent = ev.time || '';
                timeCell.appendChild(timeSpan);
                tr.appendChild(timeCell);

                var readerCell = document.createElement('td');
                readerCell.setAttribute('data-label', label(2));
                readerCell.textContent = ev.reader_label || '—';
                tr.appendChild(readerCell);

                var materialCell = document.createElement('td');
                materialCell.setAttribute('data-label', label(3));
                materialCell.textContent = '—';
                tr.appendChild(materialCell);

                var pointsCell = document.createElement('td');
                pointsCell.className = 'num ta-right';
                pointsCell.setAttribute('data-label', label(4));
                var badge = document.createElement('span');
                badge.className = 'points-badge is-none';
                badge.textContent = '—';
                pointsCell.appendChild(badge);
                tr.appendChild(pointsCell);

                table.tBodies[0].insertBefore(tr, table.tBodies[0].firstChild);
                rows.push(tr);
                apply();
            });
        }
    })();
</script>
@endsection
