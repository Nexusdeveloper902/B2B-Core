{{--
    TASK-025 item 5 (spec §30) — the student's reward catalog view:
    cost, type, stock (and whether their own balance covers it), plus
    their own recent redemptions. Redemption itself stays a DESK
    interaction (staff-verified) — students browse here, redeem at the
    desk; the server-side balance check is PointsService's job.
--}}
@extends('layouts.app')

@section('title', __('app.student_rewards_title'))

@section('content')
    <header class="page-head">
        <h1>{{ __('app.student_rewards_title') }}</h1>
        <p class="muted">{{ __('app.student_rewards_intro') }}</p>
    </header>

    <div class="stat-strip stat-strip-2">
        <x-stat :label="__('app.student_points_balance')" icon="✦">{{ $balance }}</x-stat>
    </div>

    <x-panel :label="__('app.student_rewards_title')">
        <table class="ledger-table" data-stack>
            <thead>
                <tr>
                    <th scope="col">{{ __('app.students') }}</th>
                    <th scope="col">{{ __('app.student_reward_type') }}</th>
                    <th scope="col">{{ __('app.student_reward_cost') }}</th>
                    <th scope="col">{{ __('app.student_reward_stock') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rewards as $reward)
                    <tr class="{{ $reward->point_cost <= $balance ? 'is-affordable' : '' }}">
                        <td data-label="{{ __('app.students') }}">
                            {{ $reward->name }}
                            @if($reward->point_cost <= $balance && $reward->active)
                                <span class="live-chip" data-event-type="RECYCLING_DEPOSIT">{{ __('app.student_affordable') }}</span>
                            @endif
                        </td>
                        <td data-label="{{ __('app.student_reward_type') }}">{{ $reward->type }}</td>
                        <td data-label="{{ __('app.student_reward_cost') }}">{{ $reward->point_cost }}</td>
                        <td data-label="{{ __('app.student_reward_stock') }}">
                            @if(! $reward->active)
                                —
                            @elseif($reward->stock === null)
                                {{ __('app.student_reward_stock_unlimited') }}
                            @elseif($reward->stock <= 0)
                                {{ __('app.student_reward_stock_none') }}
                            @else
                                {{ $reward->stock }}
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-panel>

    <x-panel :label="__('app.student_redemptions')">
        <table class="ledger-table" data-stack>
            <thead>
                <tr>
                    <th scope="col">{{ __('app.student_ledger_when') }}</th>
                    <th scope="col">{{ __('app.students') }}</th>
                    <th scope="col">{{ __('app.student_reward_cost') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($redemptions as $redemption)
                    <tr>
                        <td data-label="{{ __('app.student_ledger_when') }}">{{ $redemption->created_at?->format('Y-m-d H:i') }}</td>
                        <td data-label="{{ __('app.students') }}">{{ $redemption->reward->name }}</td>
                        <td data-label="{{ __('app.student_reward_cost') }}">-{{ $redemption->points_spent }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3">{{ __('app.student_no_redemptions') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-panel>
@endsection
