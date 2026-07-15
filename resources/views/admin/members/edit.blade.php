<x-layouts.admin :title="'Modifier ' . $member->name . ' — Administration'">
    <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)] md:p-8">
        <h1 class="font-display text-xl font-extrabold text-navy">Modifier {{ $member->name }}</h1>

        <form method="POST" action="{{ route('admin.members.update', $member) }}" class="mt-4 flex max-w-md flex-col gap-3">
            @csrf
            @method('PUT')

            <div x-data="{
                    titleId: '{{ old('title_id', $member->title_id) }}',
                    positionId: '{{ old('position_id', $member->position_id) }}',
                    positionsByTitle: {{ Illuminate\Support\Js::from($titles->mapWithKeys(fn ($t) => [
                        $t->id => $t->positions->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values(),
                    ])) }},
                    get availablePositions() { return this.positionsByTitle[this.titleId] ?? [] },
                }"
                class="contents"
            >
                <div class="flex flex-col gap-1.5">
                    <label for="title_id" class="text-sm font-semibold">Titre</label>
                    <select x-model="titleId" id="title_id" name="title_id" required
                        class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
                        <option value="">Sélectionnez…</option>
                        @foreach ($titles as $titleOption)
                            <option value="{{ $titleOption->id }}">{{ $titleOption->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="flex flex-col gap-1.5" x-show="availablePositions.length > 0">
                    <label for="position_id" class="text-sm font-semibold">Poste / Qualité</label>
                    <select x-model="positionId" id="position_id" name="position_id" :required="availablePositions.length > 0"
                        class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
                        <option value="">Sélectionnez…</option>
                        <template x-for="position in availablePositions" :key="position.id">
                            <option :value="position.id" x-text="position.name"></option>
                        </template>
                    </select>
                </div>
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="name" class="text-sm font-semibold">Nom</label>
                <input type="text" id="name" name="name" value="{{ old('name', $member->name) }}" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="club" class="text-sm font-semibold">Club</label>
                <input type="text" id="club" name="club" value="{{ old('club', $member->club) }}" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="phone" class="text-sm font-semibold">Téléphone</label>
                <input type="tel" id="phone" name="phone" value="{{ old('phone', $member->phone) }}" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="classification" class="text-sm font-semibold">Classification</label>
                <input type="text" id="classification" name="classification" value="{{ old('classification', $member->classification) }}"
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="email" class="text-sm font-semibold">Email</label>
                <input type="email" id="email" name="email" value="{{ old('email', $member->email) }}" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>

            <button type="submit"
                class="mt-2 cursor-pointer self-start rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                Enregistrer
            </button>
        </form>

        @if ($errors->any())
            <div class="mt-4 rounded-lg bg-error-bg px-4 py-3 text-sm text-error">
                {{ $errors->first() }}
            </div>
        @endif
    </div>
</x-layouts.admin>
