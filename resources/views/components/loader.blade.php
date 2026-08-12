@props(['class' => 'h-8 w-8'])

@php
    $clubSetting = \App\Models\ClubSetting::current();
    $logoUrl = $clubSetting?->logoUrl() ?? asset('assets/ife-logo.png');
    $dotColor = $clubSetting?->primary_color ?? '#0B73C5';
@endphp

<div {{ $attributes->merge(['class' => 'flex flex-col items-center gap-3']) }}>
    <img
        src="{{ $logoUrl }}"
        alt="Chargement…"
        class="{{ $class }} object-contain"
    >
    <div class="flex items-center gap-1.5" role="status" aria-label="Chargement…">
        <span class="h-2 w-2 rounded-full animate-bounce" style="background-color: {{ $dotColor }};"></span>
        <span class="h-2 w-2 rounded-full animate-bounce" style="background-color: {{ $dotColor }}; animation-delay: 150ms;"></span>
        <span class="h-2 w-2 rounded-full animate-bounce" style="background-color: {{ $dotColor }}; animation-delay: 300ms;"></span>
    </div>
</div>
