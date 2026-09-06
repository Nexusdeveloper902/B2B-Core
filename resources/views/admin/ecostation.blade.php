{{--
    TASK-026 — mockup "EcoStation & Recycling Hub": impact metrics row,
    the deposit ledger (event-spine truth: when / student / reader /
    material / confidence / points), the material rate card (config
    truth), and the recycling reader network. Everything is real data.

    Mockup parts with no backing functionality are omitted and
    documented in docs/FRONTEND.md: terminal telemetry pill, hardware
    firmware status snippet, compliance note box, interactive terminal
    simulation modal, cryptographic ledger footer, capture-image
    display (gap #E1 — images live on the private disk as audit
    artifacts; exposing them needs an authorized image route).
--}}
@extends('layouts.app')

@section('title', __('app.ecostation'))

@section('content')
<div class="lede">
    <span class="kicker">{{ __('app.ecostation') }}</span>
    <h1>{{ __('app.ecostation') }}</h1>
    <p class="lede-sub">{{ __('app.ecostation_sub') }}</p>
</div>

{{-- Impact metrics tonal row (all-time, ledger truth) --}}
<section class="bento" data-reveal-stagger aria-label="{{ __('app.ecostation_metrics') }}">
    <div class="metric">
        <div class="metric-head">
            {{ __('app.recycling_items') }}
            <span class="material-symbols-outlined is-20" aria-hidden="true">recycling</span>
        </div>
        <div><span class="metric-value">{{ $totalItems }}</span></div>
        <div class="metric-foot"><span class="dot" aria-hidden="true"></span>{{ __('app.ecostation_items_foot') }}</div>
    </div>
    <div class="metric">
        <div class="metric-head">
            {{ __('app.ecostation_materials') }}
            <span class="material-symbols-outlined is-20" aria-hidden="true">category</span>
        </div>
        <div><span class="metric-value">{{ $byMaterial->count() }}</span></div>
        <div class="metric-foot"><span class="dot" aria-hidden="true"></span>{{ __('app.ecostation_materials_foot') }}</div>
    </div>
    <div class="metric-hero">
        <div class="metric-head">
            {{ __('app.recycling_points') }}
            <span class="material-symbols-outlined is-20" aria-hidden="true">eco</span>
        </div>
        <div class="metric-value">{{ $totalPoints }}<span class="unit">PTS</span></div>
        <p class="metric-sub">{{ __('app.ecostation_points_sub') }}</p>
    </div>
</section>

<div class="grid-2 grid-2-wide-left" data-reveal>
    {{-- Hardware transaction ledger (~60%) --}}
    <x-panel :label="__('app.ecostation_ledger')" rule>
        <div class="ledger-wrap">
            <table class="ledger-table" data-stack>
                <thead>
                <tr>
                    <th scope="col">{{ __('app.tapped_at') }}</th>
                    <th scope="col">{{ __('app.student') }}</th>
                    <th scope="col">{{ __('app.reader_label') }}</th>
                    <th scope="col">{{ __('app.material') }}</th>
                    <th scope="col">{{ __('app.ecostation_confidence') }}</th>
                    <th scope="col" style="text-align:right;">{{ __('app.points') }}</th>
                </tr>
                </thead>
                <tbody>
                @forelse($recentDeposits as $deposit)
                    <tr>
                        <td class="num mono" data-label="{{ __('app.tapped_at') }}">{{ $deposit->event?->occurred_at?->format('Y-m-d H:i') ?? '—' }}</td>
                        <td data-label="{{ __('app.student') }}">{{ $deposit->event?->student?->name ?? '—' }}</td>
                        <td data-label="{{ __('app.reader_label') }}">{{ $deposit->event?->reader?->label ?? '—' }}</td>
                        <td data-label="{{ __('app.material') }}">
                            <span class="live-chip" data-event-type="RECYCLING_DEPOSIT">{{ $deposit->material_class?->value ?? '—' }}</span>
                        </td>
                        <td class="num mono" data-label="{{ __('app.ecostation_confidence') }}">{{ $deposit->confidence !== null ? round(100 * $deposit->confidence) . '%' : '—' }}</td>
                        <td class="num" data-label="{{ __('app.points') }}" style="text-align:right;">
                            <span class="points-badge">+{{ $deposit->points_awarded }} PTS</span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">{{ __('app.ecostation_no_deposits') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="table-note" style="margin-top: var(--sp-sm); border-radius: var(--radius-sm);">
            <span class="material-symbols-outlined is-16" aria-hidden="true">shield</span>
            <span>{{ __('app.ecostation_ledger_note') }}</span>
        </div>
    </x-panel>

    {{-- Hardware network + material rules (~40%) --}}
    <div class="stack">
        <x-panel :label="__('app.ecostation_readers')">
            <ul class="ruled">
                @forelse($readers as $reader)
                    <li>
                        <span class="rate-name" style="display:inline-flex;align-items:center;gap:6px;">
                            <span class="dot" aria-hidden="true"></span>{{ $reader->label }}
                        </span>
                        <span class="meta mono">{{ $reader->active_event_type }}</span>
                    </li>
                @empty
                    <li class="muted">{{ __('app.ecostation_no_readers') }}</li>
                @endforelse
            </ul>
        </x-panel>

        <x-panel :label="__('app.ecostation_rates')">
            <ul class="rates-list">
                @foreach($rates as $material => $points)
                    <li>
                        <span class="rate-name"><span class="dot" aria-hidden="true"></span>{{ $material }}</span>
                        <span class="rate-value {{ $points === 0 ? 'is-zero' : '' }}">+{{ $points }} PTS</span>
                    </li>
                @endforeach
            </ul>
            <div class="table-note" style="margin-top: var(--sp-sm); border-radius: var(--radius-sm);">
                <span class="material-symbols-outlined is-16" aria-hidden="true">tune</span>
                <span>{{ __('app.ecostation_rates_note') }}</span>
            </div>
        </x-panel>

        {{-- Sensor audit spotlight — latest classified capture. The image
             itself stays on the private disk (audit artifact, gap #E1);
             the card shows its REAL classification metadata. --}}
        <x-panel :label="__('app.ecostation_capture')">
            @if($latestCapture)
                <div class="capture-shot">
                    <div class="capture-none" aria-hidden="true">
                        <span class="material-symbols-outlined is-24">photo_camera</span>
                        <span>{{ __('app.ecostation_capture_private') }}</span>
                    </div>
                    <div class="capture-caption">
                        <span>{{ $latestCapture->material_class?->value }} · {{ round(100 * (float) $latestCapture->confidence) }}%</span>
                        <span>{{ $latestCapture->event?->occurred_at?->format('Y-m-d H:i') }}</span>
                    </div>
                </div>
                <p class="muted small" style="margin-top: var(--sp-2xs);">
                    {{ __('app.ecostation_capture_meta', [
                        'student' => $latestCapture->event?->student?->name ?? '—',
                        'bottle' => $latestCapture->is_bottle ? __('app.yes') : __('app.no'),
                        'recyclable' => $latestCapture->is_recyclable ? __('app.yes') : __('app.no'),
                    ]) }}
                </p>
            @else
                <div class="empty">
                    <span class="empty-icon"><span class="material-symbols-outlined is-24" aria-hidden="true">photo_camera</span></span>
                    <p>{{ __('app.ecostation_capture_none') }}</p>
                </div>
            @endif
        </x-panel>
    </div>
</div>
@endsection
