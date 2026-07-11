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
        class="rounded-2xl bg-white shadow-[0_2px_10px_rgba(20,30,50,.06)]"
    >
        <div class="border-b border-divider px-4 pb-5 pt-6 md:px-8 md:pt-7">
            <a href="{{ route('admin.sessions.index') }}"
                class="inline-flex cursor-pointer items-center gap-1 text-sm font-semibold text-muted hover:text-navy">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
                </svg>
                Retour aux séances
            </a>
            <p class="mt-3 text-[11px] font-semibold uppercase text-gold">RC Cotonou Nexus · District 9103</p>
            <div class="mt-1 flex flex-col gap-4 md:flex-row md:flex-wrap md:items-start md:justify-between">
                <div>
                    <h1 class="font-display text-2xl font-extrabold text-navy">{{ $meetingSession->title }}</h1>
                    <p class="text-[15px] text-muted">{{ $meetingSession->date->translatedFormat('d F Y') }}</p>
                </div>
                <div class="flex flex-col gap-3 md:flex-row md:items-center">
                    <div x-data="qrCodePanel(@js(route('attendance.show')))" class="relative">
                        <button type="button" @click="toggle()"
                            class="cursor-pointer w-full rounded-lg border border-border px-4 py-2 text-sm font-bold text-navy hover:bg-cream md:w-auto">
                            QR code
                        </button>
                        <div x-show="open" x-cloak @click.outside="open = false"
                            class="absolute right-0 top-full z-10 mt-2 w-72 rounded-xl border border-divider bg-white p-4 shadow-[0_2px_10px_rgba(20,30,50,.06)]">
                            <canvas x-ref="canvas" class="mx-auto"></canvas>
                            <p class="mt-3 break-all text-center text-xs text-muted-strong" x-text="url"></p>
                            <div class="mt-3 flex gap-2">
                                <button type="button" @click="share()"
                                    class="cursor-pointer flex-1 rounded-lg bg-navy px-3 py-2 text-xs font-bold text-white">
                                    <span x-show="!copied">Partager le lien</span>
                                    <span x-show="copied" x-cloak>Lien copié ✓</span>
                                </button>
                                <button type="button" @click="download()"
                                    class="cursor-pointer flex-1 rounded-lg border border-border px-3 py-2 text-xs font-bold text-navy">
                                    Télécharger
                                </button>
                            </div>
                        </div>
                    </div>
                    <a href="{{ route('admin.sessions.export-pdf', $meetingSession) }}"
                        class="cursor-pointer w-full rounded-lg bg-navy px-4 py-2 text-center text-sm font-bold text-white hover:bg-navy-hover md:w-auto">
                        Exporter en PDF
                    </a>
                    <span class="w-full rounded-full {{ $meetingSession->is_open ? 'bg-success-bg text-success' : 'bg-divider text-muted' }} px-3 py-1 text-center text-xs font-semibold md:w-auto">
                        ● {{ $meetingSession->is_open ? 'Séance ouverte' : 'Séance clôturée' }}
                    </span>
                    @if ($meetingSession->is_open)
                        <div
                            x-data="closeSessionPanel(@js($upcomingSessions->isNotEmpty() ? 'session:'.$upcomingSessions->first()->id : 'manual'))"
                            class="relative w-full md:w-auto"
                        >
                            <button type="button" @click="toggle()"
                                class="cursor-pointer w-full text-sm font-semibold text-navy underline md:w-auto">
                                Clôturer la séance
                            </button>
                            <div x-show="open" x-cloak @click.outside="open = false"
                                class="absolute right-0 top-full z-10 mt-2 w-80 rounded-xl border border-divider bg-white p-4 text-left shadow-[0_2px_10px_rgba(20,30,50,.06)]">
                                <form method="POST" action="{{ route('admin.sessions.toggle-open', $meetingSession) }}" class="flex flex-col gap-3">
                                    @csrf
                                    <label class="flex items-start gap-2 text-sm text-navy">
                                        <input type="checkbox" name="send_thank_you_email" value="1" x-model="sendThankYouEmail" class="mt-0.5">
                                        Envoyer un mail de remerciement aux présents
                                    </label>
                                    <label x-show="sendThankYouEmail" x-cloak class="flex items-start gap-2 text-sm text-navy">
                                        <input type="checkbox" name="mention_next_session" value="1" x-model="mentionNextSession" class="mt-0.5">
                                        Mentionner la prochaine séance
                                    </label>
                                    <div x-show="sendThankYouEmail && mentionNextSession" x-cloak class="flex flex-col gap-2">
                                        @if ($upcomingSessions->isNotEmpty())
                                            <select name="next_session_option" x-model="nextSessionOption"
                                                class="rounded-lg border border-border px-3 py-2 text-sm">
                                                @foreach ($upcomingSessions as $upcomingSession)
                                                    <option value="session:{{ $upcomingSession->id }}">
                                                        {{ $upcomingSession->title }} — {{ $upcomingSession->date->translatedFormat('d F Y') }}
                                                    </option>
                                                @endforeach
                                                <option value="manual">Autre date…</option>
                                            </select>
                                        @endif
                                        <input type="date" name="next_session_date" x-show="nextSessionOption === 'manual'" x-cloak
                                            class="rounded-lg border border-border px-3 py-2 text-sm">
                                    </div>
                                    <button type="submit"
                                        class="cursor-pointer rounded-lg bg-navy px-4 py-2 text-sm font-bold text-white hover:bg-navy-hover">
                                        Confirmer la clôture
                                    </button>
                                </form>
                            </div>
                        </div>
                    @else
                        <form method="POST" action="{{ route('admin.sessions.toggle-open', $meetingSession) }}" class="w-full md:w-auto">
                            @csrf
                            <button type="submit" class="cursor-pointer w-full text-sm font-semibold text-navy underline md:w-auto">
                                Rouvrir la séance
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-3 px-4 py-5 md:grid-cols-5 md:px-8">
            <div class="rounded-lg bg-navy p-3 text-white">
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

        <div class="flex flex-wrap items-center gap-3 px-4 py-4 md:px-8">
            <input type="text" x-model="search" placeholder="Rechercher un nom…"
                class="w-full max-w-[280px] rounded-full border border-border px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            <select x-model="activeTitle"
                class="cursor-pointer rounded-lg border border-border px-3 py-2 text-sm">
                <option value="all">Tous les titres</option>
                <template x-for="option in titleOptions" :key="option">
                    <option :value="option" x-text="option"></option>
                </template>
            </select>
            <button type="button" @click="activeCategory = 'all'"
                :class="activeCategory === 'all' ? 'bg-navy text-white' : 'border border-border text-navy'"
                class="cursor-pointer rounded-full px-3 py-2 text-xs font-semibold md:py-1.5">Tous</button>
            @foreach (\App\Enums\AttendanceCategory::cases() as $category)
                <button type="button" @click="activeCategory = '{{ $category->value }}'"
                    :class="activeCategory === '{{ $category->value }}' ? 'bg-navy text-white' : 'border border-border text-navy'"
                    class="cursor-pointer rounded-full px-3 py-2 text-xs font-semibold md:py-1.5">{{ $category->label() }}</button>
            @endforeach
        </div>

        <div class="max-h-[520px] overflow-y-auto px-4 pb-8 md:px-8">
            <template x-for="group in groups" :key="group.category">
                <div class="mb-5">
                    <p class="mb-2 text-xs font-semibold uppercase text-muted-strong" x-text="group.records[0].categoryLabel + ' (' + group.records.length + ')'"></p>
                    <template x-for="record in group.records" :key="record.id">
                        <div class="flex flex-col gap-2 border-b border-divider py-2.5 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex items-center gap-3">
                                <div class="flex h-[34px] w-[34px] shrink-0 items-center justify-center rounded-full bg-divider text-xs font-bold" x-text="initials(record.name)"></div>
                                <div>
                                    <p class="text-[14.5px] font-semibold text-navy" x-text="record.name"></p>
                                    <p class="text-[12.5px] text-muted-strong">
                                        <span x-text="record.title + ' · ' + record.club"></span>
                                        <span x-show="record.isLate" class="font-bold text-gold"> · marqué en retard</span>
                                    </p>
                                    <p class="mt-0.5 font-mono text-xs text-muted-strong sm:hidden" x-text="record.phone"></p>
                                </div>
                            </div>
                            <div class="flex items-center justify-between gap-3 sm:justify-end">
                                <span class="hidden font-mono text-sm text-muted-strong sm:inline" x-text="record.phone"></span>
                                <form method="POST" :action="'/admin/attendances/' + record.id + '/toggle-present'">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit"
                                        :class="record.present ? 'bg-success-bg text-success' : 'border border-border text-muted'"
                                        class="cursor-pointer rounded-lg px-3 py-1.5 text-xs font-semibold">
                                        <span x-text="record.present ? 'Présent' : 'Marquer présent'"></span>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </template>
                </div>
            </template>
        </div>
    </div>
</x-layouts.admin>
