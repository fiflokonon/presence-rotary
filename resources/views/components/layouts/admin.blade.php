@props(['title' => 'Administration — RC Cotonou Nexus'])
<!doctype html>
<html lang="fr" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full bg-[#F5F3EE] font-sans text-[#12213D] antialiased">
    <div class="mx-auto max-w-[1040px] px-4 py-8">
        <div class="mb-4 flex items-center justify-between">
            <span class="text-sm font-semibold text-[#12213D]">RC Cotonou Nexus · Administration</span>
            @auth
                <form method="POST" action="{{ route('admin.logout') }}">
                    @csrf
                    <button type="submit" class="text-sm font-semibold text-[#C77700]">Se déconnecter</button>
                </form>
            @endauth
        </div>
        {{ $slot }}
    </div>
</body>
</html>
