<form method="POST" action="{{ route('attendance.lookup') }}" class="flex flex-col gap-4 px-6 pb-6 pt-4">
    @csrf

    @if ($errors->any())
        <div class="rounded-lg bg-[#FBEAEA] px-4 py-3 text-sm text-[#B23B3B]">
            Merci de saisir une adresse e-mail valide.
        </div>
    @endif

    <div class="flex flex-col gap-1.5">
        <label for="email" class="text-sm font-semibold text-[#12213D]">Adresse e-mail*</label>
        <input type="email" id="email" name="email" value="{{ old('email') }}" required
            class="rounded-lg border border-[#DEDAD0] px-3 py-2 text-sm">
    </div>

    <button type="submit"
        class="rounded-lg bg-[#12213D] px-4 py-2.5 text-sm font-bold text-white hover:bg-[#1c3559]">
        Continuer
    </button>
</form>
