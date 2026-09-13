{{--
    TASK-037 — the kitchen meal-service desk (ADR-054).

    Designed for staff working at speed: the page's primary surface is a
    huge fullscreen accept/reject state driven by realtime tap frames —
    green = meal served, red = rejected (with the reason, in the
    operator's language). No dense dashboard, no student detail, no
    photos (the platform has none): recognition over information density.

    Realtime: the same realtime.js client (hidden boot node — no
    #live-list here; this page owns its PAE-only list), the same
    `realtime:tap` CustomEvents, the same badge honesty. The recent list
    is SSR-first from RealtimeFeed::recent() filtered to PAE rows, then
    live-prepended idempotently (the three-arrival-paths rule).

    JS string literals ride the unescaped json_encode echo (TASK-014
    Blade lesson); the data-realtime attribute is the ESCAPED echo
    (attribute context — the browser entity-decodes before dataset).
--}}
@extends('layouts.app')

@section('title', __('app.kitchen_title'))

@section('content')
<div class="kitchen" data-kitchen>
    <header class="kitchen-head">
        <div>
            <h1 class="page-title">{{ __('app.kitchen_title') }}</h1>
            <p class="muted small">
                @if($activeMeal)
                    {{ __('app.kitchen_active_meal', ['meal' => __('api.meal_'.$activeMeal)]) }}
                    · {{ $mealWindows[$activeMeal]['start'] }}–{{ $mealWindows[$activeMeal]['end'] }}
                @else
                    {{ __('app.kitchen_no_active_meal') }}
                    · {{ __('api.meal_breakfast') }} {{ $mealWindows['breakfast']['start'] }}–{{ $mealWindows['breakfast']['end'] }}
                    · {{ __('api.meal_lunch') }} {{ $mealWindows['lunch']['start'] }}–{{ $mealWindows['lunch']['end'] }}
                @endif
            </p>
        </div>
        <span id="live-badge" class="live-badge" data-state="connecting" role="status">
            <span class="live-dot" aria-hidden="true"></span><span id="live-badge-text">{{ __('app.live_state_connecting') }}</span>
        </span>
    </header>

    {{-- The glanceable state: waiting → green (served) / red (rejected).
         Populated by the inline script below from realtime tap frames
         (and pre-painted server-side from the most recent PAE row so a
         reload never loses the last verdict). --}}
    <section id="kitchen-state" class="kitchen-state is-waiting" data-reasons="{{ json_encode([
        'weekend' => __('api.pae_weekend'),
        'out_of_window' => __('api.pae_out_of_window', [
            'breakfast' => $mealWindows['breakfast']['start'].'–'.$mealWindows['breakfast']['end'],
            'lunch' => $mealWindows['lunch']['start'].'–'.$mealWindows['lunch']['end'],
        ]),
        'window_overlap' => __('api.pae_window_overlap'),
        'no_student' => __('api.pae_no_student'),
    ]) }}" data-strings="{{ json_encode([
        'waiting' => __('app.kitchen_waiting'),
        'waiting_hint' => __('app.kitchen_waiting_hint'),
        'accepted' => __('app.kitchen_accepted'),
        'rejected' => __('app.kitchen_rejected'),
        'breakfast' => __('api.meal_breakfast'),
        'lunch' => __('api.meal_lunch'),
        'attempt' => __('app.event_type_PAE_ATTEMPT'),
    ]) }}" aria-live="polite">
        <span class="kitchen-glyph material-symbols-outlined" aria-hidden="true">restaurant</span>
        <span class="kitchen-verdict">{{ __('app.kitchen_waiting') }}</span>
        <span class="kitchen-student"></span>
        <span class="kitchen-detail">{{ __('app.kitchen_waiting_hint') }}</span>
        <span class="kitchen-time"></span>
    </section>

    {{-- Recent meal taps (SSR-first; the inline script live-prepends). --}}
    <section class="kitchen-recent">
        <h2 class="panel-label">{{ __('app.kitchen_recent') }}</h2>
        <ul class="live-list kitchen-list" id="kitchen-list">
            @forelse($recentEvents as $event)
                <li class="live-row" data-event-id="{{ $event['id'] }}">
                    <span class="avatar" aria-hidden="true">{{ strtoupper(mb_substr($event['student_name'], 0, 1) . mb_substr(explode(' ', $event['student_name'])[1] ?? '', 0, 1)) }}</span>
                    <span class="live-main">
                        <span class="live-student">{{ $event['student_name'] }}</span>
                        <span class="live-context">{{ $event['class_name'] ?? '—' }} · {{ $event['time'] }}</span>
                    </span>
                    <span class="live-side">
                        <span class="live-chip @if(! $event['served']) is-flagged @endif" data-event-type="{{ $event['type'] }}">
                            {{ __('app.event_type_'.$event['type']) }}
                            @if(! $event['served'])
                                · {{ __('api.pae_reason_'.$event['reason']) }}
                            @endif
                        </span>
                    </span>
                </li>
            @empty
                <li class="live-row live-empty">{{ __('app.live_waiting') }}</li>
            @endforelse
        </ul>
    </section>

    {{-- realtime.js boot node (hidden: this page owns its PAE-only list;
         no #live-list means the shared renderer stays out of the way). --}}
    <div hidden aria-hidden="true"
         data-realtime="{{ json_encode([
             'token' => $realtimeToken,
             'expires_at' => $realtimeTokenExpires,
             'port' => (int) config('realtime.port'),
             'max_rows' => 10,
             'strings' => [
                 'state_live' => __('app.live_state_live'),
                 'state_connecting' => __('app.live_state_connecting'),
                 'state_offline' => __('app.live_state_offline'),
             ],
         ]) }}"></div>
