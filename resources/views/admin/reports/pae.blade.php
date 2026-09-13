{{--
    TASK-037 — the PAE reporting desk (ADR-056): general administrative
    report. Sections: daily/monthly served meals with SVG trend charts
    (hand-rolled, no build step, no chart library — the house doctrine),
    per-meal enrollment summary, missed meals (list + trend), flagged
    (excluded) attempts, and PDF/CSV exports.

    ONE data layer: PaeReportService — the same methods the NL functions
    call, so an LLM answer can never disagree with this page.
--}}
@extends('layouts.app')

@section('title', __('app.reports_pae'))

@php
    // Trend chart geometry (SVG, hand-rolled — no chart library).
    $trendMax = 1;
    foreach ($trend as $day) { $trendMax = max($trendMax, $day['breakfast'] + $day['lunch']); }
    $missedMax = 1;
    foreach ($missedTrend as $row) { $missedMax = max($missedMax, $row['missed']); }
    $groupW = 36; $barW = 13; $chartH = 110; $baseline = $chartH + 18;
    $trendWidth = count($trend) * $groupW + 10;
    $missedWidth = count($missedTrend) * 24 + 10;
@endphp

@section('content')
<div class="page-head">
    <h1 class="page-title">{{ __('app.reports_pae') }}</h1>
    <p class="lede muted">{{ __('app.reports_pae_sub') }}</p>
</div>

{{-- Controls: date / meal / month (GET form — no-JS fallback). --}}
<form class="report-controls" method="GET" action="{{ route('admin.reports.pae') }}">
    <label class="field">
        <span>{{ __('app.report_date') }}</span>
        <input type="date" name="date" value="{{ $date }}" max="{{ \Illuminate\Support\Carbon::today()->toDateString() }}">
    </label>
    <label class="field">
        <span>{{ __('app.report_meal') }}</span>
        <select name="meal">
            <option value="lunch" @selected($meal === 'lunch')>{{ __('api.meal_lunch') }}</option>
            <option value="breakfast" @selected($meal === 'breakfast')>{{ __('api.meal_breakfast') }}</option>
        </select>
    </label>
    <label class="field">
        <span>{{ __('app.report_month') }}</span>
        <input type="month" name="month" value="{{ $month }}">
    </label>
    <button type="submit" class="btn primary">{{ __('app.report_apply') }}</button>

    <span class="report-exports">
        <a class="btn" href="{{ route('admin.reports.pae.export.pdf') }}?type=daily&date={{ $date }}">{{ __('app.export_pdf') }}</a>
        <a class="btn" href="{{ route('admin.reports.pae.export.pdf') }}?type=monthly&month={{ $month }}">{{ __('app.export_pdf_monthly') }}</a>
        <a class="btn" href="{{ route('admin.reports.pae.export.pdf') }}?type=missed&date={{ $date }}&meal={{ $meal }}">{{ __('app.export_pdf_missed') }}</a>
        <a class="btn" href="{{ route('admin.reports.pae.export.pdf') }}?type=flagged&from={{ \Illuminate\Support\Carbon::today()->subDays(13)->toDateString() }}&to={{ \Illuminate\Support\Carbon::today()->toDateString() }}">{{ __('app.export_pdf_flagged') }}</a>
        <a class="btn" href="{{ route('admin.reports.pae.export.csv') }}?type=daily&date={{ $date }}">CSV</a>
    </span>
</form>

{{-- KPI strip. --}}
<div class="stat-row">
    <x-stat :label="__('app.pae_breakfast')" :stat="number_format($daily['breakfast'])" icon="bakery_dining" />
    <x-stat :label="__('app.pae_lunch')" :stat="number_format($daily['lunch'])" icon="lunch_dining" />
    <x-stat :label="__('app.report_stat_total')" :stat="number_format($daily['total'])" icon="restaurant" />
    <x-stat :label="__('app.report_stat_missed')" :stat="number_format(count($missed))" icon="no_meals" />
    <x-stat :label="__('app.report_stat_flagged')" :stat="number_format($flagged['total'])" icon="block" />
</div>

