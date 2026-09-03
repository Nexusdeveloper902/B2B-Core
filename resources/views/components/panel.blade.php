@props(['label' => null, 'rule' => false])

<section {{ $attributes->merge(['class' => 'panel']) }}>
    @if ($label)
        <p class="panel-label"><span class="dot" aria-hidden="true"></span>{{ $label }}</p>
    @endif

    {{ $slot }}
</section>
