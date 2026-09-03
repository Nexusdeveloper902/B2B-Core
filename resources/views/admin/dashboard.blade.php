@extends('layouts.app')

@section('title', __('app.admin_dashboard'))

@section('content')
<div class="page-head">
    <h1>{{ __('app.admin_dashboard') }}</h1>
    <p class="page-meta"><span>{{ __('app.school_today') }}</span></p>
</div>

{{-- School-wide stats today: ruled spec strip, values in pine --}}
<section class="stat-strip" aria-label="{{ __('app.school_today') }}">
    <x-stat :label="__('app.attendance_count')">{{ $attendanceToday }}</x-stat>
    <x-stat :label="__('app.pae_breakfast')">{{ $paeBreakfastToday }}</x-stat>
    <x-stat :label="__('app.pae_lunch')">{{ $paeLunchToday }}</x-stat>
    <x-stat :label="__('app.recycling_items')">{{ $recyclingToday['items'] }}</x-stat>
    <x-stat :label="__('app.recycling_points')">{{ $recyclingToday['points'] }}</x-stat>
</section>

<section class="grid-2">
    {{-- Reader list + mode control --}}
    <x-panel :label="__('app.readers')" rule>
        <div class="ledger-wrap">
            <table class="ledger-table">
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
                        <td>{{ $reader->label }}</td>
                        <td><code>{{ $reader->type->value }}</code></td>
                        <td>
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

<section class="grid-2">
    {{-- Redemption desk --}}
    <x-panel :label="__('app.redemption')">
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

<script>
    (function () {
        var csrf = document.querySelector('meta[name="csrf-token"]').content;

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
                btn.disabled = true;
                postJson(btn.dataset.endpoint, {active_event_type: select.value}).then(function (r) {
                    btn.disabled = false;
                    if (r.ok) {
                        show(document.getElementById('nl-answer'),
                            '{{ __('app.mode_updated') }}', true);
                    } else {
                        show(document.getElementById('nl-answer'),
                            (r.data && r.data.message) || '{{ __('app.error_generic') }}', false);
                    }
                });
            });
        });

        // NL query box (Phase E).
        var nlForm = document.getElementById('nl-query-form');
        nlForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var question = document.getElementById('nl-question').value.trim();
            if (!question) return;
            var box = document.getElementById('nl-answer');
            box.classList.remove('hidden');
            box.className = 'nl-answer';
            box.textContent = '…';
            postJson('/api/v1/nl-query', {question: question}).then(function (r) {
                show(box, r.data.answer || r.data.message || '{{ __('app.error_generic') }}', r.ok);
            });
        });

        // Redemption desk (Phase D).
        var redeemForm = document.getElementById('redeem-form');
        redeemForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var studentId = document.getElementById('redeem-student').value;
            var rewardId = document.getElementById('redeem-reward').value;
            var box = document.getElementById('redeem-result');
            postJson('/api/v1/students/' + studentId + '/redeem', {reward_id: parseInt(rewardId, 10)})
                .then(function (r) {
                    if (r.ok) {
                        show(box,
                            '{{ __('app.ok') }} — {{ __('app.balance') }}: ' + r.data.new_balance, true);
                    } else {
                        show(box,
                            (r.data && r.data.message) || '{{ __('app.error_generic') }}', false);
                    }
                });
        });
    })();
</script>
@endsection
