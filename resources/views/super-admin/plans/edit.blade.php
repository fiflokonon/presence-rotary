<x-layouts.super-admin title="Modifier le plan — Super-admin">
    <div class="mx-auto max-w-[420px] rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)]">
        <h1 class="font-display text-xl font-extrabold text-navy">Modifier le plan</h1>

        @if ($errors->any())
            <div class="mt-4 rounded-lg bg-error-bg px-4 py-3 text-sm text-error">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('super-admin.plans.update', $plan) }}" class="mt-4 flex flex-col gap-4">
            @csrf
            @method('PUT')
            <div class="flex flex-col gap-1.5">
                <label for="name" class="text-sm font-semibold">Nom</label>
                <input type="text" id="name" name="name" value="{{ old('name', $plan->name) }}" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="duration_months" class="text-sm font-semibold">Durée (mois)</label>
                <input type="number" id="duration_months" name="duration_months" value="{{ old('duration_months', $plan->duration_months) }}" required min="1"
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="price" class="text-sm font-semibold">Prix (FCFA)</label>
                <input type="number" id="price" name="price" value="{{ old('price', $plan->price) }}" required min="0"
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>
            <button type="submit" class="cursor-pointer rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                Enregistrer
            </button>
        </form>
    </div>
</x-layouts.super-admin>
