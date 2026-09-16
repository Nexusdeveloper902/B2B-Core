{{--
    The attendance reporting desk: daily present/late/absent with a
    per-class breakdown and SVG trend charts (hand-rolled, no chart
    library — the house doctrine), monthly aggregates, repeat
    absentees, perfect attendance, and PDF/CSV exports.

    ONE data layer: AttendanceService — the same methods the teacher
    dashboard and the NL functions call, so a report can never
    disagree with either.
--}}
@extends('layouts.app')

@section('title', __('app.reports_attendance'))

@php
    $trendMax = 1;
    foreach ($trend as $day) { $trendMax = max($trendMax, $day['students']); }
    $groupW = 36; $barW = 26; $chartH = 110; $baseline = $chartH + 18;
    $trendWidth = count($trend) * $groupW + 10;
@endphp

@section('content')
<div class="page-head">
    <h1 class="page-title">{{ __('app.reports_attendance') }}</h1>
    <p class="lede muted">{{ __('app.reports_attendance_sub') }}</p>
</div>

{{-- Controls: date / month (GET form — no-JS fallback). --}}
<form class="report-controls" method="GET" action="{{ route('admin.reports.attendance') }}">
    <label class="field">
        <span>{{ __('app.report_date') }}</span>
        <input type="date" name="date" value="{{ $date }}" max="{{ \Illuminate\Support\Carbon::today()->toDateString() }}">
    </label>
    <label class="field">
        <span>{{ __('app.report_month') }}</span>
        <input type="month" name="month" value="{{ $month }}">
    </label>
    <button type="submit" class="btn primary">{{ __('app.report_apply') }}</button>

    <span class="report-exports">
        <a class="btn" href="{{ route('admin.reports.attendance.export.pdf') }}?type=daily&date={{ $date }}">{{ __('app.export_pdf') }}</a>
        <a class="btn" href="{{ route('admin.reports.attendance.export.pdf') }}?type=monthly&month={{ $month }}">{{ __('app.export_pdf_monthly') }}</a>
        <a class="btn" href="{{ route('admin.reports.attendance.export.csv') }}?type=daily&date={{ $date }}">CSV</a>
    </span>
</form>

{{-- KPI strip. --}}
<div class="stat-row">
    <x-stat :label="__('app.report_stat_present')" :stat="number_format($present)" icon="how_to_reg" />
    <x-stat :label="__('app.report_stat_late')" :stat="number_format(count($late))" icon="schedule" />
    <x-stat :label="__('app.report_stat_absent')" :stat="number_format($absent)" icon="person_off" />
    <x-stat :label="__('app.report_stat_school_days')" :stat="number_format($monthly['totals']['school_days'])" icon="calendar_month" />
</div>

{{-- Attendance: 14-day trend chart. --}}
<x-panel :label="__('app.report_trend_title')" rule>
    <svg class="report-chart" viewBox="0 0 {{ $trendWidth }} {{ $baseline + 14 }}" role="img"
         aria-label="{{ __('app.report_trend_title') }}" preserveAspectRatio="xMidYMid meet">
        @foreach($trend as $i => $day)
            @php
                $x0 = $i * $groupW + 5;
                $bh = (int) round($day['students'] / $trendMax * $chartH);
            @endphp
            <rect x="{{ $x0 }}" y="{{ $baseline - $bh }}" width="{{ $barW }}" height="{{ max(1, $bh) }}"
                  rx="2" class="bar-lunch">
                <title>{{ $day['date'] }} — {{ $day['students'] }}</title>
            </rect>
            @if($i % 2 === 0)
                <text x="{{ $x0 + $barW / 2 }}" y="{{ $baseline + 12 }}" text-anchor="middle" class="chart-label">
                    {{ substr($day['date'], 5) }}
                </text>
            @endif
        @endforeach
        <line x1="0" y1="{{ $baseline }}" x2="{{ $trendWidth }}" y2="{{ $baseline }}" class="chart-axis" />
    </svg>
</x-panel>

