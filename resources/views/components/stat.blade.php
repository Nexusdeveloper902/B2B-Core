@props(['label'])

<div class="stat">
    <span class="stat-label">
        <span class="kpi-icon" aria-hidden="true">@isset($icon){{ $icon }}@endisset</span>
        {{ $label }}
    </span>
    <span class="stat-value">{{ $slot }}</span>
</div>
