<x-layouts.admin :title="$meetingSession->title . ' — Dashboard'">
    <div
        x-data="attendanceDashboard(@js($attendances->map(fn ($attendance) => [
            'id' => $attendance->id,
            'name' => $attendance->name,
            'title' => $attendance->title->value,
            'club' => $attendance->club,
            'phone' => $attendance->phone,
            'category' => $attendance->category->value,
            'categoryLabel' => $attendance->category->label(),
            'present' => $attendance->present,
            'isLate' => $attendance->is_late,
        ])))"
        class="rounded-xl bg-white shadow-[0_2px_10px_rgba(20,30,50,.06)]"
    >
        <div class="border-b border-[#EDEAE2] px-8 pb-5 pt-7">
            <p class="text-[11px] font-semibold uppercase text-[#C77700]">RC Cotonou Nexus · District 9103</p>
            <div class="mt-1 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="font-display text-2xl font-extrabold text-[#12213D]">{{ $meetingSession->title }}</h1>
                    <p class="text-[15px] text-[#6B6558]">{{ $meetingSession->date->translatedFormat('d F Y') }}</p>
                </div>
                <div class="flex items-center gap-3">
                    <a href="{{ route('admin.sessions.export-pdf', $meetingSession) }}"
                        class="rounded-lg bg-[#12213D] px-4 py-2 text-sm font-bold text-white hover:bg-[#1c3559]">
                        Exporter en PDF
                    </a>
                    <span class="rounded-full {{ $meetingSession->is_open ? 'bg-[#E7F5F1] text-[#0E7C66]' : 'bg-[#F1EFEA] text-[#6B6558]' }} px-3 py-1 text-xs font-semibold">
                        ● {{ $meetingSession->is_open ? 'Séance ouverte' : 'Séance clôturée' }}
                    </span>
                    <form method="POST" action="{{ route('admin.sessions.toggle-open', $meetingSession) }}">
                        @csrf
                        <button type="submit" class="text-sm font-semibold text-[#12213D] underline">
                            {{ $meetingSession->is_open ? 'Clôturer la séance' : 'Rouvrir la séance' }}
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-3 px-8 py-5 md:grid-cols-5">
            <div class="rounded-lg bg-[#12213D] p-3 text-white">
                <p class="text-lg font-extrabold">{{ $attendances->where('present', true)->count() }}/{{ $attendances->count() }}</p>
                <p class="text-xs">Présents ({{ $attendances->count() > 0 ? round($attendances->where('present', true)->count() / $attendances->count() * 100) : 0 }}%)</p>
            </div>
            @foreach (\App\Enums\AttendanceCategory::cases() as $category)
                @php $categoryCount = $attendances->filter(fn ($attendance) => $attendance->category === $category)->count(); @endphp
                <div class="rounded-lg p-3" style="background-color: {{ $category->colors()['bg'] }}; color: {{ $category->colors()['accent'] }}">
                    <p class="text-lg font-extrabold">{{ $categoryCount }}</p>
                    <p class="text-xs">{{ $category->label() }}</p>
                </div>
            @endforeach
        </div>

        <div class="flex flex-wrap items-center gap-3 px-8 py-4">
            <input type="text" x-model="search" placeholder="Rechercher un nom…"
                class="max-w-[280px] rounded-full border border-[#DEDAD0] px-4 py-2 text-sm">
            <button type="button" @click="activeCategory = 'all'"
                :class="activeCategory === 'all' ? 'bg-[#12213D] text-white' : 'border border-[#DEDAD0]'"
                class="rounded-full px-3 py-1.5 text-xs font-semibold">Tous</button>
            @foreach (\App\Enums\AttendanceCategory::cases() as $category)
                <button type="button" @click="activeCategory = '{{ $category->value }}'"
                    :class="activeCategory === '{{ $category->value }}' ? 'bg-[#12213D] text-white' : 'border border-[#DEDAD0]'"
                    class="rounded-full px-3 py-1.5 text-xs font-semibold">{{ $category->label() }}</button>
            @endforeach
        </div>

        <div class="max-h-[520px] overflow-y-auto px-8 pb-8">
            <template x-for="group in groups" :key="group.category">
                <div class="mb-5">
                    <p class="mb-2 text-xs font-semibold uppercase text-[#8A8474]" x-text="group.records[0].categoryLabel + ' (' + group.records.length + ')'"></p>
                    <template x-for="record in group.records" :key="record.id">
                        <div class="flex items-center justify-between border-b border-[#F2F0EA] py-2.5">
                            <div class="flex items-center gap-3">
                                <div class="flex h-[34px] w-[34px] items-center justify-center rounded-full bg-[#F1EFEA] text-xs font-bold" x-text="initials(record.name)"></div>
                                <div>
                                    <p class="text-[14.5px] font-semibold text-[#12213D]" x-text="record.name"></p>
                                    <p class="text-[12.5px] text-[#8A8474]">
                                        <span x-text="record.title + ' · ' + record.club"></span>
                                        <span x-show="record.isLate" class="font-bold text-[#C77700]"> · marqué en retard</span>
                                    </p>
                                </div>
                            </div>
                            <span class="font-mono text-sm text-[#A39C8C]" x-text="record.phone"></span>
                            <form method="POST" :action="'/admin/attendances/' + record.id + '/toggle-present'">
                                @csrf
                                @method('PATCH')
                                <button type="submit"
                                    :class="record.present ? 'bg-[#E7F5F1] text-[#0E7C66]' : 'border border-[#DEDAD0] text-[#6B6558]'"
                                    class="rounded-lg px-3 py-1.5 text-xs font-semibold">
                                    <span x-text="record.present ? 'Présent' : 'Marquer présent'"></span>
                                </button>
                            </form>
                        </div>
                    </template>
                </div>
            </template>
        </div>
    </div>
</x-layouts.admin>