<div class="report-cols">
    {{-- Per-class breakdown for the selected date. --}}
    <x-panel :label="__('app.report_by_class_attendance')" rule>
        <div class="ledger-wrap">
            <table class="ledger-table">
                <thead>
                <tr>
                    <th>{{ __('app.report_col_class') }}</th>
                    <th>{{ __('app.report_col_present') }}</th>
                    <th>{{ __('app.report_col_absent') }}</th>
                    <th>{{ __('app.report_col_rate') }}</th>
                </tr>
                </thead>
                <tbody>
                @forelse($byClass as $row)
                    <tr>
                        <td data-label="{{ __('app.report_col_class') }}">{{ $row['class_name'] }}</td>
                        <td data-label="{{ __('app.report_col_present') }}">{{ $row['present'] }}</td>
                        <td data-label="{{ __('app.report_col_absent') }}">{{ $row['absent'] }}</td>
                        <td data-label="{{ __('app.report_col_rate') }}">{{ $row['rate'] }}%</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="muted">{{ __('app.report_none') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-panel>

    {{-- Monthly totals. --}}
    <x-panel :label="__('app.report_attendance_monthly_title', ['month' => $month])" rule>
        <div class="stat-row stat-row-compact">
            <x-stat :label="__('app.report_stat_attendances')" :stat="number_format($monthly['totals']['attendances'])" />
            <x-stat :label="__('app.report_stat_school_days')" :stat="number_format($monthly['totals']['school_days'])" />
            <x-stat :label="__('app.report_stat_peak')" :stat="number_format($monthly['totals']['peak'])" />
        </div>
        <a class="btn sp-t-sm" href="{{ route('admin.reports.attendance.export.csv') }}?type=monthly&month={{ $month }}">{{ __('app.export_csv_monthly') }}</a>
    </x-panel>
</div>

<div class="report-cols">
    {{-- Late arrivals (selected date). --}}
    <x-panel :label="__('app.report_late_title')" rule>
        <div class="ledger-wrap">
            <table class="ledger-table">
                <thead>
                <tr>
                    <th>{{ __('app.report_col_student') }}</th>
                    <th>{{ __('app.report_col_class') }}</th>
                    <th>{{ __('app.report_col_tapped') }}</th>
                </tr>
                </thead>
                <tbody>
                @forelse(array_slice($late, 0, 10) as $row)
                    <tr>
                        <td data-label="{{ __('app.report_col_student') }}"><a href="{{ route('admin.reports.attendance.student', $row['id']) }}">{{ $row['name'] }}</a></td>
                        <td data-label="{{ __('app.report_col_class') }}">{{ $row['class_name'] ?? '—' }}</td>
                        <td data-label="{{ __('app.report_col_tapped') }}">{{ $row['tapped_at'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="muted">{{ __('app.report_none') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-panel>

    {{-- Absent students (selected date). --}}
    <x-panel :label="__('app.report_absent_title')" rule>
        <div class="ledger-wrap">
            <table class="ledger-table">
                <thead>
                <tr>
                    <th>{{ __('app.report_col_student') }}</th>
                    <th>{{ __('app.report_col_class') }}</th>
                </tr>
                </thead>
                <tbody>
                @forelse(array_slice($absentList, 0, 10) as $row)
                    <tr>
                        <td data-label="{{ __('app.report_col_student') }}"><a href="{{ route('admin.reports.attendance.student', $row['id']) }}">{{ $row['name'] }}</a></td>
                        <td data-label="{{ __('app.report_col_class') }}">{{ $row['class_name'] ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="2" class="muted">{{ __('app.report_none') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-panel>
</div>

{{-- Repeat absentees. --}}
<x-panel :label="__('app.report_absentees_title')" rule>
    <p class="muted small">{{ __('app.report_stat_students') }}: {{ number_format(count($repeatAbsent)) }} · {{ __('app.report_stat_present') }}: {{ number_format($perfectCount) }} / 30 {{ __('app.report_stat_school_days') }}</p>
    <div class="ledger-wrap">
        <table class="ledger-table">
            <thead>
            <tr>
                <th>{{ __('app.report_col_student') }}</th>
                <th>{{ __('app.report_col_class') }}</th>
                <th>{{ __('app.report_col_absences') }}</th>
            </tr>
            </thead>
            <tbody>
            @forelse($repeatAbsent as $row)
                <tr>
                    <td data-label="{{ __('app.report_col_student') }}"><a href="{{ route('admin.reports.attendance.student', $row['id']) }}">{{ $row['name'] }}</a></td>
                    <td data-label="{{ __('app.report_col_class') }}">{{ $row['class_name'] ?? '—' }}</td>
                    <td data-label="{{ __('app.report_col_absences') }}">{{ $row['absences'] }} / {{ $row['school_days'] }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="muted">{{ __('app.report_none') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-panel>

{{-- Per-student entry point. --}}
<x-panel :label="__('app.report_student_pick')" rule>
    <form class="report-student-pick" method="GET" action="{{ route('admin.reports.attendance') }}">
        <label class="field">
            <span>{{ __('app.report_col_student') }}</span>
            <select name="__student" id="report-student" data-base="{{ route('admin.reports.attendance.student', ['student' => '__ID__']) }}">
                @foreach($students as $student)
                    <option value="{{ $student->id }}">{{ $student->name }}</option>
                @endforeach
            </select>
        </label>
        <button type="button" class="btn primary" id="report-student-go">{{ __('app.report_open_student') }}</button>
    </form>
</x-panel>

<script>
(function () {
    'use strict';
    var select = document.getElementById('report-student');
    var go = document.getElementById('report-student-go');
    if (!select || !go) { return; }
    go.addEventListener('click', function () {
        var base = select.dataset.base || '';
        window.location.href = base.replace('__ID__', encodeURIComponent(select.value || ''));
    });
})();
</script>
@endsection
