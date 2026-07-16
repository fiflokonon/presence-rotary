<x-layouts.admin title="Titres/Qualités — Administration">
    <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)] md:p-8">
        <div class="flex items-center justify-between gap-3">
            <h1 class="font-display text-xl font-extrabold text-navy">Titres/Qualités</h1>
            <a href="{{ route('admin.positions.create') }}"
                class="cursor-pointer rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                Ajouter un titre/qualité
            </a>
        </div>

        @if (session('error'))
            <div class="mt-4 rounded-lg bg-error-bg px-4 py-3 text-sm text-error">
                {{ session('error') }}
            </div>
        @endif

        <div class="mt-6 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-divider text-muted-strong">
                        <th class="py-2 pr-4 font-semibold">Nom</th>
                        <th class="py-2 pr-4 font-semibold">Statut</th>
                        <th class="py-2 pr-4 font-semibold"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-divider">
                    @foreach ($positions as $position)
                        <tr>
                            <td class="py-3 pr-4 font-semibold text-navy">{{ $position->name }}</td>
                            <td class="py-3 pr-4">
                                <span class="rounded-full px-2 py-1 text-xs font-semibold {{ $position->is_active ? 'bg-success-bg text-success' : 'bg-divider text-muted' }}">
                                    {{ $position->is_active ? 'Actif' : 'Inactif' }}
                                </span>
                            </td>
                            <td class="py-3 pr-4 text-right">
                                <div class="flex items-center justify-end gap-3">
                                    <a href="{{ route('admin.positions.edit', $position) }}" class="text-sm font-semibold text-navy underline">
                                        Modifier
                                    </a>
                                    <form method="POST" action="{{ route('admin.positions.toggle-active', $position) }}">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="cursor-pointer text-sm font-semibold text-navy underline">
                                            {{ $position->is_active ? 'Désactiver' : 'Activer' }}
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.positions.destroy', $position) }}"
                                        onsubmit="return confirm('Supprimer définitivement ce titre/qualité ?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="cursor-pointer text-sm font-semibold text-error underline">
                                            Supprimer
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-layouts.admin>
