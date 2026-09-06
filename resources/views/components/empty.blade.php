<div class="empty" role="status">
    @isset($icon)
        <span class="empty-icon" aria-hidden="true">{{ $icon }}</span>
    @endisset
    <p>{{ $slot }}</p>
</div>
