<x-layouts.admin title="Identité du club — Administration">
    <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)] md:p-8">
        <h1 class="font-display text-xl font-extrabold text-navy">Identité du club</h1>
        <p class="mt-1 text-sm text-muted">
            Ces informations apparaissent sur le formulaire de présence, l'export PDF et les emails envoyés.
        </p>

        @if (session('status'))
            <div class="mt-4 rounded-lg bg-success-bg px-4 py-3 text-sm text-success">
                {{ session('status') }}
            </div>
        @endif

        <form method="POST" action="{{ route('admin.club-settings.update') }}" enctype="multipart/form-data" class="mt-4 flex max-w-md flex-col gap-3">
            @csrf
            @method('PUT')

            <div class="flex flex-col gap-1.5">
                <label for="name" class="text-sm font-semibold">Nom</label>
                <input type="text" id="name" name="name" value="{{ old('name', $clubSetting?->name) }}" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="tagline" class="text-sm font-semibold">Sous-titre</label>
                <input type="text" id="tagline" name="tagline" value="{{ old('tagline', $clubSetting?->tagline) }}"
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="logo" class="text-sm font-semibold">Logo</label>
                @if ($clubSetting)
                    <img src="{{ $clubSetting->logoUrl() }}" alt="Logo actuel" class="h-16 w-auto object-contain">
                @endif
                <input type="file" id="logo" name="logo" accept="image/png,image/jpeg,image/svg+xml" class="text-sm">
            </div>

            <div class="flex flex-col gap-1.5" x-data="{ color: '{{ old('primary_color', $clubSetting?->primary_color ?? '#0B73C5') }}' }">
                <label for="primary_color" class="text-sm font-semibold">Couleur primaire</label>
                <div class="flex items-center gap-2">
                    <input type="color" x-model="color" class="h-9 w-12 cursor-pointer rounded border border-border">
                    <input type="text" id="primary_color" name="primary_color" x-model="color" required
                        class="w-28 rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
                </div>
            </div>

            <div class="flex flex-col gap-1.5" x-data="{ color: '{{ old('secondary_color', $clubSetting?->secondary_color ?? '#17A8E5') }}' }">
                <label for="secondary_color" class="text-sm font-semibold">Couleur secondaire</label>
                <div class="flex items-center gap-2">
                    <input type="color" x-model="color" class="h-9 w-12 cursor-pointer rounded border border-border">
                    <input type="text" id="secondary_color" name="secondary_color" x-model="color" required
                        class="w-28 rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
                </div>
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="address" class="text-sm font-semibold">Adresse</label>
                <input type="text" id="address" name="address" value="{{ old('address', $clubSetting?->address) }}"
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="phone" class="text-sm font-semibold">Téléphone</label>
                <input type="text" id="phone" name="phone" value="{{ old('phone', $clubSetting?->phone) }}"
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="email" class="text-sm font-semibold">Email</label>
                <input type="email" id="email" name="email" value="{{ old('email', $clubSetting?->email) }}"
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="website" class="text-sm font-semibold">Site web</label>
                <input type="url" id="website" name="website" value="{{ old('website', $clubSetting?->website) }}"
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="facebook_url" class="text-sm font-semibold">Facebook</label>
                <input type="url" id="facebook_url" name="facebook_url" value="{{ old('facebook_url', $clubSetting?->facebook_url) }}"
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="instagram_url" class="text-sm font-semibold">Instagram</label>
                <input type="url" id="instagram_url" name="instagram_url" value="{{ old('instagram_url', $clubSetting?->instagram_url) }}"
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
