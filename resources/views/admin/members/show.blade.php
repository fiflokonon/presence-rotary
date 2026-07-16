<x-layouts.admin :title="$member->name . ' — Administration'">
    <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)] md:p-8">
        <div class="flex items-center justify-between gap-3">
            <h1 class="font-display text-xl font-extrabold text-navy">{{ $member->name }}</h1>
            <a href="{{ route('admin.members.edit', $member) }}"
                class="cursor-pointer rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                Modifier
            </a>
        </div>

        <dl class="mt-4 grid grid-cols-2 gap-4 text-sm">
            <div>
                <dt class="font-semibold text-muted-strong">Email</dt>
                <dd>{{ $member->email }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-muted-strong">Club</dt>
                <dd>{{ $member->club }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-muted-strong">Téléphone</dt>
                <dd>{{ $member->phone }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-muted-strong">Organisation / Titre-Qualité</dt>
                <dd>{{ $member->title->name }}{{ $member->position ? ' — '.$member->position->name : '' }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-muted-strong">Classification</dt>
                <dd>{{ $member->classification }}</dd>
            </div>
        </dl>

        <h2 class="mt-8 font-display text-lg font-extrabold text-navy">Historique des présences</h2>

        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-divider text-muted-strong">
                        <th class="py-2 pr-4 font-semibold">Séance</th>
                        <th class="py-2 pr-4 font-semibold">Date</th>
                        <th class="py-2 pr-4 font-semibold">Club</th>
                        <th class="py-2 pr-4 font-semibold">Classification</th>
                        <th class="py-2 pr-4 font-semibold">Présent</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-divider">
                    @foreach ($attendances as $attendance)
                        <tr>
                            <td class="py-3 pr-4 font-semibold text-navy">{{ $attendance->meetingSession->title }}</td>
                            <td class="py-3 pr-4 text-muted">{{ $attendance->meetingSession->date->format('d/m/Y') }}</td>
                            <td class="py-3 pr-4">{{ $attendance->club }}</td>
                            <td class="py-3 pr-4">{{ $attendance->classification }}</td>
                            <td class="py-3 pr-4">{{ $attendance->present ? 'Oui' : 'Non' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-layouts.admin>
