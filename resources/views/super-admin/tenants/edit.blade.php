<x-layouts.super-admin title="Modifier le club — Super-admin">
    <div class="mx-auto max-w-lg rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)]">
        <h1 class="font-display text-xl font-extrabold text-navy">Modifier le sous-domaine</h1>
        <p class="mt-1 text-sm text-muted">Club : <span class="font-semibold text-navy">{{ $tenant->name }}</span></p>

        <form method="POST" action="{{ route('super-admin.tenants.update', $tenant) }}" class="mt-6 flex flex-col gap-4">
            @csrf
            @method('PATCH')
            <div class="flex flex-col gap-1.5">
                <label for="host" class="text-sm font-semibold">Sous-domaine (ex. club2.tondomaine.org)</label>
                <input type="text" id="host" name="host" value="{{ old('host', $tenant->host) }}" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
                @error('host')
                    <p class="text-sm text-error">{{ $message }}</p>
                @enderror
            </div>
            <div class="flex items-center gap-3">
                <button type="submit"
                    class="cursor-pointer rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                    Enregistrer
                </button>
                <a href="{{ route('super-admin.tenants.index') }}"
                    class="cursor-pointer text-sm font-semibold text-muted hover:text-navy">
                    Annuler
                </a>
            </div>
        </form>
    </div>
</x-layouts.super-admin>
