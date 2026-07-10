<x-layouts.app title="Connexion administrateur">
    <div class="mx-auto flex min-h-screen max-w-[380px] items-center px-4">
        <div class="w-full rounded-xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)]">
            <h1 class="font-display text-xl font-extrabold text-[#12213D]">Connexion administrateur</h1>

            @if ($errors->any())
                <div class="mt-4 rounded-lg bg-[#FBEAEA] px-4 py-3 text-sm text-[#B23B3B]">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('admin.login.store') }}" class="mt-4 flex flex-col gap-4">
                @csrf
                <div class="flex flex-col gap-1.5">
                    <label for="email" class="text-sm font-semibold">E-mail</label>
                    <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus
                        class="rounded-lg border border-[#DEDAD0] px-3 py-2 text-sm">
                </div>
                <div class="flex flex-col gap-1.5">
                    <label for="password" class="text-sm font-semibold">Mot de passe</label>
                    <input type="password" id="password" name="password" required
                        class="rounded-lg border border-[#DEDAD0] px-3 py-2 text-sm">
                </div>
                <button type="submit"
                    class="rounded-lg bg-[#12213D] px-4 py-2.5 text-sm font-bold text-white hover:bg-[#1c3559]">
                    Se connecter
                </button>
            </form>
        </div>
    </div>
</x-layouts.app>
