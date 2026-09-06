{{--
    TASK-026 — mockup "Admin Dashboard — School Today": 5-KPI hero strip
    (attendance black card + PAE + recycling), readers hardware table,
    NL query panel, redemption module, student directory, shared live
    feed. Every id/class/form contract is unchanged (nl-query-form,
    redeem-form, mode-form…). Mockup parts with no data source are
    omitted and documented (docs/FRONTEND.md): node/telemetry strip,
    "Force Telemetry Poll" and "Global Thresholds" buttons, campus
    operations summary card (gaps #A1-A3).
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
    <x-stat :label="__('app.attendance_count')">
        <x-slot:icon>
            <span class="material-symbols-outlined is-16" aria-hidden="true">how_to_reg</span>
        </x-slot:icon>
        {{ $attendanceToday }}
    </x-stat>
    <x-stat :label="__('app.pae_breakfast')">
        <x-slot:icon>
            <span class="material-symbols-outlined is-16" aria-hidden="true">bakery_dining</span>
        </x-slot:icon>
        {{ $paeBreakfastToday }}
    </x-stat>
    <x-stat :label="__('app.pae_lunch')">
        <x-slot:icon>
            <span class="material-symbols-outlined is-16" aria-hidden="true">lunch_dining</span>
        </x-slot:icon>
        {{ $paeLunchToday }}
    </x-stat>
    <x-stat :label="__('app.recycling_items')">
        <x-slot:icon>
            <span class="material-symbols-outlined is-16" aria-hidden="true">recycling</span>
        </x-slot:icon>
        {{ $recyclingToday['items'] }}
    </x-stat>
    <x-stat :label="__('app.recycling_points')">
        <x-slot:icon>
            <span class="material-symbols-outlined is-16" aria-hidden="true">toll</span>
        </x-slot:icon>
        {{ $recyclingToday['points'] }}
    </x-stat>
</section>

{{-- TASK-016 — live activity: server-rendered, then WebSocket-live --}}
@include('partials.live-feed')

<section class="grid-2 grid-2-wide-left" data-reveal>
    {{-- Reader list + mode control --}}
    <x-panel :label="__('app.readers')" rule>
        <div class="ledger-wrap">
            <table class="ledger-table" data-stack>
                <thead>
                <tr>
                    <th scope="col">{{ __('app.reader') }}</th>
                    <th scope="col">{{ __('app.reader_type') }}</th>
                    <th scope="col">{{ __('app.active_mode') }}</th>
                </tr>
                </thead>
                <tbody>
                @forelse($readers as $reader)
                    <tr>
                        <td data-label="{{ __('app.reader') }}">{{ $reader->label }}</td>
                        <td data-label="{{ __('app.reader_type') }}"><code>{{ $reader->type->value }}</code></td>
                        <td data-label="{{ __('app.active_mode') }}">
                            <form class="mode-form tool-form" data-reader="{{ $reader->id }}">
                                <select name="active_event_type" class="mode-select bare-select"
                                        id="mode-{{ $reader->id }}" aria-label="{{ __('app.active_mode') }}">
                                    @foreach(\App\Enums\EventType::cases() as $eventType)
                                        <option value="{{ $eventType->value }}"
                                                @selected($reader->active_event_type === $eventType->value)>
                                            {{ $eventType->value }}
                                        </option>
                                    @endforeach
                                </select>
                                <button type="submit" class="btn btn-quiet btn-small"
                                        data-endpoint="{{ '/api/v1/admin/readers/'.$reader->id.'/mode' }}">
                                    {{ __('app.change_mode') }}
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="muted">—</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-panel>

    {{-- NL query box --}}
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
                    <option value="{{ $reward->id }}">{{ $reward->name }} ({{ $reward->point_cost }} pts)</option>
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
                        <span class="meta">{{ $student->schoolClass?->name }} · {{ $student->pae_enrolled ? 'PAE ✓' : '—' }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-panel>
</section>

<script src="{{ asset('js/realtime.js') }}"></script>
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

        function show(el, text, ok) {
            el.classList.remove('hidden');
            el.textContent = text;
            el.className = 'nl-answer ' + (ok ? 'answer-ok' : 'answer-error');
        }

        // Reader mode change (calls the Phase B mode endpoint).
        document.querySelectorAll('.mode-form').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var select = form.querySelector('.mode-select');
                var btn = form.querySelector('button');
                busy(btn, true);
                postJson(btn.dataset.endpoint, {active_event_type: select.value}).then(function (r) {
                    busy(btn, false);
                    if (r.ok) {
                        show(document.getElementById('nl-answer'),
                            '{{ __('app.mode_updated') }}', true);
                    } else {
                        show(document.getElementById('nl-answer'),
                            (r.data && r.data.message) || '{{ __('app.error_generic') }}', false);
                    }
                }).catch(function () { busy(btn, false); });
            });
        });

        // NL query box (Phase E).
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
            box.textContent = '…';
            postJson('/api/v1/nl-query', {question: question}).then(function (r) {
                busy(btn, false);
                show(box, r.data.answer || r.data.message || '{{ __('app.error_generic') }}', r.ok);
            }).catch(function () { busy(btn, false); });
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
                }).catch(function () { busy(btn, false); });
        });
    })();
</script>
@endsection
