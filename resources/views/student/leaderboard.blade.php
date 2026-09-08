{{--
    TASK-026 — mockup "Leaderboard & Class Standings": podium top-3, the
    full school board (rank / name / points), and class standings derived
    from the board itself. All data comes from LeaderboardService (the
    same source as the API + the student desk).

    Mockup parts with no backing functionality are omitted and
    documented in docs/FRONTEND.md: season tabs / historic seasons and
    the homeroom pizza-challenge milestone hero (gap #L1 — no season or
    campaign model exists), inter-section challenge tab (same gap),
    grade filtering (gap #L2 — no grade dimension on classes).
--}}
@extends('layouts.app')

@section('title', __('app.student_standings'))

@section('content')
<div class="lede">
    <span class="kicker">{{ __('app.student_standings') }}</span>
    <h1>{{ __('app.leaderboard_title') }}</h1>
    <p class="lede-sub">{{ __('app.leaderboard_sub') }}</p>
</div>

{{-- My standing (real rank + balance) --}}
<div class="stat-strip stat-strip-2" data-reveal-stagger>
    <x-stat :label="__('app.student_rank')" stat="rank">
        <x-slot:icon>
            <span class="material-symbols-outlined is-16" aria-hidden="true">leaderboard</span>
        </x-slot:icon>
        {{ $myRank['rank'] ?? '—' }}
    </x-stat>
    <x-stat :label="__('app.student_points_balance')" stat="balance">
        <x-slot:icon>
            <span class="material-symbols-outlined is-16" aria-hidden="true">eco</span>
        </x-slot:icon>
        {{ $balance }}
    </x-stat>
</div>

{{-- Podium top-3 (real competition ranks) --}}
@if(count($top3) > 0)
    <section class="podium" data-reveal-stagger aria-label="{{ __('app.leaderboard_top3') }}">
        @foreach($top3 as $i => $entry)
            <div class="podium-card {{ $i === 0 ? 'is-first' : '' }}" data-podium-student="{{ $entry['student_id'] }}">
                <span class="podium-rank">#{{ $entry['rank'] }}</span>
                <span class="podium-name">{{ $entry['student_name'] }}</span>
                <span class="podium-points"><span class="podium-pts-value">{{ $entry['points'] }}</span> PTS · {{ $entry['class_name'] ?? '—' }}</span>
            </div>
        @endforeach
    </section>
@endif

<div class="grid-2 grid-2-wide-left" data-reveal>
    <x-panel :label="__('app.leaderboard_board')" rule>
        <ol class="board-list">
            @forelse($board as $entry)
                <li class="board-row {{ $entry['student_id'] === $student->id ? 'is-me' : '' }}" data-board-student="{{ $entry['student_id'] }}">
                    <span class="board-rank">{{ $entry['rank'] }}</span>
                    <span class="board-name">{{ $entry['student_name'] }}<span class="muted"> · {{ $entry['class_name'] ?? '—' }}</span></span>
                    <span class="board-points">{{ $entry['points'] }}</span>
                </li>
            @empty
                <li class="activity-row live-empty">{{ __('app.student_no_activity') }}</li>
            @endforelse
        </ol>
    </x-panel>

    <x-panel :label="__('app.leaderboard_classes')">
        <ul class="rates-list">
            @forelse($classStandings as $standing)
                <li>
                    <span class="rate-name"><span class="dot" aria-hidden="true"></span>{{ $standing['class_name'] }}</span>
                    <span class="rate-value">{{ $standing['points'] }} PTS · {{ $standing['students'] }} {{ __('app.leaderboard_students') }}</span>
                </li>
            @empty
                <li class="muted">{{ __('app.leaderboard_no_classes') }}</li>
            @endforelse
        </ul>
    </x-panel>
</div>

{{-- TASK-029 — the board is LIVE: points_awarded / reward_redeemed
      frames update the named student's points, then the board (and the
      podium) re-sorts with the SAME deterministic rule the server uses
      (points DESC, student_id ASC) and ranks renumber — a client
      re-sort of server-provided numbers, never invented standings. The
      class-standings panel stays a snapshot (its per-class sums would
      need the frame to carry class aggregates; documented, not
      pretended). --}}
<div id="leaderboard-realtime" hidden data-realtime="{{ json_encode([
    'token' => $realtimeToken,
    'expires_at' => $realtimeTokenExpires,
    'port' => (int) config('realtime.port'),
    'max_rows' => (int) config('realtime.history_limit'),
]) }}"></div>
<script src="{{ asset('js/realtime.js') }}"></script>
<script>
    (function () {
        function setPoints(row, points) {
            var value = row.querySelector('.board-points, .podium-pts-value');
            if (value) { value.textContent = points; }
        }

        function resort(list, rowSelector, rankSelector, useHash) {
            var rows = Array.prototype.slice.call(list.querySelectorAll(rowSelector));
            rows.sort(function (a, b) {
                var pa = parseInt((a.querySelector('.board-points, .podium-pts-value') || {}).textContent, 10) || 0;
                var pb = parseInt((b.querySelector('.board-points, .podium-pts-value') || {}).textContent, 10) || 0;
                if (pb !== pa) { return pb - pa; }
                return parseInt(a.dataset.boardStudent || a.dataset.podiumStudent, 10)
                    - parseInt(b.dataset.boardStudent || b.dataset.podiumStudent, 10);
            });
            rows.forEach(function (row, i) {
                list.appendChild(row);
                var rank = row.querySelector(rankSelector);
                if (rank) { rank.textContent = (useHash ? '#' : '') + (i + 1); }
            });
        }

        document.addEventListener('realtime:recycling', function (e) {
            var update = e.detail || {};
            var p = update.payload || {};
            if (update.type !== 'points_awarded' && update.type !== 'reward_redeemed') { return; }
            if (p.student_id === undefined || typeof p.new_balance !== 'number') { return; }

            var boardRow = document.querySelector('[data-board-student="' + p.student_id + '"]');
            if (boardRow) {
                setPoints(boardRow, p.new_balance);
                boardRow.classList.remove('js-row-flash');
                void boardRow.offsetWidth;
                boardRow.classList.add('js-row-flash');
            }
            var podiumRow = document.querySelector('[data-podium-student="' + p.student_id + '"]');
            if (podiumRow) { setPoints(podiumRow, p.new_balance); }

            var board = document.querySelector('.board-list');
            if (board) {
                resort(board, '.board-row', '.board-rank', false);
                var me = board.querySelector('.board-row.is-me');
                var rankStat = document.querySelector('[data-stat="rank"] .stat-value');
                if (me && rankStat) {
                    rankStat.textContent = Array.prototype.indexOf.call(board.children, me) + 1;
                }
            }
            var podium = document.querySelector('.podium');
            if (podium) { resort(podium, '.podium-card', '.podium-rank', true); }

            // MY balance moves only when the frame names me.
            var meRow = document.querySelector('.board-row.is-me');
            if (meRow && Number(meRow.dataset.boardStudent) === Number(p.student_id)) {
                var balanceStat = document.querySelector('[data-stat="balance"] .stat-value');
                if (balanceStat) { balanceStat.textContent = p.new_balance; }
            }
        });
    })();
</script>
@endsection
