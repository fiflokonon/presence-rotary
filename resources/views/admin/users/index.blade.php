<x-layouts.admin title="Administrateurs — Administration">
    <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)] md:p-8">
        <div class="flex items-center justify-between gap-3">
            <h1 class="font-display text-xl font-extrabold text-navy">Administrateurs</h1>
            <a href="{{ route('admin.users.create') }}"
                class="cursor-pointer rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                Ajouter un admin
            </a>
        </div>

        <div class="mt-6 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-divider text-muted-strong">
                        <th class="py-2 pr-4 font-semibold">Nom</th>
                        <th class="py-2 pr-4 font-semibold">Email</th>
                        <th class="py-2 pr-4 font-semibold">Créé le</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-divider">
                    @foreach ($users as $user)
                        <tr>
                            <td class="py-3 pr-4 font-semibold text-navy">{{ $user->name }}</td>
                            <td class="py-3 pr-4">{{ $user->email }}</td>
                            <td class="py-3 pr-4 text-muted">{{ $user->created_at->format('d/m/Y') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-layouts.admin>
