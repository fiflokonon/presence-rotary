<x-layouts.super-admin title="Plans — Super-admin">
    <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)]">
        <div class="flex items-center justify-between gap-3">
            <h1 class="font-display text-xl font-extrabold text-navy">Plans</h1>
            <a href="{{ route('super-admin.plans.create') }}"
                class="cursor-pointer rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                Ajouter un plan
            </a>
        </div>

        @if (session('status'))
            <div class="mt-4 rounded-lg bg-cream px-4 py-3 text-sm text-navy">{{ session('status') }}</div>
        @endif

        <div class="mt-6 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-divider text-muted-strong">
                        <th class="py-2 pr-4 font-semibold">Nom</th>
                        <th class="py-2 pr-4 font-semibold">Durée</th>
                        <th class="py-2 pr-4 font-semibold">Prix</th>
                        <th class="py-2 pr-4 font-semibold">Statut</th>
                        <th class="py-2 pr-4 font-semibold"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-divider">
                    @foreach ($plans as $plan)
                        <tr>
                            <td class="py-3 pr-4 font-semibold text-navy">{{ $plan->name }}</td>
                            <td class="py-3 pr-4 text-muted">{{ $plan->duration_months }} mois</td>
                            <td class="py-3 pr-4 text-muted">{{ number_format($plan->price, 0, ',', ' ') }} FCFA</td>
                            <td class="py-3 pr-4">{{ $plan->is_active ? 'Actif' : 'Inactif' }}</td>
                            <td class="py-3 pr-4 flex gap-3">
                                <a href="{{ route('super-admin.plans.edit', $plan) }}" class="cursor-pointer text-sm font-semibold text-navy hover:text-navy-hover">Modifier</a>
                                <form method="POST" action="{{ route('super-admin.plans.toggle-active', $plan) }}">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="cursor-pointer text-sm font-semibold text-navy hover:text-navy-hover">
                                        {{ $plan->is_active ? 'Désactiver' : 'Activer' }}
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-layouts.super-admin>
