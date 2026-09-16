{{--
    The per-student recycling report: earn/spend totals with the
    per-deposit history. Shares
    RecyclingReportService::studentHistory — one truth, one surface
    plus its CSV/PDF exports.
--}}
@extends('layouts.app')

@section('title', __('app.report_recycling_student_doc_title', ['name' => $student->name]))

@section('content')
<div class="page-head">
    <h1 class="page-title">{{ $student->name }}</h1>
    <p class="lede muted">{{ $student->schoolClass?->name }} · {{ __('app.reports_recycling') }}</p>
</div>

<div class="report-student-badges">
    <span class="live-chip">{{ __('app.report_stat_items') }}: {{ $history['totals']['items'] }}</span>
    <span class="live-chip">{{ __('app.report_stat_points') }}: {{ $history['totals']['points'] }}</span>
    <span class="live-chip">{{ __('app.report_stat_redeemed') }}: {{ $history['totals']['redeemed'] }}</span>
    <span class="report-exports">
        <a class="btn" href="{{ route('admin.reports.recycling.student.export.pdf', $student) }}">{{ __('app.export_pdf') }}</a>
        <a class="btn" href="{{ route('admin.reports.recycling.student.export.csv', $student) }}">CSV</a>
        <a class="btn" href="{{ route('parent.timeline', $student) }}">{{ __('app.view_parent') }}</a>
    </span>
</div>

<div class="ledger-wrap">
    <table class="ledger-table">
        <thead>
        <tr>
            <th>{{ __('app.report_col_date') }}</th>
            <th>{{ __('app.material') }}</th>
            <th>{{ __('app.report_col_points') }}</th>
            <th>{{ __('app.reader') }}</th>
        </tr>
        </thead>
        <tbody>
        @forelse($history['deposits'] as $row)
            <tr>
                <td data-label="{{ __('app.report_col_date') }}">{{ $row['date'] }} {{ $row['time'] }}</td>
                <td data-label="{{ __('app.material') }}">{{ __('app.material_'.$row['material']) }}</td>
                <td data-label="{{ __('app.report_col_points') }}">+{{ $row['points'] }}</td>
                <td data-label="{{ __('app.reader') }}">{{ $row['reader'] ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="muted">{{ __('app.report_none') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<p class="sp-t-md">
    <a class="btn" href="{{ route('admin.reports.recycling') }}">{{ __('app.report_back') }}</a>
</p>
@endsection
