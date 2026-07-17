<x-layouts.admin title="Organisations — Administration">
    <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)] md:p-8">
        <div class="flex items-center justify-between gap-3">
            <h1 class="font-display text-xl font-extrabold text-navy">Organisations</h1>
            <a href="{{ route('admin.titles.create') }}"
                class="cursor-pointer rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                Ajouter une organisation
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
                        <th class="py-2 pr-4 font-semibold">Principale</th>
                        <th class="py-2 pr-4 font-semibold">Titres/Qualités liés</th>
                        <th class="py-2 pr-4 font-semibold">Ordre</th>
                        <th class="py-2 pr-4 font-semibold">Statut</th>
                        <th class="py-2 pr-4 font-semibold"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-divider">
                    @foreach ($titles as $title)
                        <tr>
                            <td class="py-3 pr-4 font-semibold text-navy">{{ $title->name }}</td>
                            <td class="py-3 pr-4">
                                @if ($title->is_principal)
                                    <span class="rounded-full bg-success-bg px-2 py-1 text-xs font-semibold text-success">Oui</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="py-3 pr-4">{{ $title->positions_count }}</td>
                            <td class="py-3 pr-4">
                                <div class="flex items-center gap-2">
                                    <form method="POST" action="{{ route('admin.titles.move-order', [$title, 'up']) }}" class="inline">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="cursor-pointer text-lg leading-none text-muted hover:text-navy" title="Déplacer vers le haut">
                                            ↑
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.titles.move-order', [$title, 'down']) }}" class="inline">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="cursor-pointer text-lg leading-none text-muted hover:text-navy" title="Déplacer vers le bas">
                                            ↓
                                        </button>
                                    </form>
                                </div>
                            </td>
                            <td class="py-3 pr-4">
                                <span class="rounded-full px-2 py-1 text-xs font-semibold {{ $title->is_active ? 'bg-success-bg text-success' : 'bg-divider text-muted' }}">
                                    {{ $title->is_active ? 'Actif' : 'Inactif' }}
                                </span>
                            </td>
                            <td class="py-3 pr-4 text-right">
                                <div class="flex items-center justify-end gap-3">
                                    <a href="{{ route('admin.titles.edit', $title) }}" class="text-sm font-semibold text-navy underline">
                                        Modifier
                                    </a>
                                    <form method="POST" action="{{ route('admin.titles.toggle-active', $title) }}">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="cursor-pointer text-sm font-semibold text-navy underline">
                                            {{ $title->is_active ? 'Désactiver' : 'Activer' }}
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.titles.destroy', $title) }}"
                                        onsubmit="return confirm('Supprimer définitivement cette organisation ?');">
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
