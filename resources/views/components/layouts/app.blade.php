@props(['title' => 'Liste de présence — '.(\App\Models\ClubSetting::current()?->name ?? 'RC Cotonou Ife')])
<!doctype html>
<html lang="fr" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-[#F5F3EE] font-sans text-[#12213D] antialiased">
    <x-page-loading-overlay />
    {{ $slot }}
</body>
</html>
