<x-layouts.app title="Connexion administrateur">
    <div class="mx-auto flex min-h-screen max-w-[380px] flex-col items-center justify-center gap-6 px-4">
        <img src="{{ asset('assets/rotary-nexus-logo.png') }}" alt="RC Cotonou Nexus" class="h-14 w-14 object-contain">
        <div class="w-full rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)]">
            <h1 class="font-display text-xl font-extrabold text-navy">Connexion administrateur</h1>

            @if ($errors->any())
                <div class="mt-4 rounded-lg bg-error-bg px-4 py-3 text-sm text-error">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('admin.login.store') }}" class="mt-4 flex flex-col gap-4">
                @csrf
                <div class="flex flex-col gap-1.5">
                    <label for="email" class="text-sm font-semibold">E-mail</label>
                    <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus
                        class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
                </div>
                <div class="flex flex-col gap-1.5">
                    <label for="password" class="text-sm font-semibold">Mot de passe</label>
                    <input type="password" id="password" name="password" required
                        class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
                </div>
                <button type="submit"
                    class="cursor-pointer rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                    Se connecter
                </button>
            </form>
        </div>
    </div>
</x-layouts.app>
