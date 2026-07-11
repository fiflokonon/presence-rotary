<x-layouts.admin title="Séances — Administration">
    <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)] md:p-8">
        <h1 class="font-display text-xl font-extrabold text-navy">Séances</h1>

        <form method="POST" action="{{ route('admin.sessions.store') }}" class="mt-4 flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end">
            @csrf
            <div class="flex flex-col gap-1.5">
                <label for="title" class="text-sm font-semibold">Titre</label>
                <input type="text" id="title" name="title" value="{{ old('title') }}" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="date" class="text-sm font-semibold">Date</label>
                <input type="date" id="date" name="date" value="{{ old('date') }}" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="time" class="text-sm font-semibold">Heure</label>
                <input type="time" id="time" name="time" value="{{ old('time') }}" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>
            <button type="submit"
                class="cursor-pointer rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                Créer et activer
            </button>
        </form>

        @if ($errors->any())
            <div class="mt-4 rounded-lg bg-error-bg px-4 py-3 text-sm text-error">
                {{ $errors->first() }}
            </div>
        @endif

        <div
            x-data="sessionsList(@js($meetingSessions->map(fn ($meetingSession) => [
                'id' => $meetingSession->id,
                'title' => $meetingSession->title,
                'date' => $meetingSession->date->format('d/m/Y'),
                'url' => route('admin.sessions.show', $meetingSession),
                'isActive' => $meetingSession->is_active,
                'isOpen' => $meetingSession->is_open,
            ])))"
        >
            <input type="text" x-model="search" placeholder="Rechercher un titre…"
                class="mt-6 w-full max-w-[280px] rounded-full border border-border px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">

            <ul class="mt-4 divide-y divide-divider">
                <template x-for="session in filtered" :key="session.id">
                    <li>
                        <a :href="session.url"
                            class="flex cursor-pointer items-center justify-between gap-3 rounded-lg py-3 pl-2 pr-2 hover:bg-cream">
                            <span class="text-sm font-semibold text-navy">
                                <span x-text="session.title"></span> — <span x-text="session.date"></span>
                            </span>
                            <span class="flex items-center gap-2">
                                <span x-show="session.isActive" class="rounded-full bg-success-bg px-2 py-0.5 text-[11px] font-semibold uppercase text-success">Active</span>
                                <span :class="session.isOpen ? 'bg-success-bg text-success' : 'bg-divider text-muted'" class="rounded-full px-2 py-0.5 text-[11px] font-semibold uppercase" x-text="session.isOpen ? 'Ouverte' : 'Clôturée'"></span>
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-muted-strong" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                </svg>
                                <span class="sr-only">Voir les détails</span>
                            </span>
                        </a>
                    </li>
                </template>
            </ul>
        </div>
    </div>
</x-layouts.admin>