{{-- Served meals: 14-day trend chart. --}}
<x-panel :label="__('app.report_trend_14d')" rule>
    <svg class="report-chart" viewBox="0 0 {{ $trendWidth }} {{ $baseline + 14 }}" role="img"
         aria-label="{{ __('app.report_trend_14d') }}" preserveAspectRatio="xMidYMid meet">
        @foreach($trend as $i => $day)
            @php
                $x0 = $i * $groupW + 5;
                $bh = (int) round($day['breakfast'] / $trendMax * $chartH);
                $lh = (int) round($day['lunch'] / $trendMax * $chartH);
            @endphp
            <rect x="{{ $x0 }}" y="{{ $baseline - $bh }}" width="{{ $barW }}" height="{{ max(1, $bh) }}"
                  rx="2" class="bar-breakfast">
                <title>{{ $day['date'] }} — {{ __('api.meal_breakfast') }}: {{ $day['breakfast'] }}</title>
            </rect>
            <rect x="{{ $x0 + $barW + 3 }}" y="{{ $baseline - $lh }}" width="{{ $barW }}" height="{{ max(1, $lh) }}"
                  rx="2" class="bar-lunch">
                <title>{{ $day['date'] }} — {{ __('api.meal_lunch') }}: {{ $day['lunch'] }}</title>
            </rect>
            @if($i % 2 === 0)
                <text x="{{ $x0 + $barW }}" y="{{ $baseline + 12 }}" text-anchor="middle" class="chart-label">
                    {{ substr($day['date'], 5) }}
                </text>
            @endif
        @endforeach
        <line x1="0" y1="{{ $baseline }}" x2="{{ $trendWidth }}" y2="{{ $baseline }}" class="chart-axis" />
    </svg>
    <p class="chart-legend muted small">
        <span class="legend-swatch bar-breakfast"></span> {{ __('api.meal_breakfast') }}
        <span class="legend-swatch bar-lunch sp-l-md"></span> {{ __('api.meal_lunch') }}
    </p>
</x-panel>

<div class="report-cols">
    {{-- Monthly totals. --}}
    <x-panel :label="__('app.report_monthly_title', ['month' => $month])" rule>
        <div class="stat-row stat-row-compact">
            <x-stat :label="__('api.meal_breakfast')" :stat="number_format($monthly['totals']['breakfast'])" />
            <x-stat :label="__('api.meal_lunch')" :stat="number_format($monthly['totals']['lunch'])" />
            <x-stat :label="__('app.report_stat_total')" :stat="number_format($monthly['totals']['total'])" />
        </div>
        <a class="btn sp-t-sm" href="{{ route('admin.reports.pae.export.csv') }}?type=monthly&month={{ $month }}">{{ __('app.export_csv_monthly') }}</a>
    </x-panel>

    {{-- Enrollment summary. --}}
    <x-panel :label="__('app.report_enrollment')" rule>
        <dl class="report-dl">
            <div><dt>{{ __('app.report_enr_breakfast') }}</dt><dd>{{ number_format($enrollment['breakfast']) }}</dd></div>
            <div><dt>{{ __('app.report_enr_lunch') }}</dt><dd>{{ number_format($enrollment['lunch']) }}</dd></div>
            <div><dt>{{ __('app.report_enr_both') }}</dt><dd>{{ number_format($enrollment['both']) }}</dd></div>
            <div><dt>{{ __('app.report_enr_breakfast_only') }}</dt><dd>{{ number_format($enrollment['breakfast_only']) }}</dd></div>
            <div><dt>{{ __('app.report_enr_lunch_only') }}</dt><dd>{{ number_format($enrollment['lunch_only']) }}</dd></div>
            <div><dt>{{ __('app.report_enr_neither') }}</dt><dd>{{ number_format($enrollment['neither']) }}</dd></div>
            <div><dt>{{ __('app.report_enr_total') }}</dt><dd>{{ number_format($enrollment['total_students']) }}</dd></div>
        </dl>
    </x-panel>
</div>

