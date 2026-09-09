@props(['label' => null, 'rule' => false])

{{-- `rule` renders the design system's accent rule (.panel-rule) on the
     panel — the prop was previously captured and silently ignored, so no
     desk ever got the accent the mockups show. --}}
<section {{ $attributes->merge(['class' => 'panel'.($rule ? ' panel-rule' : '')]) }}>
    @if ($label)
        <p class="panel-label"><span class="dot" aria-hidden="true"></span>{{ $label }}</p>
    @endif

    {{ $slot }}
</section>
