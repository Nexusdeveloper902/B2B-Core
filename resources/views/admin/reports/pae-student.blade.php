{{--
    TASK-037 — the per-student PAE report (ADR-056): one student's meal
    history with graphics. Shares PaeReportService::studentHistory with
    the NL get_student_pae_history function — one truth, two surfaces.
--}}
@extends('layouts.app')

@section('title', __('app.report_student_doc_title', ['name' => $student->name]))

@php
    $historyMax = 1;
    foreach ($history['days'] as $day) {
        $historyMax = max($historyMax, ($day['breakfast'] ? 1 : 0) + ($day['lunch'] ? 1 : 0));
    }
    $slice = array_slice($history['days'], 0, 30);
@endphp

@section('content')
<div class="page-head">
    <h1 class="page-title">{{ $student->name }}</h1>
    <p class="lede muted">{{ $student->schoolClass?->name }} · {{ __('app.report_student_doc_title', ['name' => '']) }}</p>
</div>

<div class="report-student-badges">
    <span class="live-chip" data-event-type="PAE_BREAKFAST">{{ __('app.pae_breakfast') }}: {{ $history['student']['breakfast_enrolled'] ? '✓' : '—' }}</span>
    <span class="live-chip" data-event-type="PAE_LUNCH">{{ __('app.pae_lunch') }}: {{ $history['student']['lunch_enrolled'] ? '✓' : '—' }}</span>
    <span class="report-exports">
        <a class="btn" href="{{ route('admin.reports.pae.student.export.pdf', $student) }}">{{ __('app.export_pdf') }}</a>
        <a class="btn" href="{{ route('admin.reports.pae.student.export.csv', $student) }}">CSV</a>
        <a class="btn" href="{{ route('parent.timeline', $student) }}">{{ __('app.parent_view') }}</a>
    </span>
</div>

<div class="stat-row">
    <x-stat :label="__('app.report_stat_breakfasts')" :stat="number_format($history['totals']['breakfasts'])" icon="bakery_dining" />
    <x-stat :label="__('app.report_stat_lunches')" :stat="number_format($history['totals']['lunches'])" icon="lunch_dining" />
    <x-stat :label="__('app.report_stat_flagged')" :stat="number_format($history['totals']['flagged'])" icon="block" />
</div>

{{-- Participation strip: one column per school day with a meal; green
     marks served, amber marks a flagged attempt — the graphical view of
     the table below. --}}
<x-panel :label="__('app.report_student_history_title')" rule>
    @if(count($slice) > 0)
        <div class="report-participation" role="img" aria-label="{{ __('app.report_student_history_title') }}">
            @foreach($slice as $day)
                @php
                    $b = $day['breakfast'];
                    $l = $day['lunch'];
                    $bClass = $b ? ($b['status'] === 'served' ? 'is-served' : 'is-flagged') : 'is-empty';
                    $lClass = $l ? ($l['status'] === 'served' ? 'is-served' : 'is-flagged') : 'is-empty';
                    $title = $day['date'] . ' — ' . ($b ? __('api.meal_breakfast') . ': ' . $b['time'] . ' (' . $b['status'] . ($b['reason'] ? ', ' . __('api.pae_reason_'.$b['reason']) : '') . ')' : __('api.meal_breakfast') . ': —') . ' · ' . ($l ? __('api.meal_lunch') . ': ' . $l['time'] . ' (' . $l['status'] . ($l['reason'] ? ', ' . __('api.pae_reason_'.$l['reason']) : '') . ')' : __('api.meal_lunch') . ': —');
                @endphp
                <span class="participation-day" title="{{ $title }}">
                    <span class="participation-cell {{ $bClass }}">{{ $b ? $b['time'] : '' }}</span>
                    <span class="participation-cell {{ $lClass }}">{{ $l ? $l['time'] : '' }}</span>
                    <span class="participation-date">{{ substr($day['date'], 5) }}</span>
                </span>
            @endforeach
        </div>
        <p class="chart-legend muted small">
            <span class="legend-swatch is-served"></span> {{ __('app.report_served') }}
            <span class="legend-swatch is-flagged sp-l-md"></span> {{ __('app.report_flagged_short') }}
            <span class="legend-swatch is-empty sp-l-md"></span> {{ __('app.report_no_meal') }}
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
            <th>{{ __('api.meal_breakfast') }}</th>
            <th>{{ __('api.meal_lunch') }}</th>
        </tr>
        </thead>
        <tbody>
        @forelse($history['days'] as $day)
            <tr>
                <td data-label="{{ __('app.report_col_date') }}">{{ $day['date'] }}</td>
                <td data-label="{{ __('api.meal_breakfast') }}">
                    @if($day['breakfast'])
                        <span class="live-chip @if($day['breakfast']['status'] !== 'served') is-flagged @endif" data-event-type="PAE_BREAKFAST">
                            {{ $day['breakfast']['time'] }} · {{ $day['breakfast']['status'] === 'served' ? __('app.report_served') : __('api.pae_reason_'.$day['breakfast']['reason']) }}
                        </span>
                    @else
                        <span class="muted">—</span>
                    @endif
                </td>
                <td data-label="{{ __('api.meal_lunch') }}">
                    @if($day['lunch'])
                        <span class="live-chip @if($day['lunch']['status'] !== 'served') is-flagged @endif" data-event-type="PAE_LUNCH">
                            {{ $day['lunch']['time'] }} · {{ $day['lunch']['status'] === 'served' ? __('app.report_served') : __('api.pae_reason_'.$day['lunch']['reason']) }}
                        </span>
                    @else
                        <span class="muted">—</span>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="3" class="muted">{{ __('app.report_none') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<p class="sp-t-md">
    <a class="btn" href="{{ route('admin.reports.pae') }}">{{ __('app.report_back') }}</a>
</p>
@endsection