<div class="report-cols">
    {{-- Missed meals (selected date + meal). --}}
    <x-panel :label="__('app.report_missed_title', ['meal' => __('api.meal_'.$meal)])" rule>
        <p class="muted small">{{ __('app.report_missed_hint', ['date' => $date]) }}</p>
        <div class="ledger-wrap">
            <table class="ledger-table">
                <thead>
                <tr>
                    <th>{{ __('app.report_col_student') }}</th>
                    <th>{{ __('app.report_col_class') }}</th>
                    <th>{{ __('app.report_col_attended') }}</th>
                </tr>
                </thead>
                <tbody>
                @forelse($missed as $row)
                    <tr>
                        <td data-label="{{ __('app.report_col_student') }}"><a href="{{ route('admin.reports.pae.student', $row['id']) }}">{{ $row['name'] }}</a></td>
                        <td data-label="{{ __('app.report_col_class') }}">{{ $row['class_name'] ?? '—' }}</td>
                        <td data-label="{{ __('app.report_col_attended') }}">{{ $row['attended_at'] ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="muted">{{ __('app.report_none') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        {{-- Missed-meal trend (bars). --}}
        <svg class="report-chart sp-t-sm" viewBox="0 0 {{ $missedWidth }} 80" role="img"
             aria-label="{{ __('app.report_missed_trend') }}">
            @foreach($missedTrend as $i => $row)
                @php($mh = (int) round($row['missed'] / $missedMax * 50))
                <rect x="{{ $i * 24 + 4 }}" y="{{ 58 - max(1, $mh) }}" width="16" height="{{ max(1, $mh) }}" rx="2" class="bar-missed">
                    <title>{{ $row['date'] }} — {{ $row['missed'] }}</title>
                </rect>
                @if($i % 2 === 0)
                    <text x="{{ $i * 24 + 12 }}" y="74" text-anchor="middle" class="chart-label">{{ substr($row['date'], 8) }}</text>
                @endif
            @endforeach
            <line x1="0" y1="58" x2="{{ $missedWidth }}" y2="58" class="chart-axis" />
        </svg>
    </x-panel>

    {{-- Flagged (excluded) attempts. --}}
    <x-panel :label="__('app.report_flagged_title') . ' · ' . __('app.report_14d')" rule>
        <p class="muted small">{{ __('app.report_flagged_hint') }}</p>
        @if($flagged['by_reason'] !== [])
            <ul class="report-reasons">
                @foreach($flagged['by_reason'] as $reason => $count)
                    <li>
                        <span>{{ __('api.pae_reason_'.$reason) }}</span>
                        <strong>{{ number_format($count) }}</strong>
                    </li>
                @endforeach
            </ul>
        @else
            <p class="muted">{{ __('app.report_none') }}</p>
        @endif
        <div class="ledger-wrap sp-t-sm">
            <table class="ledger-table">
                <thead>
                <tr>
                    <th>{{ __('app.report_col_time') }}</th>
                    <th>{{ __('app.report_col_student') }}</th>
                    <th>{{ __('app.report_col_meal') }}</th>
                    <th>{{ __('app.report_col_reason') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach(array_slice($flagged['rows'], 0, 10) as $row)
                    <tr>
                        <td data-label="{{ __('app.report_col_time') }}">{{ substr($row['occurred_at'], 11, 5) }}</td>
                        <td data-label="{{ __('app.report_col_student') }}">{{ $row['student_name'] ?? '—' }}</td>
                        <td data-label="{{ __('app.report_col_meal') }}">{{ $row['meal'] ? __('api.meal_'.$row['meal']) : __('app.event_type_PAE_ATTEMPT') }}</td>
                        <td data-label="{{ __('app.report_col_reason') }}">{{ __('api.pae_reason_'.$row['reason']) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </x-panel>
</div>

{{-- Per-student entry point. --}}
<x-panel :label="__('app.report_student_pick')" rule>
    <form class="report-student-pick" method="GET" action="{{ route('admin.reports.pae') }}">
        <label class="field">
            <span>{{ __('app.report_col_student') }}</span>
            <select name="__student" id="report-student" data-base="{{ route('admin.reports.pae.student', ['student' => '__ID__']) }}">
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