</div>

<script src="{{ asset('js/realtime.js') }}?v={{ @filemtime(public_path('js/realtime.js')) }}"></script>
<script>
(function () {
    'use strict';

    var state = document.getElementById('kitchen-state');
    var list = document.getElementById('kitchen-list');
    if (!state) { return; }

    var strings = {};
    var reasons = {};
    try { strings = JSON.parse(state.dataset.strings || '{}'); } catch (e) {}
    try { reasons = JSON.parse(state.dataset.reasons || '{}'); } catch (e) {}

    function mealLabel(type) {
        if (type === 'PAE_BREAKFAST') { return strings.breakfast || 'Breakfast'; }
        if (type === 'PAE_LUNCH') { return strings.lunch || 'Lunch'; }
        return strings.attempt || 'Meal attempt';
    }

    function show(ev) {
        var accepted = ev.served !== false;
        var studentName = String(ev.student_name || '');

        state.className = 'kitchen-state ' + (accepted ? 'is-accepted' : 'is-rejected');

        var glyph = state.querySelector('.kitchen-glyph');
        if (glyph) { glyph.textContent = accepted ? 'check_circle' : 'cancel'; }

        var verdictEl = state.querySelector('.kitchen-verdict');
        if (verdictEl) { verdictEl.textContent = (accepted ? strings.accepted : strings.rejected) + ' · ' + mealLabel(ev.type); }

        var studentEl = state.querySelector('.kitchen-student');
        if (studentEl) { studentEl.textContent = studentName; }

        var detailEl = state.querySelector('.kitchen-detail');
        if (detailEl) {
            if (accepted) {
                detailEl.textContent = ev.class_name || '';
            } else {
                var template = reasons[ev.reason] || ev.reason || '';
                detailEl.textContent = String(template)
                    .replace(':student', studentName)
                    .replace(':meal', mealLabel(ev.type));
            }
        }

        var timeEl = state.querySelector('.kitchen-time');
        if (timeEl) { timeEl.textContent = ev.time || ''; }
    }

    function prependRow(ev) {
        if (!list) { return; }

        if (list.querySelector('[data-event-id="' + ev.id + '"]')) { return; } // idempotent

        var li = document.createElement('li');
        li.className = 'live-row live-new';
        li.dataset.eventId = String(ev.id);

        var avatar = document.createElement('span');
        avatar.className = 'avatar';
        avatar.setAttribute('aria-hidden', 'true');
        var parts = String(ev.student_name || '').trim().split(/\s+/);
        avatar.textContent = ((parts[0] || '·').charAt(0) + (parts[1] ? parts[1].charAt(0) : '')).toUpperCase();

        var main = document.createElement('span');
        main.className = 'live-main';
        var student = document.createElement('span');
        student.className = 'live-student';
        student.textContent = ev.student_name || '';
        var context = document.createElement('span');
        context.className = 'live-context';
        context.textContent = (ev.class_name || '—') + ' · ' + (ev.time || '');
        main.appendChild(student);
        main.appendChild(context);

        var side = document.createElement('span');
        side.className = 'live-side';
        var chip = document.createElement('span');
        chip.className = 'live-chip' + (ev.served === false ? ' is-flagged' : '');
        chip.setAttribute('data-event-type', ev.type || '');
        chip.textContent = mealLabel(ev.type) + (ev.served === false
            ? ' · ' + String(reasons[ev.reason] || ev.reason || '').replace(':student', ev.student_name || '').replace(':meal', mealLabel(ev.type))
            : '');
        side.appendChild(chip);

        li.appendChild(avatar);
        li.appendChild(main);
        li.appendChild(side);

        var empty = list.querySelector('.live-empty');
        if (empty) { list.removeChild(empty); }
        list.insertBefore(li, list.firstChild);

        while (list.children.length > 10) { list.removeChild(list.lastChild); }
    }

    // Pre-paint the verdict from the most recent SSR PAE row (reload
    // honesty): recent() returns oldest-first, so the last row is newest.
    var rows = list ? list.querySelectorAll('.live-row[data-event-id]') : [];
    if (rows.length > 0) {
        var newest = rows[rows.length - 1];
        var chip = newest.querySelector('.live-chip');
        var flagged = chip && chip.classList.contains('is-flagged');
        show({
            type: chip ? chip.dataset.eventType : '',
            student_name: newest.querySelector('.live-student') ? newest.querySelector('.live-student').textContent : '',
            class_name: '',
            time: '',
            served: !flagged,
            reason: null
        });
        if (flagged) {
            var detailEl = state.querySelector('.kitchen-detail');
            var contextEl = newest.querySelector('.live-context');
            if (detailEl && contextEl) { detailEl.textContent = contextEl.textContent; }
        }
    }

    document.addEventListener('realtime:tap', function (e) {
        var ev = e.detail || {};
        if (String(ev.type || '').indexOf('PAE_') !== 0) { return; } // meal taps only
        show(ev);
        prependRow(ev);
    });
})();
</script>
@endsection
