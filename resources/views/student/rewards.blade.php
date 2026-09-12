{{--
    TASK-025 item 5 (spec §30) — the student's reward catalog view:
    cost, type, stock (and whether their own balance covers it), plus
    their own recent redemptions. Redemption itself stays a DESK
    interaction (staff-verified) — students browse here, redeem at the
    desk; the server-side balance check is PointsService's job.

    TASK-026 — mockup "Rewards & Perks Store": card grid with type ref,
    price chip, stock meta, affordability state and goal progress (real
    math: balance / cost). Mockup parts with no backing functionality
    are omitted and documented in docs/FRONTEND.md: category filters
    beyond the reward's real type (gap #R2), search/sort (gap #R3),
    voucher/QR redemption buttons + barcode modal (gap #R1 — the
    student role cannot redeem by design), location/hours metadata
    (gap #R4), "Add to Wallet" (gap #R1).
--}}
@extends('layouts.app')

@section('title', __('app.student_rewards_title'))

@section('content')
<div class="lede">
    <span class="kicker">{{ __('app.student_rewards') }}</span>
    <h1>{{ __('app.student_rewards_title') }}</h1>
    <p class="lede-sub">{{ __('app.student_rewards_intro') }}</p>
</div>

<div class="stat-strip stat-strip-2" data-reveal-stagger>
    <x-stat :label="__('app.student_points_balance')">
        <x-slot:icon>
            <span class="material-symbols-outlined is-16" aria-hidden="true">account_balance_wallet</span>
        </x-slot:icon>
        {{ $balance }}
    </x-stat>
</div>

<section class="reward-grid" data-reveal aria-label="{{ __('app.student_rewards_title') }}">
    @forelse($rewards as $reward)
        @php($affordable = $reward->point_cost <= $balance && $reward->active && ($reward->stock === null || $reward->stock > 0))
        <div class="reward-card {{ $affordable ? '' : 'is-locked' }}">
            <div class="reward-head">
                <span class="reward-ref">{{ $reward->type }}</span>
                <span class="reward-price">
                    <span class="material-symbols-outlined is-14" aria-hidden="true">toll</span>
                    {{ $reward->point_cost }}
                </span>
            </div>
            <h2 class="reward-title">{{ $reward->name }}</h2>

            <div class="reward-meta">
                <span class="reward-meta-icon" aria-hidden="true">
                    <span class="material-symbols-outlined is-20">{{ $affordable ? 'check_circle' : 'lock' }}</span>
                </span>
                <span class="min-w-0">
                    <span class="reward-meta-label">{{ __('app.student_reward_stock') }}</span>
                    <span class="reward-meta-value">
                        @if(! $reward->active)
                            {{ __('app.student_reward_inactive') }}
                        @elseif($reward->stock === null)
                            {{ __('app.student_reward_stock_unlimited') }}
                        @elseif($reward->stock <= 0)
                            {{ __('app.student_reward_stock_none') }}
                        @else
                            {{ $reward->stock }}
                        @endif
                    </span>
                </span>
            </div>

            @if($affordable)
                <div class="reward-foot">
                    <div class="reward-afford is-yes">
                        <span><span class="afford-dot" aria-hidden="true"></span>{{ __('app.student_affordable') }}</span>
                        <span>{{ __('app.balance_after') }}: {{ $balance - $reward->point_cost }}</span>
                    </div>
                    <div class="reward-note is-yes">
                        <span class="material-symbols-outlined is-16" aria-hidden="true">storefront</span>
                        {{ __('app.student_redeem_at_desk') }}
                    </div>
                </div>
            @else
                <div class="reward-foot">
                    <div class="meter on-light">
                        <div class="meter-row">
                            <span>{{ __('app.goal_progress') }}</span>
                            <span><strong>{{ $balance }}</strong> / {{ $reward->point_cost }} {{ __('app.points_unit') }}</span>
                        </div>
                        <div class="meter-track">
                            <div class="meter-fill" style="width: {{ min(100, (int) round($balance * 100 / max(1, $reward->point_cost))) }}%;"></div>
                        </div>
                    </div>
                    <div class="reward-note">
                        <span class="material-symbols-outlined is-16" aria-hidden="true">lock</span>
                        {{ __('app.student_goal_locked') }}
                    </div>
                </div>
            @endif
        </div>
    @empty
        <x-empty>{{ __('app.student_no_rewards') }}</x-empty>
    @endforelse
</section>

<x-panel :label="__('app.student_redemptions')" rule data-reveal>
    <div class="ledger-wrap">
        <table class="ledger-table" data-stack>
            <thead>
            <tr>
                <th scope="col">{{ __('app.student_ledger_when') }}</th>
                <th scope="col">{{ __('app.reward') }}</th>
                <th scope="col">{{ __('app.student_reward_cost') }}</th>
            </tr>
            </thead>
            <tbody>
            @forelse($redemptions as $redemption)
                <tr>
                    <td class="num mono" data-label="{{ __('app.student_ledger_when') }}">{{ $redemption->created_at?->format('Y-m-d H:i') }}</td>
                    <td data-label="{{ __('app.reward') }}">{{ $redemption->reward->name }}</td>
                    <td class="num" data-label="{{ __('app.student_reward_cost') }}">
                        <span class="points-badge is-negative">-{{ $redemption->points_spent }}</span>
                    </td>
                </tr>
            @empty
                <tr><td colspan="3">{{ __('app.student_no_redemptions') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-panel>
@endsection
