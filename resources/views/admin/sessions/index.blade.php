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

        <ul class="mt-6 divide-y divide-[#EDEAE2]">
            @foreach ($meetingSessions as $meetingSession)
                <li class="flex items-center justify-between py-3">
                    <a href="{{ route('admin.sessions.show', $meetingSession) }}" class="text-sm font-semibold text-[#12213D] hover:underline">
                        {{ $meetingSession->title }} — {{ $meetingSession->date->format('d/m/Y') }}
                    </a>
                    <span class="flex items-center gap-2">
                        @if ($meetingSession->is_active)
                            <span class="rounded-full bg-[#E7F5F1] px-2 py-0.5 text-[11px] font-semibold uppercase text-[#0E7C66]">Active</span>
                        @endif
                        <span class="rounded-full {{ $meetingSession->is_open ? 'bg-[#E7F5F1] text-[#0E7C66]' : 'bg-[#F1EFEA] text-[#6B6558]' }} px-2 py-0.5 text-[11px] font-semibold uppercase">
                            {{ $meetingSession->is_open ? 'Ouverte' : 'Clôturée' }}
                        </span>
                    </span>
                </li>
            @endforeach
        </ul>
    </div>
</x-layouts.admin>
