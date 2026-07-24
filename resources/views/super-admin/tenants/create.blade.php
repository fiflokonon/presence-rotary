<x-layouts.super-admin title="Nouveau club — Super-admin">
    <div class="mx-auto max-w-[480px] rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)]">
        <h1 class="font-display text-xl font-extrabold text-navy">Nouveau club</h1>

        @if ($errors->any())
            <div class="mt-4 rounded-lg bg-error-bg px-4 py-3 text-sm text-error">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('super-admin.tenants.store') }}" class="mt-4 flex flex-col gap-4">
            @csrf
            <div class="flex flex-col gap-1.5">
                <label for="name" class="text-sm font-semibold">Nom du club</label>
                <input type="text" id="name" name="name" value="{{ old('name') }}" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="host" class="text-sm font-semibold">Sous-domaine (ex. club2.tondomaine.org)</label>
                <input type="text" id="host" name="host" value="{{ old('host') }}" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="admin_name" class="text-sm font-semibold">Nom du premier admin</label>
                <input type="text" id="admin_name" name="admin_name" value="{{ old('admin_name') }}" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="admin_email" class="text-sm font-semibold">Email du premier admin</label>
                <input type="email" id="admin_email" name="admin_email" value="{{ old('admin_email') }}" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>
            <p class="text-sm text-muted">
                Un mot de passe sera généré automatiquement et envoyé par email au premier admin du club.
            </p>
            <button type="submit"
                class="cursor-pointer rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                Créer le club
            </button>
        </form>
    </div>
</x-layouts.super-admin>
