@props(['title' => 'Super-admin'])
<!doctype html>
<html lang="fr" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-cream font-sans text-navy antialiased">
    @auth('super_admin')
        <nav class="border-b border-divider bg-white px-4 py-3">
            <div class="mx-auto flex max-w-5xl items-center justify-between">
                <div class="flex items-center gap-4 text-sm font-semibold">
                    <a href="{{ route('super-admin.tenants.index') }}" class="text-navy hover:text-navy-hover">Clubs</a>
                    <a href="{{ route('super-admin.dashboard') }}" class="text-navy hover:text-navy-hover">Tableau de bord</a>
                </div>
                <form method="POST" action="{{ route('super-admin.logout') }}">
                    @csrf
                    <button type="submit" class="cursor-pointer text-sm font-semibold text-muted hover:text-navy">Déconnexion</button>
                </form>
            </div>
        </nav>
    @endauth

    <main class="mx-auto max-w-5xl px-4 py-8">
        {{ $slot }}
    </main>
</body>
</html>
