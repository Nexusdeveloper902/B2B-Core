{{--
    TASK-025 item 5 (spec §12) — the student's own full points history,
    with a running balance column. Scoped server-side to the
    authenticated account's student row only.
--}}
@extends('layouts.app')

@section('title', __('app.student_history_title'))

@section('content')
    <header class="page-head">
        <h1>{{ __('app.student_history_title') }}</h1>
        <p class="muted">{{ $student->name }} · {{ __('app.student_points_balance') }}: {{ $balance }}</p>
    </header>

    <x-panel :label="__('app.student_ledger_running')">
        @php
            // Chronological pass computes the running balance; the
            // display order stays newest-first (page renders top-down).
            $chronological = $ledger->getCollection()->sortBy('id')->values();
            $running = [];
            $acc = 0;
            foreach ($chronological as $row) {
                $acc += $row->delta;
                $running[$row->id] = $acc;
            }
        @endphp
        <table class="ledger-table" data-stack>
            <thead>
                <tr>
                    <th scope="col">{{ __('app.student_ledger_when') }}</th>
                    <th scope="col">{{ __('app.student_ledger_change') }}</th>
                    <th scope="col">{{ __('app.student_ledger_balance') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($chronological->reverse() as $row)
                    <tr>
                        <td data-label="{{ __('app.student_ledger_when') }}">{{ $row->created_at?->format('Y-m-d H:i') }}</td>
                        <td data-label="{{ __('app.student_ledger_change') }}">{{ __('app.student_ledger_reason_'.$row->reason) }} ({{ $row->delta >= 0 ? '+' : '' }}{{ $row->delta }})</td>
                        <td data-label="{{ __('app.student_ledger_balance') }}">{{ $running[$row->id] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3">{{ __('app.student_no_activity') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-panel>

    {{ $ledger->links() }}
@endsection
