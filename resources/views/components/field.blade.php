@props(['label' => null, 'for' => null])

<div class="field">
    @if ($label)
        <label for="{{ $for }}">{{ $label }}</label>
    @endif

    {{ $slot }}
</div>
