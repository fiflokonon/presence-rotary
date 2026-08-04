<x-layouts.super-admin title="Réglages — Super-admin">
    <div class="mx-auto max-w-[420px] rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)]">
        <h1 class="font-display text-xl font-extrabold text-navy">Réglages de la plateforme</h1>

        @if (session('status'))
            <div class="mt-4 rounded-lg bg-cream px-4 py-3 text-sm text-navy">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="mt-4 rounded-lg bg-error-bg px-4 py-3 text-sm text-error">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('super-admin.settings.update') }}" class="mt-4 flex flex-col gap-4">
            @csrf
            @method('PUT')
            <div class="flex flex-col gap-1.5">
                <label for="default_grace_period_days" class="text-sm font-semibold">Délai de grâce par défaut (jours)</label>
                <input type="number" id="default_grace_period_days" name="default_grace_period_days"
                    value="{{ old('default_grace_period_days', $platformSetting->default_grace_period_days) }}" required min="0"
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
                <p class="text-sm text-muted">Appliqué à tout club sans délai de grâce spécifique (voir la liste des clubs).</p>
            </div>
            <button type="submit" class="cursor-pointer rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                Enregistrer
            </button>
        </form>
    </div>
</x-layouts.super-admin>
