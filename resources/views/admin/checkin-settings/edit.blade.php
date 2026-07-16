<x-layouts.admin title="Paramètres du formulaire — Administration">
    <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)] md:p-8">
        <h1 class="font-display text-xl font-extrabold text-navy">Paramètres du formulaire</h1>
        <p class="mt-1 text-sm text-muted">
            Contrôlez les options proposées sur le formulaire public de présence.
        </p>

        @if (session('status'))
            <div class="mt-4 rounded-lg bg-success-bg px-4 py-3 text-sm text-success">
                {{ session('status') }}
            </div>
        @endif

        <form method="POST" action="{{ route('admin.checkin-settings.update') }}" class="mt-4 flex max-w-md flex-col gap-3">
            @csrf
            @method('PUT')
            <label class="flex items-center gap-2 text-sm font-semibold">
                <input type="checkbox" name="show_guest_option" value="1"
                    @checked(old('show_guest_option', $checkinSetting?->show_guest_option ?? true))>
                Afficher l'option « Invité » sur le formulaire de présence
            </label>
            <button type="submit"
                class="mt-2 cursor-pointer self-start rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                Enregistrer
            </button>
        </form>
    </div>
</x-layouts.admin>
