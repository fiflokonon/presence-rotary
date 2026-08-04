<!doctype html>
<html lang="fr" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $clubSetting?->name ?? 'Service' }} — Indisponible</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex h-full items-center justify-center bg-cream font-sans text-navy antialiased">
    <div class="mx-auto max-w-[420px] rounded-2xl bg-white p-8 text-center shadow-[0_2px_10px_rgba(20,30,50,.06)]">
        @if ($clubSetting)
            <img src="{{ $clubSetting->logoUrl() }}" alt="{{ $clubSetting->name }}" class="mx-auto h-16 w-16 object-contain">
            <h1 class="mt-4 font-display text-lg font-extrabold text-navy">{{ $clubSetting->name }}</h1>
        @endif
        <p class="mt-3 text-sm text-muted">
            Ce service est temporairement indisponible. Merci de contacter l'administrateur du club.
        </p>
    </div>
</body>
</html>
