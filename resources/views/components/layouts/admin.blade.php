@props(['title' => 'Administration — RC Cotonou Nexus'])
<!doctype html>
<html lang="fr" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-cream font-sans text-navy antialiased">
    <x-page-loading-overlay />
    <div x-data="adminShell()" class="flex min-h-full flex-col md:flex-row">
        <div class="flex items-center justify-between border-b border-divider bg-white px-4 py-3 md:hidden">
            <div class="flex items-center gap-2">
                <div class="inline-flex items-center justify-center rounded-lg bg-[linear-gradient(135deg,#17A8E5_0%,#0B73C5_55%,#0A5CA6_100%)] p-1 shadow-[0_6px_14px_rgba(10,92,166,.3)]">
                    <img src="{{ asset('assets/ife-logo.png') }}" alt="RC Cotonou Nexus" class="h-8 w-8 object-contain">
                </div>
                <span class="text-sm font-semibold text-navy">RC Cotonou Nexus</span>
            </div>
            <button type="button" @click="toggle()" aria-label="Ouvrir le menu"
                class="cursor-pointer rounded-lg p-2 text-navy hover:bg-cream">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </button>
        </div>

        <div x-show="sidebarOpen" x-cloak @click="close()" x-transition.opacity
            class="fixed inset-0 z-30 cursor-pointer bg-black/40 md:hidden"></div>

        <aside
            :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
            class="fixed inset-y-0 left-0 z-40 flex w-60 flex-col border-r border-divider bg-white px-4 py-6 transition-transform duration-200 md:static md:translate-x-0"
        >
            <div class="hidden items-center gap-2 px-2 md:flex">
                <div class="inline-flex items-center justify-center rounded-lg bg-[linear-gradient(135deg,#17A8E5_0%,#0B73C5_55%,#0A5CA6_100%)] p-1 shadow-[0_6px_14px_rgba(10,92,166,.3)]">
                    <img src="{{ asset('assets/ife-logo.png') }}" alt="RC Cotonou Nexus" class="h-10 w-10 object-contain">
                </div>
                <span class="text-sm font-semibold text-navy">RC Cotonou Nexus</span>
            </div>

            <div class="flex items-center justify-between px-2 md:hidden">
                <span class="text-sm font-semibold text-navy">Menu</span>
                <button type="button" @click="close()" aria-label="Fermer le menu"
                    class="cursor-pointer rounded-lg p-1 text-muted hover:bg-cream">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <nav class="mt-6 flex flex-1 flex-col gap-1">
                <a href="{{ route('admin.sessions.index') }}" @click="close()"
                    class="cursor-pointer rounded-lg px-3 py-2 text-sm font-semibold {{ request()->routeIs('admin.sessions.*') ? 'bg-navy text-white' : 'text-navy hover:bg-cream' }}">
                    Séances
                </a>
            </nav>

            @auth
                <form method="POST" action="{{ route('admin.logout') }}">
                    @csrf
                    <button type="submit"
                        class="cursor-pointer w-full rounded-lg px-3 py-2 text-left text-sm font-semibold text-gold hover:bg-cream">
                        Se déconnecter
                    </button>
                </form>
            @endauth
        </aside>

        <main class="flex-1 px-4 py-6 md:px-8 md:py-10">
            <div class="mx-auto max-w-[1040px]">
                {{ $slot }}
            </div>
        </main>
    </div>
</body>
</html>
