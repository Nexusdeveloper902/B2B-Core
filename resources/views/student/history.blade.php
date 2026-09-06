{{--
    TASK-025 item 5 (spec §12) — the student's own full points history,
    with a running balance column. Scoped server-side to the
    authenticated account's student row only.

    TASK-026 — mockup ledger styling; the running-balance computation
    and pagination are unchanged.
--}}
@extends('layouts.app')

@section('title', __('app.student_history_title'))

@section('content')
<div class="lede">
    <span class="kicker">{{ __('app.student_history') }}</span>
    <h1>{{ __('app.student_history_title') }}</h1>
    <p class="lede-sub">{{ $student->name }} · {{ __('app.student_points_balance') }}: {{ $balance }}</p>
</div>

<x-panel :label="__('app.student_ledger_running')" rule data-reveal>
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
    <div class="ledger-wrap">
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
                    <td class="num mono" data-label="{{ __('app.student_ledger_when') }}">{{ $row->created_at?->format('Y-m-d H:i') }}</td>
                    <td data-label="{{ __('app.student_ledger_change') }}">
                        {{ __('app.student_ledger_reason_'.$row->reason) }}
                        <span class="points-badge {{ $row->delta < 0 ? 'is-negative' : '' }}">{{ $row->delta >= 0 ? '+' : '' }}{{ $row->delta }}</span>
                    </td>
                    <td class="num" data-label="{{ __('app.student_ledger_balance') }}">{{ $running[$row->id] }}</td>
                </tr>
            @empty
                <tr><td colspan="3">{{ __('app.student_no_activity') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-panel>

{{ $ledger->links() }}
@endsection
