{{--
    The per-student attendance report: one student's day-by-day status
    with a graphical participation strip. Shares
    AttendanceService::studentAttendanceHistory — one truth, one surface
    plus its CSV/PDF exports.
--}}
@extends('layouts.app')

@section('title', __('app.report_attendance_student_doc_title', ['name' => $student->name]))

@section('content')
<div class="page-head">
    <h1 class="page-title">{{ $student->name }}</h1>
    <p class="lede muted">{{ $student->schoolClass?->name }} · {{ __('app.reports_attendance') }}</p>
</div>

<div class="report-student-badges">
    <span class="live-chip">{{ __('app.report_stat_present') }}: {{ $history['totals']['present'] }}</span>
    <span class="live-chip">{{ __('app.report_stat_late') }}: {{ $history['totals']['late'] }}</span>
    <span class="live-chip">{{ __('app.report_stat_absent') }}: {{ $history['totals']['absent'] }}</span>
    <span class="report-exports">
        <a class="btn" href="{{ route('admin.reports.attendance.student.export.pdf', $student) }}">{{ __('app.export_pdf') }}</a>
        <a class="btn" href="{{ route('admin.reports.attendance.student.export.csv', $student) }}">CSV</a>
        <a class="btn" href="{{ route('parent.timeline', $student) }}">{{ __('app.view_parent') }}</a>
    </span>
</div>

{{-- Participation strip: one column per school day; green marks present,
     amber marks late, muted marks absent — the graphical view of the
     table below. --}}
<x-panel :label="__('app.report_student_history_title')" rule>
    @if(count($history['days']) > 0)
        <div class="report-participation" role="img" aria-label="{{ __('app.report_student_history_title') }}">
            @foreach(array_slice($history['days'], 0, 30) as $day)
                @php
                    $cellClass = $day['status'] === 'present' ? 'is-served' : ($day['status'] === 'late' ? 'is-flagged' : 'is-empty');
                    $title = $day['date'] . ' — ' . __('app.' . $day['status']) . ($day['tapped_at'] ? ' · ' . $day['tapped_at'] : '');
                @endphp
                <span class="participation-day" title="{{ $title }}">
                    <span class="participation-cell {{ $cellClass }}">{{ $day['tapped_at'] ?? '' }}</span>
                    <span class="participation-cell {{ $cellClass }}">{{ substr($day['status'], 0, 1) }}</span>
                    <span class="participation-date">{{ substr($day['date'], 5) }}</span>
                </span>
            @endforeach
        </div>
        <p class="chart-legend muted small">
            <span class="legend-swatch is-served"></span> {{ __('app.present') }}
            <span class="legend-swatch is-flagged sp-l-md"></span> {{ __('app.late') }}
            <span class="legend-swatch is-empty sp-l-md"></span> {{ __('app.absent') }}
        </p>
    @else
        <x-empty :label="__('app.report_none')" />
    @endif
</x-panel>

<div class="ledger-wrap">
    <table class="ledger-table">
        <thead>
        <tr>
            <th>{{ __('app.report_col_date') }}</th>
            <th>{{ __('app.status') }}</th>
            <th>{{ __('app.tapped_at') }}</th>
        </tr>
        </thead>
        <tbody>
        @forelse($history['days'] as $day)
            <tr>
                <td data-label="{{ __('app.report_col_date') }}">{{ $day['date'] }}</td>
                <td data-label="{{ __('app.status') }}">{{ __('app.' . $day['status']) }}</td>
                <td data-label="{{ __('app.tapped_at') }}">{{ $day['tapped_at'] ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="3" class="muted">{{ __('app.report_none') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<p class="sp-t-md">
    <a class="btn" href="{{ route('admin.reports.attendance') }}">{{ __('app.report_back') }}</a>
</p>
@endsection
