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
    <p class="lede-sub">{{ $student->name }} · {{ __('app.student_points_balance') }}:
        <span data-balance>{{ $balance }}</span></p>
</div>

<x-panel :label="__('app.student_ledger_running')" rule data-reveal>
    @php
        // Chronological pass computes the running balance; the
        // display order stays newest-first (page renders top-down).
        //
        // The accumulator is seeded with the balance CARRIED INTO this
        // page (all deltas older than the page's oldest row) — starting
        // at 0 would show page-local sums on every page after the first,
        // contradicting the true balance in the header. Empty pages skip
        // the query entirely (min() of nothing is null).
        $chronological = $ledger->getCollection()->sortBy('id')->values();
        $running = [];
        $acc = $chronological->isEmpty()
            ? 0
            : (int) $student->pointsLedger()->where('id', '<', $chronological->min('id'))->sum('delta');
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
            <tbody data-ledger-body data-student-live="{{ $student->id }}"
                   data-reason-deposit="{{ __('app.student_ledger_reason_recycling_deposit') }}"
                   data-reason-redemption="{{ __('app.student_ledger_reason_redemption') }}">
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

{{-- TASK-029 — the ledger is LIVE: committed points_awarded / reward_
      redeemed frames (recycling channel; school-wide on the wire, so
      the handler filters to THIS student) prepend rows with the true
      running balance from the frame — never a client guess. --}}
<div id="history-realtime" hidden data-realtime="{{ json_encode([
    'token' => $realtimeToken,
    'expires_at' => $realtimeTokenExpires,
    'port' => (int) config('realtime.port'),
    'max_rows' => (int) config('realtime.history_limit'),
]) }}"></div>
<script src="{{ asset('js/realtime.js') }}"></script>
<script>
    (function () {
        var body = document.querySelector('[data-ledger-body]');
        if (!body) { return; }
        var studentId = Number(body.dataset.studentLive);

        document.addEventListener('realtime:recycling', function (e) {
            var update = e.detail || {};
            var p = update.payload || {};
            if (Number(p.student_id) !== studentId) { return; }

            var delta, reason;
            if (update.type === 'points_awarded' && typeof p.points === 'number') {
                delta = p.points;
                reason = body.dataset.reasonDeposit;
            } else if (update.type === 'reward_redeemed' && typeof p.points_spent === 'number') {
                delta = -p.points_spent;
                reason = body.dataset.reasonRedemption;
            } else {
                return;
            }

            // Mobile stacked tables label each cell via data-label
            // (CSS attr()); reuse the SSR headers so live rows match.
            var headers = body.closest('table').querySelectorAll('thead th');
            function label(i) { return headers[i] ? headers[i].textContent : ''; }

            var tr = document.createElement('tr');
            tr.className = 'js-row-flash';

            var when = document.createElement('td');
            when.className = 'num mono';
            when.setAttribute('data-label', label(0));
            when.textContent = String(update.at || '').replace('T', ' ').slice(0, 16);
            tr.appendChild(when);

            var change = document.createElement('td');
            change.setAttribute('data-label', label(1));
            var text = document.createElement('span');
            text.textContent = reason || '';
            change.appendChild(text);
            var badge = document.createElement('span');
            badge.className = 'points-badge' + (delta < 0 ? ' is-negative' : '');
            badge.textContent = (delta >= 0 ? '+' : '') + delta;
            change.appendChild(document.createTextNode(' '));
            change.appendChild(badge);
            tr.appendChild(change);

            var balance = document.createElement('td');
            balance.className = 'num';
            balance.setAttribute('data-label', label(2));
            if (typeof p.new_balance === 'number') {
                balance.textContent = p.new_balance;
                var lede = document.querySelector('[data-balance]');
                if (lede) { lede.textContent = p.new_balance; }
            }
            tr.appendChild(balance);

            var empty = body.querySelector('tr td[colspan]');
            if (empty) { empty.closest('tr').remove(); }
            body.insertBefore(tr, body.firstChild);
        });
    })();
</script>
@endsection
