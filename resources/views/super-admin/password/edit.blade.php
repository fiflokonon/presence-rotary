<x-layouts.super-admin title="Mon mot de passe — Super-admin">
    <div class="mx-auto max-w-md rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)]">
        <h1 class="font-display text-xl font-extrabold text-navy">Mon mot de passe</h1>
        <p class="mt-1 text-sm text-muted">
            Saisissez votre mot de passe actuel puis le nouveau.
        </p>

        @if (session('status'))
            <div class="mt-4 rounded-lg bg-success-bg px-4 py-3 text-sm text-success">
                {{ session('status') }}
            </div>
        @endif

        <form method="POST" action="{{ route('super-admin.password.update') }}" class="mt-4 flex flex-col gap-3">
            @csrf
            @method('PUT')

            <div class="flex flex-col gap-1.5">
                <label for="current_password" class="text-sm font-semibold">Mot de passe actuel</label>
                <input type="password" id="current_password" name="current_password" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="password" class="text-sm font-semibold">Nouveau mot de passe</label>
                <input type="password" id="password" name="password" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="password_confirmation" class="text-sm font-semibold">Confirmer le nouveau mot de passe</label>
                <input type="password" id="password_confirmation" name="password_confirmation" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>

            <button type="submit"
                class="mt-2 cursor-pointer self-start rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                Mettre à jour
            </button>
        </form>

        @if ($errors->any())
            <div class="mt-4 rounded-lg bg-error-bg px-4 py-3 text-sm text-error">
                {{ $errors->first() }}
            </div>
        @endif
    </div>
</x-layouts.super-admin>
