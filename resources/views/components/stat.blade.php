@props(['label'])

<div class="stat">
    <span class="stat-label">{{ $label }}</span>
    <span class="stat-value">{{ $slot }}</span>
</div>
