{{--
    TASK-026 — mockup "Teacher Dashboard — Today's Attendance": KPI
    summary cards (real school-wide sums computed in-Blade from the
    role-scoped class data), cohort chips, per-class ledgers with
    client-side search, shared live feed. The realtime contract is
    byte-identical: realtime.js + the tap-row listener below.
    Mockup parts with no data source are omitted (documented):
    environmental metrics widget, instructor briefing card, telemetry
    strip (gaps #T1-T3 in docs/FRONTEND.md).
--}}
@extends('layouts.app')

@section('title', __('app.teacher_dashboard'))

@section('content')
<div class="lede">
    <span class="kicker">{{ __('app.today_attendance') }}</span>
    <h1>{{ __('app.teacher_dashboard') }} — {{ __('app.today_attendance') }}</h1>
    <p class="lede-sub">{{ __('app.late_cutoff_note', ['cutoff' => $cutoff]) }}</p>
</div>

{{-- KPI summary (school-wide sums over the role-scoped classes) --}}
@php($totals = ['present' => 0, 'late' => 0, 'absent' => 0, 'enrolled' => 0])
@foreach($attendanceByClass as $rows)
    @foreach($rows as $row)
        @php($totals[$row['status']] = ($totals[$row['status']] ?? 0) + 1)
        @php($totals['enrolled']++)
    @endforeach
@endforeach
<section class="stat-strip" data-reveal-stagger aria-label="{{ __('app.class_summary') }}">
    <x-stat :label="__('app.present')" stat="present">
        <x-slot:icon><span class="material-symbols-outlined is-16" aria-hidden="true">check_circle</span></x-slot:icon>
        {{ $totals['present'] }}
    </x-stat>
    <x-stat :label="__('app.late')" stat="late">
        <x-slot:icon><span class="material-symbols-outlined is-16" aria-hidden="true">schedule</span></x-slot:icon>
        {{ $totals['late'] }}
    </x-stat>
    <x-stat :label="__('app.absent')" stat="absent">
        <x-slot:icon><span class="material-symbols-outlined is-16" aria-hidden="true">cancel</span></x-slot:icon>
        {{ $totals['absent'] }}
    </x-stat>
    <x-stat :label="__('app.enrolled')" stat="enrolled">
        <x-slot:icon><span class="material-symbols-outlined is-16" aria-hidden="true">groups</span></x-slot:icon>
        {{ $totals['enrolled'] }}
    </x-stat>
</section>

{{-- TASK-016 — live activity feed + live attendance rows --}}
@include('partials.live-feed')

<div class="filterbar">
    <div class="searchbox">
        <span class="material-symbols-outlined is-18" aria-hidden="true">search</span>
        <input type="search" id="student-search" aria-label="{{ __('app.search_students') }}"
               placeholder="{{ __('app.search_students') }}" autocomplete="off">
    </div>
    <span class="t-label-sm muted" style="text-transform:uppercase;">{{ __('app.today_attendance') }}</span>
</div>

{{-- TASK-027 — the teacher's own NL query desk: same endpoint as the
      admin box, but every function execution is fenced server-side to
      THIS teacher's classes (StudentScope — never a prompt-side promise).
      Answers render light Markdown via markdown.js (escaped-first). --}}
@if(isset($nlQueryConfigured) && $nlQueryConfigured)
    <x-panel :label="__('app.nl_query')" rule>
        <p class="panel-sub">{{ __('app.nl_query_teacher_hint') }}</p>
        <form id="nl-query-form" class="tool-form">
            <input type="text" class="bare-input" id="nl-question"
                   placeholder="{{ __('app.nl_query_placeholder') }}" autocomplete="off"
                   aria-label="{{ __('app.nl_query') }}">
            <button type="submit" class="btn btn-primary">{{ __('app.ask') }}</button>
        </form>
        <div id="nl-answer" class="nl-answer hidden" aria-live="polite"></div>
    </x-panel>
@endif

<div class="stack" data-reveal data-cutoff="{{ $cutoff }}"
     data-label-present="{{ __('app.present') }}" data-label-late="{{ __('app.late') }}" data-label-absent="{{ __('app.absent') }}">
    @if($classes->isEmpty())
        <x-empty>{{ __('app.no_students') }}</x-empty>
    @else
        @foreach($classes as $class)
            @php($rows = $attendanceByClass[$class->id])
            {{-- TASK-017 — per-class summary chips: counts answer the
                  first question ("who's here?") before any table scan. --}}
            @php($counts = ['present' => 0, 'late' => 0, 'absent' => 0])
            @foreach($rows as $row)
                @php($counts[$row['status']] = ($counts[$row['status']] ?? 0) + 1)
            @endforeach
            <x-panel :label="__('app.class')" rule>
                <h2>{{ $class->name }}</h2>
                @if($class->teacher)
                    <p class="panel-sub">{{ $class->teacher->name }}</p>
                @endif

                <div class="sum-chips" data-class-panel="{{ $class->id }}" aria-label="{{ __('app.class_summary') }}">
                    <span class="sum-chip sum-chip-present" data-count="{{ $counts['present'] }}">{{ __('app.present') }} {{ $counts['present'] }}</span>
                    <span class="sum-chip sum-chip-late" data-count="{{ $counts['late'] }}">{{ __('app.late') }} {{ $counts['late'] }}</span>
                    <span class="sum-chip sum-chip-absent" data-count="{{ $counts['absent'] }}">{{ __('app.absent') }} {{ $counts['absent'] }}</span>
                </div>

                <div class="ledger-wrap">
                    <table class="ledger-table" data-stack data-class-ledger>
                        <thead>
                        <tr>
                            <th scope="col">{{ __('app.student') }}</th>
                            <th scope="col">{{ __('app.status') }}</th>
                            <th scope="col">{{ __('app.tapped_at') }}</th>
                            <th scope="col">{{ __('app.pae_enrolled') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($rows as $row)
                            <tr data-student-row="{{ $row['student']->id }}"
                                data-search="{{ mb_strtolower($row['student']->name) }}">
                                <td data-label="{{ __('app.student') }}">
                                    <span class="student-cell">
                                        {{ $row['student']->name }}
                                        <a class="tiny-link" href="{{ route('parent.timeline', $row['student']) }}">
                                            {{ __('app.view_parent') }}
                                        </a>
                                    </span>
                                </td>
                                <td class="js-tap-status" data-label="{{ __('app.status') }}">
                                    <x-stamp :status="$row['status']">{{ __('app.'.$row['status']) }}</x-stamp>
                                </td>
                                <td class="num js-tap-time" data-label="{{ __('app.tapped_at') }}">{{ $row['tappedAt'] ?? '—' }}</td>
                                <td class="num" data-label="{{ __('app.pae_enrolled') }}">{{ $row['student']->pae_enrolled ? __('app.pae_enrolled_yes') : __('app.pae_enrolled_no') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="muted">{{ __('app.no_students') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </x-panel>
        @endforeach
    @endif
</div>

<script src="{{ asset('js/realtime.js') }}"></script>
<script src="{{ asset('js/markdown.js') }}"></script>
<script>
    (function () {
        // TASK-026 — client-side student search across the class ledgers
        // (real rows, no backend round-trip).
        var search = document.getElementById('student-search');
        if (search) {
            search.addEventListener('input', function () {
                var q = search.value.trim().toLowerCase();
                document.querySelectorAll('tr[data-student-row]').forEach(function (row) {
                    row.hidden = q !== '' && (row.dataset.search || '').indexOf(q) === -1;
                });
            });
        }

        // TASK-027 — the teacher's NL query box (same wire as the admin
        // box; the server fences every function to this teacher's classes).
        var nlForm = document.getElementById('nl-query-form');
        if (nlForm) {
            var csrf = document.querySelector('meta[name="csrf-token"]').content;
            nlForm.addEventListener('submit', function (e) {
                e.preventDefault();
                var question = document.getElementById('nl-question').value.trim();
                if (!question) return;
                var btn = nlForm.querySelector('button');
                var box = document.getElementById('nl-answer');
                btn.disabled = true;
                btn.classList.add('is-loading');
                box.classList.remove('hidden');
                box.className = 'nl-answer';
                box.textContent = '{{ __('app.nl_query_processing') }}';
                fetch('/api/v1/nl-query', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrf
                    },
                    body: JSON.stringify({question: question})
                }).then(function (r) {
                    return r.json().then(function (data) { return {ok: r.ok, data: data}; });
                }).then(function (r) {
                    btn.disabled = false;
                    btn.classList.remove('is-loading');
                    box.classList.remove('hidden');
                    box.className = 'nl-answer ' + (r.ok ? 'answer-ok' : 'answer-error');
                    var text = (r.data && (r.data.answer || r.data.message)) || '{{ __('app.error_generic') }}';
                    if (r.ok && window.renderMarkdown) {
                        box.innerHTML = window.renderMarkdown(text);
                    } else {
                        box.textContent = text;
                    }
                }).catch(function () {
                    btn.disabled = false;
                    btn.classList.remove('is-loading');
                    // A network failure must still close the aria-live
                    // region — leaving "Thinking..." would freeze the box.
                    box.classList.remove('hidden');
                    box.className = 'nl-answer answer-error';
                    box.textContent = '{{ __('app.error_generic') }}';
                });
            });
        }

        // TASK-016 — attendance rows go live: a CLASS_ATTENDANCE tap
        // flips the student's row to Present/Late without a reload.
        // FIRST tap wins (the server keeps the day's first event as
        // the attendance one) — later taps never overwrite an earlier
        // time, matching classAttendanceToday's semantics.
        //
        // TASK-029 — the class PANEL is live too: the per-class summary
        // chips and the KPI strip move with every tap (row → chips →
        // totals, all from the same backend-confirmed frame; a tap for
        // a student whose row already shows a time is a duplicate and
        // moves nothing — first tap wins, same rule at every level).
        var stack = document.querySelector('.stack[data-cutoff]');
        if (!stack) return;
        var cutoff = stack.dataset.cutoff || '08:15';
        var labelPresent = stack.dataset.labelPresent || 'Present';
        var labelLate = stack.dataset.labelLate || 'Late';
        var labelAbsent = stack.dataset.labelAbsent || 'Absent';

        function bumpStat(name, delta) {
            var stat = document.querySelector('[data-stat="' + name + '"]');
            if (!stat) { return; }
            var value = stat.querySelector('.stat-value');
            var n = parseInt(value.textContent, 10);
            if (isNaN(n)) { return; }
            value.textContent = String(Math.max(0, n + delta));
        }

        function bumpChips(panel, oldStatus, newStatus) {
            if (oldStatus === newStatus) { return; }
            var chips = panel.querySelector('[data-class-panel]');
            if (!chips) { return; }
            [oldStatus, newStatus].forEach(function (status, i) {
                if (status !== 'present' && status !== 'late' && status !== 'absent') { return; }
                var chip = chips.querySelector('.sum-chip-' + status);
                if (!chip) { return; }
                var n = parseInt(chip.dataset.count || '0', 10);
                n = Math.max(0, n + (i === 0 ? -1 : 1));
                chip.dataset.count = String(n);
                chip.textContent = (status === 'present' ? labelPresent : (status === 'late' ? labelLate : labelAbsent)) + ' ' + n;
            });
        }

        document.addEventListener('realtime:tap', function (e) {
            var ev = e.detail || {};
            if (ev.type !== 'CLASS_ATTENDANCE') return;

            var row = document.querySelector('tr[data-student-row="' + ev.student_id + '"]');
            if (!row) return;

            var timeCell = row.querySelector('.js-tap-time');
            if (timeCell && timeCell.textContent !== '—' && timeCell.textContent !== '') {
                if ((ev.time || '99:99') >= timeCell.textContent) return; // first tap wins
            }

            var statusCell = row.querySelector('.js-tap-status');
            var oldStamp = statusCell ? statusCell.querySelector('[class*="stamp-"]') : null;
            var oldStatus = null;
            if (oldStamp) {
                ['present', 'late', 'absent'].forEach(function (s) {
                    if (oldStamp.classList.contains('stamp-' + s)) { oldStatus = s; }
                });
            }

            var late = (ev.time || '') > cutoff;
            var newStatus = late ? 'late' : 'present';
            if (statusCell) {
                statusCell.textContent = '';
                var stamp = document.createElement('span');
                stamp.className = 'stamp stamp-' + (late ? 'late' : 'present');
                stamp.textContent = late ? labelLate : labelPresent;
                statusCell.appendChild(stamp);
            }
            if (timeCell) { timeCell.textContent = ev.time || '—'; }

            bumpChips(row.closest('.panel'), oldStatus, newStatus);
            if (oldStatus !== newStatus) {
                if (oldStatus) { bumpStat(oldStatus, -1); }
                bumpStat(newStatus, 1);
            }

            row.classList.remove('js-row-flash');
            void row.offsetWidth; // restart the animation
            row.classList.add('js-row-flash');
        });
    })();
</script>
@endsection
