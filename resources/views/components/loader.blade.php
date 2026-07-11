@props(['class' => 'h-8 w-8'])

<img
    src="{{ asset('assets/rotary-nexus-logo.png') }}"
    alt="Chargement…"
    {{ $attributes->merge(['class' => $class.' object-contain animate-spin']) }}
>
