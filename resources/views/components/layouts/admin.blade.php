@props(['title' => 'Administration — '.(\App\Models\ClubSetting::current()?->name ?? 'RC Cotonou Ife')])
@php $clubSetting = \App\Models\ClubSetting::current(); @endphp
<!doctype html>
<html lang="fr" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-cream font-sans text-navy antialiased">
    @if (session()->has('impersonating_tenant_id'))
        <div class="flex items-center justify-between bg-navy px-4 py-2 text-sm text-white">
            <span>Vous consultez : {{ $clubSetting?->name ?? 'ce club' }}</span>
            <form method="POST" action="{{ route('super-admin.impersonate.stop') }}">
                @csrf
                <button type="submit" class="cursor-pointer font-semibold underline">Quitter la vue</button>
            </form>
        </div>
    @endif
    @isset($subscriptionGraceWarning)
        <div class="bg-gold px-4 py-2 text-center text-sm font-semibold text-navy">
            Votre souscription a expiré le {{ $subscriptionGraceWarning['expiredAt']->format('d/m/Y') }}. Renouvelez avant le {{ $subscriptionGraceWarning['graceEndsAt']->format('d/m/Y') }} pour ne pas perdre l'accès.
            <a href="{{ route('admin.subscription.index') }}" class="underline">Renouveler</a>
        </div>
    @endisset
    <x-page-loading-overlay />
    <div x-data="adminShell()" class="flex min-h-full flex-col md:flex-row">
        <div class="flex items-center justify-between border-b border-divider bg-white px-4 py-3 md:hidden">
            <div class="flex items-center gap-2">
                <div class="inline-flex items-center justify-center rounded-lg p-1 shadow-[0_6px_14px_rgba(10,92,166,.3)]" style="background: linear-gradient(135deg, {{ $clubSetting?->secondary_color ?? '#17A8E5' }} 0%, {{ $clubSetting?->primary_color ?? '#0B73C5' }} 100%);">
                    <img src="{{ $clubSetting?->logoUrl() ?? asset('assets/ife-logo.png') }}" alt="{{ $clubSetting?->name ?? 'RC Cotonou Ife' }}" class="h-8 w-8 object-contain">
                </div>
                <span class="text-sm font-semibold text-navy">{{ $clubSetting?->name ?? 'RC Cotonou Ife' }}</span>
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
                <div class="inline-flex items-center justify-center rounded-lg p-1 shadow-[0_6px_14px_rgba(10,92,166,.3)]" style="background: linear-gradient(135deg, {{ $clubSetting?->secondary_color ?? '#17A8E5' }} 0%, {{ $clubSetting?->primary_color ?? '#0B73C5' }} 100%);">
                    <img src="{{ $clubSetting?->logoUrl() ?? asset('assets/ife-logo.png') }}" alt="{{ $clubSetting?->name ?? 'RC Cotonou Ife' }}" class="h-10 w-10 object-contain">
                </div>
                <span class="text-sm font-semibold text-navy">{{ $clubSetting?->name ?? 'RC Cotonou Ife' }}</span>
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
                <a href="{{ route('admin.dashboard') }}" @click="close()"
                    class="cursor-pointer flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold {{ request()->routeIs('admin.dashboard') ? 'bg-navy text-white' : 'text-navy hover:bg-cream' }}">
                    <i class="fa-solid fa-gauge w-4 text-center" aria-hidden="true"></i> Tableau de bord
                </a>
                <a href="{{ route('admin.sessions.index') }}" @click="close()"
                    class="cursor-pointer flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold {{ request()->routeIs('admin.sessions.*') ? 'bg-navy text-white' : 'text-navy hover:bg-cream' }}">
                    <i class="fa-solid fa-calendar-days w-4 text-center" aria-hidden="true"></i> Séances
                </a>
                <a href="{{ route('admin.users.index') }}" @click="close()"
                    class="cursor-pointer flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold {{ request()->routeIs('admin.users.*') ? 'bg-navy text-white' : 'text-navy hover:bg-cream' }}">
                    <i class="fa-solid fa-user-shield w-4 text-center" aria-hidden="true"></i> Administrateurs
                </a>
                <a href="{{ route('admin.club-settings.edit') }}" @click="close()"
                    class="cursor-pointer flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold {{ request()->routeIs('admin.club-settings.*') ? 'bg-navy text-white' : 'text-navy hover:bg-cream' }}">
                    <i class="fa-solid fa-palette w-4 text-center" aria-hidden="true"></i> Identité du club
                </a>
                <a href="{{ route('admin.subscription.index') }}" @click="close()"
                    class="cursor-pointer flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold {{ request()->routeIs('admin.subscription.*') ? 'bg-navy text-white' : 'text-navy hover:bg-cream' }}">
                    <i class="fa-solid fa-credit-card w-4 text-center" aria-hidden="true"></i> Souscription
                </a>
                <a href="{{ route('admin.mail-settings.edit') }}" @click="close()"
                    class="cursor-pointer flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold {{ request()->routeIs('admin.mail-settings.*') ? 'bg-navy text-white' : 'text-navy hover:bg-cream' }}">
                    <i class="fa-solid fa-envelope w-4 text-center" aria-hidden="true"></i> Paramètres mail
                </a>
                <a href="{{ route('admin.checkin-settings.edit') }}" @click="close()"
                    class="cursor-pointer flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold {{ request()->routeIs('admin.checkin-settings.*') ? 'bg-navy text-white' : 'text-navy hover:bg-cream' }}">
                    <i class="fa-solid fa-list-check w-4 text-center" aria-hidden="true"></i> Paramètres formulaire
                </a>
                <a href="{{ route('admin.members.index') }}" @click="close()"
                    class="cursor-pointer flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold {{ request()->routeIs('admin.members.*') ? 'bg-navy text-white' : 'text-navy hover:bg-cream' }}">
                    <i class="fa-solid fa-users w-4 text-center" aria-hidden="true"></i> Membres
                </a>
                <a href="{{ route('admin.titles.index') }}" @click="close()"
                    class="cursor-pointer flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold {{ request()->routeIs('admin.titles.*') ? 'bg-navy text-white' : 'text-navy hover:bg-cream' }}">
                    <i class="fa-solid fa-sitemap w-4 text-center" aria-hidden="true"></i> Organisations
                </a>
                <a href="{{ route('admin.positions.index') }}" @click="close()"
                    class="cursor-pointer flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold {{ request()->routeIs('admin.positions.*') ? 'bg-navy text-white' : 'text-navy hover:bg-cream' }}">
                    <i class="fa-solid fa-id-badge w-4 text-center" aria-hidden="true"></i> Titres/Qualités
                </a>
            </nav>

            @auth
                @unless (session()->has('impersonating_tenant_id'))
                    <a href="{{ route('admin.password.edit') }}" @click="close()"
                        class="cursor-pointer flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold {{ request()->routeIs('admin.password.*') ? 'bg-navy text-white' : 'text-navy hover:bg-cream' }}">
                        <i class="fa-solid fa-key w-4 text-center" aria-hidden="true"></i> Mon mot de passe
                    </a>
                @endunless
                <form method="POST" action="{{ route('admin.logout') }}">
                    @csrf
                    <button type="submit"
                        class="cursor-pointer flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm font-semibold text-gold hover:bg-cream">
                        <i class="fa-solid fa-right-from-bracket w-4 text-center" aria-hidden="true"></i> Se déconnecter
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
