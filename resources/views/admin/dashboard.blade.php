@extends('layouts.app')

@section('title', __('app.admin_dashboard'))

@section('content')
<div class="page-head">
    <h1>{{ __('app.admin_dashboard') }}</h1>
</div>

{{-- School-wide stats today --}}
<section class="stat-grid">
    <div class="stat-card">
        <span class="stat-value">{{ $attendanceToday }}</span>
        <span class="stat-label">{{ __('app.attendance_count') }}</span>
    </div>
    <div class="stat-card">
        <span class="stat-value">{{ $paeBreakfastToday }}</span>
        <span class="stat-label">{{ __('app.pae_breakfast') }}</span>
    </div>
    <div class="stat-card">
        <span class="stat-value">{{ $paeLunchToday }}</span>
        <span class="stat-label">{{ __('app.pae_lunch') }}</span>
    </div>
    <div class="stat-card">
        <span class="stat-value">{{ $recyclingToday['items'] }}</span>
        <span class="stat-label">{{ __('app.recycling_items') }}</span>
    </div>
    <div class="stat-card">
        <span class="stat-value">{{ $recyclingToday['points'] }}</span>
        <span class="stat-label">{{ __('app.recycling_points') }}</span>
    </div>
</section>

<section class="two-col">
    {{-- Reader list + mode control --}}
    <div class="card">
        <h2>{{ __('app.readers') }}</h2>

        <table class="table">
            <thead>
            <tr>
                <th>{{ __('app.reader') }}</th>
                <th>{{ __('app.reader_type') }}</th>
                <th>{{ __('app.active_mode') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach($readers as $reader)
                <tr>
                    <td>{{ $reader->label }}</td>
                    <td><code>{{ $reader->type->value }}</code></td>
                    <td>
                        <form class="mode-form" data-reader="{{ $reader->id }}">
                            <select name="active_event_type" class="mode-select" id="mode-{{ $reader->id }}">
                                @foreach(\App\Enums\EventType::cases() as $eventType)
                                    <option value="{{ $eventType->value }}"
                                            @selected($reader->active_event_type === $eventType->value)>
                                        {{ $eventType->value }}
                                    </option>
                                @endforeach
                            </select>
                            <button type="submit" class="btn btn-small"
                                    data-endpoint="{{ '/api/v1/admin/readers/'.$reader->id.'/mode' }}">
                                {{ __('app.change_mode') }}
                            </button>
                        </form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    {{-- NL query box --}}
    <div class="card">
        <h2>{{ __('app.nl_query') }}</h2>

        @unless($nlQueryConfigured)
            <p class="flash flash-warn">{{ __('app.nl_query_not_configured') }}</p>
        @endunless

        <form id="nl-query-form">
            <input type="text" id="nl-question" placeholder="{{ __('app.nl_query_placeholder') }}"
                   autocomplete="off">
            <button type="submit" class="btn btn-primary">{{ __('app.ask') }}</button>
        </form>

        <div id="nl-answer" class="nl-answer hidden"></div>
    </div>
</section>

<section class="two-col">
    {{-- Redemption desk --}}
    <div class="card">
        <h2>{{ __('app.redemption') }}</h2>

        <form id="redeem-form">
            <select id="redeem-student" required>
                @foreach($students as $student)
                    <option value="{{ $student->id }}">{{ $student->name }}</option>
                @endforeach
            </select>

            <select id="redeem-reward" required>
                @foreach($rewards as $reward)
                    <option value="{{ $reward->id }}">{{ $reward->name }} ({{ $reward->point_cost }} pts)</option>
                @endforeach
            </select>

            <button type="submit" class="btn btn-primary">{{ __('app.redeem') }}</button>
        </form>
        <div id="redeem-result" class="nl-answer hidden"></div>
    </div>

    {{-- Students quick links (parent view entry) --}}
    <div class="card">
        <h2>{{ __('app.students') }}</h2>
        <ul class="student-links">
            @foreach($students as $student)
                <li>
                    <a href="{{ route('parent.timeline', $student) }}">{{ $student->name }}</a>
                    <span class="muted">
                        {{ $student->schoolClass?->name }} · {{ $student->pae_enrolled ? 'PAE ✓' : '—' }}
                    </span>
                </li>
            @endforeach
        </ul>
    </div>
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
