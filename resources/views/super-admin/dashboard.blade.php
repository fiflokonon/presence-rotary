<x-layouts.super-admin title="Tableau de bord — Super-admin">
    <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)]">
        <h1 class="font-display text-xl font-extrabold text-navy">Tableau de bord</h1>

        <div class="mt-6 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-divider text-muted-strong">
                        <th class="py-2 pr-4 font-semibold">Club</th>
                        <th class="py-2 pr-4 font-semibold">Membres</th>
                        <th class="py-2 pr-4 font-semibold">Présences enregistrées</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-divider">
                    @foreach ($rows as $row)
                        <tr>
                            <td class="py-3 pr-4 font-semibold text-navy">{{ $row['name'] }}</td>
                            <td class="py-3 pr-4">{{ $row['member_count'] }}</td>
                            <td class="py-3 pr-4">{{ $row['attendance_count'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-layouts.super-admin>
