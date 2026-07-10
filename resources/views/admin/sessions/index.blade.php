<x-layouts.admin title="Séances — Administration">
    <div class="rounded-xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)]">
        <h1 class="font-display text-xl font-extrabold text-[#12213D]">Séances</h1>

        <form method="POST" action="{{ route('admin.sessions.store') }}" class="mt-4 flex flex-wrap items-end gap-3">
            @csrf
            <div class="flex flex-col gap-1.5">
                <label for="title" class="text-sm font-semibold">Titre</label>
                <input type="text" id="title" name="title" value="{{ old('title') }}" required
                    class="rounded-lg border border-[#DEDAD0] px-3 py-2 text-sm">
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="date" class="text-sm font-semibold">Date</label>
                <input type="date" id="date" name="date" value="{{ old('date') }}" required
                    class="rounded-lg border border-[#DEDAD0] px-3 py-2 text-sm">
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="time" class="text-sm font-semibold">Heure</label>
                <input type="time" id="time" name="time" value="{{ old('time') }}" required
                    class="rounded-lg border border-[#DEDAD0] px-3 py-2 text-sm">
            </div>
            <button type="submit"
                class="rounded-lg bg-[#12213D] px-4 py-2.5 text-sm font-bold text-white hover:bg-[#1c3559]">
                Créer et activer
            </button>
        </form>

        @if ($errors->any())
            <div class="mt-4 rounded-lg bg-[#FBEAEA] px-4 py-3 text-sm text-[#B23B3B]">
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
                class="mt-6 max-w-[280px] rounded-full border border-[#DEDAD0] px-4 py-2 text-sm">

            <ul class="mt-4 divide-y divide-[#EDEAE2]">
                <template x-for="session in filtered" :key="session.id">
                    <li class="flex items-center justify-between py-3">
                        <a :href="session.url" class="text-sm font-semibold text-[#12213D] hover:underline">
                            <span x-text="session.title"></span> — <span x-text="session.date"></span>
                        </a>
                        <span class="flex items-center gap-2">
                            <span x-show="session.isActive" class="rounded-full bg-[#E7F5F1] px-2 py-0.5 text-[11px] font-semibold uppercase text-[#0E7C66]">Active</span>
                            <span :class="session.isOpen ? 'bg-[#E7F5F1] text-[#0E7C66]' : 'bg-[#F1EFEA] text-[#6B6558]'" class="rounded-full px-2 py-0.5 text-[11px] font-semibold uppercase" x-text="session.isOpen ? 'Ouverte' : 'Clôturée'"></span>
                        </span>
                    </li>
                </template>
            </ul>
        </div>
    </div>
</x-layouts.admin>
