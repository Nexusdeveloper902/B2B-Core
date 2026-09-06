@extends('layouts.app')

@section('title', __('app.teacher_dashboard'))

@section('content')
<div class="page-head">
    <h1>{{ __('app.teacher_dashboard') }} — {{ __('app.today_attendance') }}</h1>
    <p class="page-meta">
        <span>{{ __('app.late_cutoff_note', ['cutoff' => $cutoff]) }}</span>
    </p>
</div>

{{-- TASK-016 — live activity feed + live attendance rows --}}
@include('partials.live-feed')

<div class="stack" data-cutoff="{{ $cutoff }}"
     data-label-present="{{ __('app.present') }}" data-label-late="{{ __('app.late') }}">
    @if($classes->isEmpty())
        <x-empty>{{ __('app.no_students') }}</x-empty>
    @else
        @foreach($classes as $class)
            @php($rows = $attendanceByClass[$class->id])
            <x-panel :label="__('app.class')" rule>
                <h2>{{ $class->name }}</h2>
                @if($class->teacher)
                    <p class="panel-sub">{{ $class->teacher->name }}</p>
                @endif

                <div class="ledger-wrap">
                    <table class="ledger-table">
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
                            <tr data-student-row="{{ $row['student']->id }}">
                                <td>
                                    <span class="student-cell">
                                        {{ $row['student']->name }}
                                        <a class="tiny-link" href="{{ route('parent.timeline', $row['student']) }}">
                                            {{ __('app.view_parent') }}
                                        </a>
                                    </span>
                                </td>
                                <td class="js-tap-status">
                                    <x-stamp :status="$row['status']">{{ __('app.'.$row['status']) }}</x-stamp>
                                </td>
                                <td class="num js-tap-time">{{ $row['tappedAt'] ?? '—' }}</td>
                                <td class="num">{{ $row['student']->pae_enrolled ? '✓' : '—' }}</td>
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
<script>
    (function () {
        // TASK-016 — attendance rows go live: a CLASS_ATTENDANCE tap
        // flips the student's row to Present/Late without a reload.
        // FIRST tap wins (the server keeps the day's first event as
        // the attendance one) — later taps never overwrite an earlier
        // time, matching classAttendanceToday's semantics.
        var stack = document.querySelector('.stack[data-cutoff]');
        if (!stack) return;
        var cutoff = stack.dataset.cutoff || '08:15';
        var labelPresent = stack.dataset.labelPresent || 'Present';
        var labelLate = stack.dataset.labelLate || 'Late';

        document.addEventListener('realtime:tap', function (e) {
            var ev = e.detail || {};
            if (ev.type !== 'CLASS_ATTENDANCE') return;

            var row = document.querySelector('tr[data-student-row="' + ev.student_id + '"]');
            if (!row) return;

            var timeCell = row.querySelector('.js-tap-time');
            if (timeCell && timeCell.textContent !== '—' && timeCell.textContent !== '') {
                if ((ev.time || '99:99') >= timeCell.textContent) return; // first tap wins
            }

            var late = (ev.time || '') > cutoff;
            var statusCell = row.querySelector('.js-tap-status');
            if (statusCell) {
                statusCell.textContent = '';
                var stamp = document.createElement('span');
                stamp.className = 'stamp stamp-' + (late ? 'late' : 'present');
                stamp.textContent = late ? labelLate : labelPresent;
                statusCell.appendChild(stamp);
            }
            if (timeCell) { timeCell.textContent = ev.time || '—'; }

            row.classList.remove('js-row-flash');
            void row.offsetWidth; // restart the animation
            row.classList.add('js-row-flash');
        });
    })();
</script>
@endsection
