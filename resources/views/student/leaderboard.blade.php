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
    <x-stat :label="__('app.student_rank')">
        <x-slot:icon>
            <span class="material-symbols-outlined is-16" aria-hidden="true">leaderboard</span>
        </x-slot:icon>
        {{ $myRank['rank'] ?? '—' }}
    </x-stat>
    <x-stat :label="__('app.student_points_balance')">
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
            <div class="podium-card {{ $i === 0 ? 'is-first' : '' }}">
                <span class="podium-rank">#{{ $entry['rank'] }}</span>
                <span class="podium-name">{{ $entry['student_name'] }}</span>
                <span class="podium-points">{{ $entry['points'] }} PTS · {{ $entry['class_name'] ?? '—' }}</span>
            </div>
        @endforeach
    </section>
@endif

<div class="grid-2 grid-2-wide-left" data-reveal>
    <x-panel :label="__('app.leaderboard_board')" rule>
        <ol class="board-list">
            @forelse($board as $entry)
                <li class="board-row {{ $entry['student_id'] === $student->id ? 'is-me' : '' }}">
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
@endsection
