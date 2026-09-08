@props(['label', 'stat' => null])

<div class="stat" @if($stat) data-stat="{{ $stat }}" @endif>
    <span class="stat-label">
        <span class="kpi-icon" aria-hidden="true">@isset($icon){{ $icon }}@endisset</span>
        {{ $label }}
    </span>
    <span class="stat-value">{{ $slot }}</span>
</div>
