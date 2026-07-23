<x-layouts.super-admin title="Clubs — Super-admin">
    <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)]">
        <div class="flex items-center justify-between gap-3">
            <h1 class="font-display text-xl font-extrabold text-navy">Clubs</h1>
            <a href="{{ route('super-admin.tenants.create') }}"
                class="cursor-pointer rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                Ajouter un club
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
                        <th class="py-2 pr-4 font-semibold">Sous-domaine</th>
                        <th class="py-2 pr-4 font-semibold"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-divider">
                    @foreach ($tenants as $tenant)
                        <tr>
                            <td class="py-3 pr-4 font-semibold text-navy">{{ $tenant->name }}</td>
                            <td class="py-3 pr-4 text-muted">{{ $tenant->host }}</td>
                            <td class="py-3 pr-4">
                                <form method="POST" action="{{ route('super-admin.impersonate.start', $tenant) }}">
                                    @csrf
                                    <button type="submit" class="cursor-pointer text-sm font-semibold text-navy hover:text-navy-hover">
                                        Voir en tant que
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
