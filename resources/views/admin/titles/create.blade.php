<x-layouts.admin title="Ajouter une organisation — Administration">
    <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)] md:p-8">
        <h1 class="font-display text-xl font-extrabold text-navy">Ajouter une organisation</h1>

        <form method="POST" action="{{ route('admin.titles.store') }}" class="mt-4 flex max-w-md flex-col gap-3">
            @csrf
            <div class="flex flex-col gap-1.5">
                <label for="name" class="text-sm font-semibold">Nom</label>
                <input type="text" id="name" name="name" value="{{ old('name') }}" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="category" class="text-sm font-semibold">Catégorie</label>
                <select id="category" name="category" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
                    <option value="">Sélectionnez…</option>
                    @foreach (\App\Enums\AttendanceCategory::cases() as $categoryOption)
                        <option value="{{ $categoryOption->value }}" @selected(old('category') === $categoryOption->value)>
                            {{ $categoryOption->label() }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="flex flex-col gap-1.5">
                <span class="text-sm font-semibold">Titres/Qualités liés</span>
                <div class="flex flex-col gap-1.5 rounded-lg border border-border p-3">
                    @foreach ($positions as $position)
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="position_ids[]" value="{{ $position->id }}"
                                @checked(collect(old('position_ids', []))->contains($position->id))>
                            {{ $position->name }}
                        </label>
                    @endforeach
                </div>
            </div>
            <button type="submit"
                class="mt-2 cursor-pointer self-start rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                Créer l'organisation
            </button>
        </form>

        @if ($errors->any())
            <div class="mt-4 rounded-lg bg-error-bg px-4 py-3 text-sm text-error">
                {{ $errors->first() }}
            </div>
        @endif
    </div>
</x-layouts.admin>
