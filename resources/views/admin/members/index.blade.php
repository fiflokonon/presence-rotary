<x-layouts.admin title="Membres — Administration">
    <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)] md:p-8">
        <h1 class="font-display text-xl font-extrabold text-navy">Membres</h1>

        <form method="GET" action="{{ route('admin.members.index') }}" class="mt-4 flex max-w-sm gap-2">
            <input type="text" name="search" value="{{ $search }}" placeholder="Nom, email ou club"
                class="w-full rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            <button type="submit"
                class="cursor-pointer rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                Rechercher
            </button>
        </form>

        <a href="{{ route('admin.members.create') }}"
            class="mt-4 inline-flex items-center gap-2 rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
            <i class="fa-solid fa-plus" aria-hidden="true"></i> Ajouter un membre
        </a>

        <a href="{{ route('admin.members.import-template') }}"
            class="mt-4 ml-2 inline-flex items-center gap-2 rounded-lg border border-border px-4 py-2.5 text-sm font-bold text-navy hover:bg-cream">
            <i class="fa-solid fa-file-arrow-down" aria-hidden="true"></i> Télécharger le gabarit
        </a>

        <div class="mt-6 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-divider text-muted-strong">
                        <th class="py-2 pr-4 font-semibold">Nom</th>
                        <th class="py-2 pr-4 font-semibold">Email</th>
                        <th class="py-2 pr-4 font-semibold">Club</th>
                        <th class="py-2 pr-4 font-semibold"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-divider">
                    @foreach ($members as $member)
                        <tr>
                            <td class="py-3 pr-4 font-semibold text-navy">
                                {{ $member->name }}
                                @if ($member->is_club_member)
                                    <span class="ml-2 rounded-full bg-gold/20 px-2 py-0.5 text-[11px] font-semibold text-gold">Membre du club</span>
                                @endif
                            </td>
                            <td class="py-3 pr-4">{{ $member->email }}</td>
                            <td class="py-3 pr-4">{{ $member->club }}</td>
                            <td class="py-3 pr-4 text-right">
                                <a href="{{ route('admin.members.show', $member) }}" class="text-sm font-semibold text-navy underline">
                                    Voir
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-layouts.admin>
