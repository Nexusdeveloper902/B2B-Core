{{--
    The recycling reporting desk: daily/monthly yields with SVG trend
    charts (hand-rolled, no chart library — the house doctrine), the
    material mix, the points leaderboard, and PDF/CSV exports.

    ONE data layer: RecyclingReportService — the same derivations the
    EcoStation hub reads, so a report can never disagree with it.
--}}
@extends('layouts.app')

@section('title', __('app.reports_recycling'))

@php
    $trendMax = 1;
    foreach ($trend as $day) { $trendMax = max($trendMax, $day['items']); }
    $groupW = 36; $barW = 26; $chartH = 110; $baseline = $chartH + 18;
    $trendWidth = count($trend) * $groupW + 10;
@endphp

@section('content')
<div class="page-head">
    <h1 class="page-title">{{ __('app.reports_recycling') }}</h1>
    <p class="lede muted">{{ __('app.reports_recycling_sub') }}</p>
</div>

{{-- Controls: date / month (GET form — no-JS fallback). --}}
<form class="report-controls" method="GET" action="{{ route('admin.reports.recycling') }}">
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
        <a class="btn" href="{{ route('admin.reports.recycling.export.pdf') }}?type=daily&date={{ $date }}">{{ __('app.export_pdf') }}</a>
        <a class="btn" href="{{ route('admin.reports.recycling.export.pdf') }}?type=monthly&month={{ $month }}">{{ __('app.export_pdf_monthly') }}</a>
        <a class="btn" href="{{ route('admin.reports.recycling.export.pdf') }}?type=leaderboard">{{ __('app.leaderboard_board') }}</a>
        <a class="btn" href="{{ route('admin.reports.recycling.export.csv') }}?type=daily&date={{ $date }}">CSV</a>
    </span>
</form>

{{-- KPI strip. --}}
<div class="stat-row">
    <x-stat :label="__('app.report_stat_items')" :stat="number_format($daily['items'])" icon="recycling" />
    <x-stat :label="__('app.report_stat_points')" :stat="number_format($daily['points'])" icon="stars" />
    <x-stat :label="__('app.report_stat_month')" :stat="$month" icon="calendar_month" />
    <x-stat :label="__('app.report_stat_leader')" :stat="($leaderboard[0]['student_name'] ?? '—')" icon="emoji_events" />
</div>

{{-- Yields: 14-day trend chart. --}}
<x-panel :label="__('app.report_trend_title')" rule>
    <svg class="report-chart" viewBox="0 0 {{ $trendWidth }} {{ $baseline + 14 }}" role="img"
         aria-label="{{ __('app.report_trend_title') }}" preserveAspectRatio="xMidYMid meet">
        @foreach($trend as $i => $day)
            @php
                $x0 = $i * $groupW + 5;
                $bh = (int) round($day['items'] / $trendMax * $chartH);
            @endphp
            <rect x="{{ $x0 }}" y="{{ $baseline - $bh }}" width="{{ $barW }}" height="{{ max(1, $bh) }}"
                  rx="2" class="bar-lunch">
                <title>{{ $day['date'] }} — {{ $day['items'] }}</title>
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
    {{-- Monthly totals. --}}
    <x-panel :label="__('app.report_recycling_monthly_title', ['month' => $month])" rule>
        <div class="stat-row stat-row-compact">
            <x-stat :label="__('app.report_stat_items')" :stat="number_format($monthly['totals']['items'])" />
            <x-stat :label="__('app.report_stat_points')" :stat="number_format($monthly['totals']['points'])" />
        </div>
        <a class="btn sp-t-sm" href="{{ route('admin.reports.recycling.export.csv') }}?type=monthly&month={{ $month }}">{{ __('app.export_csv_monthly') }}</a>
    </x-panel>

    {{-- Material mix (last 14 days). --}}
    <x-panel :label="__('app.report_material_mix')" rule>
        <dl class="report-dl">
            @foreach($mix as $material => $row)
                <div><dt>{{ __('app.material_'.$material) }} ({{ $row['rate'] }} pts)</dt><dd>{{ number_format($row['items']) }}</dd></div>
            @endforeach
        </dl>
    </x-panel>
</div>

{{-- Leaderboard. --}}
<x-panel :label="__('app.leaderboard_board')" rule>
    <div class="ledger-wrap">
        <table class="ledger-table">
            <thead>
            <tr>
                <th>{{ __('app.report_col_rank') }}</th>
                <th>{{ __('app.report_col_student') }}</th>
                <th>{{ __('app.report_col_class') }}</th>
                <th>{{ __('app.report_col_points') }}</th>
            </tr>
            </thead>
            <tbody>
            @forelse($leaderboard as $row)
                <tr>
                    <td data-label="{{ __('app.report_col_rank') }}">{{ $row['rank'] }}</td>
                    <td data-label="{{ __('app.report_col_student') }}"><a href="{{ route('admin.reports.recycling.student', $row['student_id']) }}">{{ $row['student_name'] }}</a></td>
                    <td data-label="{{ __('app.report_col_class') }}">{{ $row['class_name'] ?? '—' }}</td>
                    <td data-label="{{ __('app.report_col_points') }}">{{ number_format($row['points']) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="muted">{{ __('app.report_none') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-panel>

{{-- Per-student entry point. --}}
<x-panel :label="__('app.report_student_pick')" rule>
    <form class="report-student-pick" method="GET" action="{{ route('admin.reports.recycling') }}">
        <label class="field">
            <span>{{ __('app.report_col_student') }}</span>
            <select name="__student" id="report-student" data-base="{{ route('admin.reports.recycling.student', ['student' => '__ID__']) }}">
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
