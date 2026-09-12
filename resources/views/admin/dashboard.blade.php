{{--
    TASK-026 — mockup "Admin Dashboard — School Today": 5-KPI hero strip
    (attendance black card + PAE + recycling), NL query panel,
    redemption module, student directory, shared live feed. Every
    id/class/form contract is unchanged (nl-query-form, redeem-form…).
    Mockup parts with no data source are omitted and documented
    (docs/FRONTEND.md): node/telemetry strip, "Force Telemetry Poll"
    and "Global Thresholds" buttons, campus operations summary card
    (gaps #A1-A3).

    TASK-035 — the readers hardware table is gone from this page (the
    /admin/readers desk is the readers surface now); reader mode
    changes happen there, not here.
--}}
@extends('layouts.app')

@section('title', __('app.admin_dashboard'))

@section('content')
<div class="lede">
    <span class="kicker">{{ __('app.school_today') }}</span>
    <h1>{{ __('app.admin_dashboard') }}</h1>
    <p class="lede-sub">{{ __('app.admin_dashboard_sub') }}</p>
</div>

{{-- School-wide stats today: hero attendance tile + secondary KPI tiles.
     The hero (most important number) sits first — dashboard best practice. --}}
<section class="stat-strip" data-reveal-stagger aria-label="{{ __('app.school_today') }}">
    <x-stat :label="__('app.attendance_count')" stat="attendance">
        <x-slot:icon>
            <span class="material-symbols-outlined is-16" aria-hidden="true">how_to_reg</span>
        </x-slot:icon>
        {{ $attendanceToday }}
    </x-stat>
    <x-stat :label="__('app.pae_breakfast')" stat="pae_breakfast">
        <x-slot:icon>
            <span class="material-symbols-outlined is-16" aria-hidden="true">bakery_dining</span>
        </x-slot:icon>
        {{ $paeBreakfastToday }}
    </x-stat>
    <x-stat :label="__('app.pae_lunch')" stat="pae_lunch">
        <x-slot:icon>
            <span class="material-symbols-outlined is-16" aria-hidden="true">lunch_dining</span>
        </x-slot:icon>
        {{ $paeLunchToday }}
    </x-stat>
    <x-stat :label="__('app.recycling_items')" stat="recycling_items">
        <x-slot:icon>
            <span class="material-symbols-outlined is-16" aria-hidden="true">recycling</span>
        </x-slot:icon>
        {{ $recyclingToday['items'] }}
    </x-stat>
    <x-stat :label="__('app.recycling_points')" stat="recycling_points">
        <x-slot:icon>
            <span class="material-symbols-outlined is-16" aria-hidden="true">toll</span>
        </x-slot:icon>
        {{ $recyclingToday['points'] }}
    </x-stat>
</section>

{{-- TASK-016 — live activity: server-rendered, then WebSocket-live --}}
@include('partials.live-feed')

{{-- NL query box (full width since TASK-035 removed the readers panel) --}}
<section class="section-gap" data-reveal>
    <x-panel :label="__('app.nl_query')" rule>
        @unless($nlQueryConfigured)
            <div class="notice notice-warn" role="alert">{{ __('app.nl_query_not_configured') }}</div>
        @endunless

        <form id="nl-query-form" class="tool-form">
            <input type="text" class="bare-input" id="nl-question"
                   placeholder="{{ __('app.nl_query_placeholder') }}" autocomplete="off"
                   aria-label="{{ __('app.nl_query') }}">
            <button type="submit" class="btn btn-primary">{{ __('app.ask') }}</button>
        </form>

        <div id="nl-answer" class="nl-answer hidden" aria-live="polite"></div>
    </x-panel>
</section>

<section class="grid-2" data-reveal>
    {{-- Redemption desk --}}
    <x-panel :label="__('app.redemption')" rule>
        <form id="redeem-form" class="tool-form">
            <select id="redeem-student" class="bare-select" required aria-label="{{ __('app.student') }}">
                @foreach($students as $student)
                    <option value="{{ $student->id }}">{{ $student->name }}</option>
                @endforeach
            </select>

            <select id="redeem-reward" class="bare-select" required aria-label="{{ __('app.reward') }}">
                @foreach($rewards as $reward)
                    <option value="{{ $reward->id }}">{{ $reward->name }} ({{ $reward->point_cost }} {{ __('app.points_unit') }})</option>
                @endforeach
            </select>

            <button type="submit" class="btn btn-primary">{{ __('app.redeem') }}</button>
        </form>
        <div id="redeem-result" class="nl-answer hidden" aria-live="polite"></div>
    </x-panel>

    {{-- Students quick links (parent view entry) --}}
    <x-panel :label="__('app.students')">
        @if($students->isEmpty())
            <x-empty>{{ __('app.no_students') }}</x-empty>
        @else
            <ul class="ruled">
                @foreach($students as $student)
                    <li>
                        <a href="{{ route('parent.timeline', $student) }}">{{ $student->name }}</a>
                        <span class="meta">{{ $student->schoolClass?->name }} · {{ $student->pae_enrolled ? __('app.pae_enrolled_yes') : __('app.pae_enrolled_no') }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-panel>
</section>

<script src="{{ asset('js/realtime.js') }}"></script>
<script src="{{ asset('js/markdown.js') }}"></script>
<script>
    (function () {
        var csrf = document.querySelector('meta[name="csrf-token"]').content;

        // TASK-017 — honest loading states: every async action shows a
        // spinner on its own button for exactly the duration of the fetch.
        function busy(btn, on) {
            btn.disabled = on;
            btn.classList.toggle('is-loading', on);
        }

        function postJson(url, body) {
            return fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf
                },
                body: JSON.stringify(body)
            }).then(function (r) {
                return r.json().then(function (data) {
                    return {ok: r.ok, status: r.status, data: data};
                });
            });
        }

        // TASK-027 — NL answers render light Markdown. The answer is
        // model-generated text and NEVER trusted HTML: markdown.js
        // escapes everything first and only then emits its own tiny
        // elements (no links, no images, no raw HTML). Our own error
        // strings stay plain text.
        function show(el, text, ok) {
            el.classList.remove('hidden');
            el.className = 'nl-answer ' + (ok ? 'answer-ok' : 'answer-error');
            if (ok && window.renderMarkdown) {
                el.innerHTML = window.renderMarkdown(text);
            } else {
                el.textContent = text;
            }
        }

        // NL query box (Phase E). TASK-027 — the pending state says what
        // is happening; the answer renders Markdown.
        var nlForm = document.getElementById('nl-query-form');
        nlForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var question = document.getElementById('nl-question').value.trim();
            if (!question) return;
            var btn = nlForm.querySelector('button');
            var box = document.getElementById('nl-answer');
            busy(btn, true);
            box.classList.remove('hidden');
            box.className = 'nl-answer';
            box.textContent = '{{ __('app.nl_query_processing') }}';
            postJson('/api/v1/nl-query', {question: question}).then(function (r) {
                busy(btn, false);
                show(box, r.data.answer || r.data.message || '{{ __('app.error_generic') }}', r.ok);
            }).catch(function () {
                busy(btn, false);
                // Close the aria-live region on network failure too.
                show(box, '{{ __('app.error_generic') }}', false);
            });
        });

        // Redemption desk (Phase D).
        var redeemForm = document.getElementById('redeem-form');
        redeemForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var studentId = document.getElementById('redeem-student').value;
            var rewardId = document.getElementById('redeem-reward').value;
            var btn = redeemForm.querySelector('button[type="submit"]');
            var box = document.getElementById('redeem-result');
            busy(btn, true);
            postJson('/api/v1/students/' + studentId + '/redeem', {reward_id: parseInt(rewardId, 10)})
                .then(function (r) {
                    busy(btn, false);
                    if (r.ok) {
                        show(box,
                            '{{ __('app.ok') }} — {{ __('app.balance') }}: ' + r.data.new_balance, true);
                    } else {
                        show(box,
                            (r.data && r.data.message) || '{{ __('app.error_generic') }}', false);
                    }
                }).catch(function () {
                busy(btn, false);
                // Close the aria-live region on network failure too.
                show(box, '{{ __('app.error_generic') }}', false);
            });
        });

        // ---- TASK-029 — the KPI strip is LIVE. Attendance and the PAE
        // meals are DISTINCT-STUDENT counts, so a per-student Set keeps
        // a second tap from double-counting; recycling totals ride the
        // recycling channel's committed frames. (TASK-035: the readers
        // table this comment used to mention lives on /admin/readers.) ----
        function bumpStat(name, delta) {
            var stat = document.querySelector('[data-stat="' + name + '"]');
            if (!stat) { return; }
            var value = stat.querySelector('.stat-value');
            var n = parseInt(value.textContent, 10);
            if (isNaN(n)) { return; }
            value.textContent = String(Math.max(0, n + delta));
        }

        var seen = {attendance: {}, breakfast: {}, lunch: {}};

        document.addEventListener('realtime:tap', function (e) {
            var ev = e.detail || {};
            if (ev.student_id === undefined) { return; }
            var id = String(ev.student_id);

            if (ev.type === 'CLASS_ATTENDANCE' && !seen.attendance[id]) {
                seen.attendance[id] = true;
                bumpStat('attendance', 1);
            }
            if (ev.type === 'PAE_BREAKFAST' && !seen.breakfast[id]) {
                seen.breakfast[id] = true;
                bumpStat('pae_breakfast', 1);
            }
            if (ev.type === 'PAE_LUNCH' && !seen.lunch[id]) {
                seen.lunch[id] = true;
                bumpStat('pae_lunch', 1);
            }
        });

        document.addEventListener('realtime:recycling', function (e) {
            var update = e.detail || {};
            var payload = update.payload || {};
            if (update.type === 'validated') { bumpStat('recycling_items', 1); }
            if (update.type === 'points_awarded' && typeof payload.points === 'number') {
                bumpStat('recycling_points', payload.points);
            }
        });

    })();
</script>
@endsection
