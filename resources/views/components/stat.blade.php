@props(['label', 'stat' => null])

<div class="stat" @if($stat) data-stat="{{ $stat }}" @endif>
    <span class="stat-label">
        <span class="kpi-icon" aria-hidden="true">@isset($icon){{ $icon }}@endisset</span>
        {{ $label }}
    </span>
    <span class="stat-value">{{ $slot }}</span>
    {{-- Optional footer action (RUN-037 spacing pass: the student
         hub's REWARDS link lived here instead of dangling alone below
         the grid). --}}
    @isset($footer)
        <span class="stat-footer">{{ $footer }}</span>
    @endisset
</div>
