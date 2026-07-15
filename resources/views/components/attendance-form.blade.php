@props(['late' => false, 'email', 'member' => null, 'titles'])

<form method="POST" action="{{ route('attendance.store') }}" class="flex flex-col gap-4 px-6 pb-6 pt-4">
    @csrf
    <input type="hidden" name="email" value="{{ $email }}">

    @if ($late)
        <div class="rounded-lg bg-[#FDF3E2] px-4 py-3 text-sm font-semibold text-[#C77700]">
            ⏱ Séance clôturée — cette réponse sera enregistrée comme présence en retard.
        </div>
    @endif

    @if ($errors->any())
        <div class="rounded-lg bg-[#FBEAEA] px-4 py-3 text-sm text-[#B23B3B]">
            * Merci de remplir les champs obligatoires.
        </div>
    @endif

    <div class="flex flex-col gap-1.5">
        <span class="text-sm font-semibold text-[#12213D]">Adresse e-mail</span>
        <p class="rounded-lg border border-[#DEDAD0] bg-[#F1EFEA] px-3 py-2 text-sm text-[#8A8474]">{{ $email }}</p>
        <a href="{{ route('attendance.show') }}" class="text-xs font-semibold text-[#12213D] underline">
            Changer d'adresse e-mail
        </a>
    </div>

    <div x-data="{
            titleId: '{{ old('title_id', $member?->title_id) }}',
            positionId: '{{ old('position_id', $member?->position_id) }}',
            positionsByTitle: {{ Illuminate\Support\Js::from($titles->mapWithKeys(fn ($t) => [
                $t->id => $t->positions->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values(),
            ])) }},
            get availablePositions() { return this.positionsByTitle[this.titleId] ?? [] },
        }"
        class="contents"
    >
        <div class="flex flex-col gap-1.5">
            <label for="title_id" class="text-sm font-semibold text-[#12213D]">Titre*</label>
            <select x-model="titleId" id="title_id" name="title_id" required
                class="rounded-lg border border-[#DEDAD0] px-3 py-2 text-sm">
                <option value="">Sélectionnez…</option>
                @foreach ($titles as $titleOption)
                    <option value="{{ $titleOption->id }}">{{ $titleOption->name }}</option>
                @endforeach
            </select>
        </div>

        <div class="flex flex-col gap-1.5" x-show="availablePositions.length > 0">
            <label for="position_id" class="text-sm font-semibold text-[#12213D]">Poste / Qualité*</label>
            <select x-model="positionId" id="position_id" name="position_id" :required="availablePositions.length > 0"
                class="rounded-lg border border-[#DEDAD0] px-3 py-2 text-sm">
                <option value="">Sélectionnez…</option>
                <template x-for="position in availablePositions" :key="position.id">
                    <option :value="position.id" x-text="position.name"></option>
                </template>
            </select>
        </div>
    </div>

    <div class="flex flex-col gap-1.5">
        <label for="name" class="text-sm font-semibold text-[#12213D]">Nom et prénoms*</label>
        <input type="text" id="name" name="name" value="{{ old('name', $member?->name) }}" required
            class="rounded-lg border border-[#DEDAD0] px-3 py-2 text-sm">
    </div>

    <div class="flex flex-col gap-1.5">
        <label for="club" class="text-sm font-semibold text-[#12213D]">Votre club*</label>
        <input type="text" id="club" name="club" value="{{ old('club', $member?->club) }}" required
            class="rounded-lg border border-[#DEDAD0] px-3 py-2 text-sm">
    </div>

    <div class="flex flex-col gap-1.5">
        <label for="phone" class="text-sm font-semibold text-[#12213D]">Numéro de téléphone*</label>
        <input type="tel" id="phone" name="phone" value="{{ old('phone', $member?->phone) }}" required
            class="rounded-lg border border-[#DEDAD0] px-3 py-2 text-sm">
    </div>

    <div class="flex flex-col gap-1.5">
        <label for="classification" class="text-sm font-semibold text-[#12213D]">Classification</label>
        <input type="text" id="classification" name="classification" value="{{ old('classification', $member?->classification) }}"
            class="rounded-lg border border-[#DEDAD0] px-3 py-2 text-sm">
    </div>

    <button type="submit"
        class="rounded-lg bg-[#12213D] px-4 py-2.5 text-sm font-bold text-white hover:bg-[#1c3559]">
        Envoyer
    </button>
    <button type="reset" class="text-sm font-semibold text-[#C77700]">
        Effacer le formulaire
    </button>
</form>
