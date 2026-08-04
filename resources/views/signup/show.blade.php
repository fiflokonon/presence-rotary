<x-layouts.super-admin title="Inscription">
    <div class="mx-auto max-w-[420px] rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)]">
        <h1 class="font-display text-xl font-extrabold text-navy">Inscrire mon club</h1>

        @if (session('error'))
            <div class="mt-4 rounded-lg bg-error-bg px-4 py-3 text-sm text-error">{{ session('error') }}</div>
        @endif
        @if ($errors->any())
            <div class="mt-4 rounded-lg bg-error-bg px-4 py-3 text-sm text-error">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('signup.store') }}" class="mt-4 flex flex-col gap-4">
            @csrf
            <div class="flex flex-col gap-1.5">
                <label for="club_name" class="text-sm font-semibold">Nom du club</label>
                <input type="text" id="club_name" name="club_name" value="{{ old('club_name') }}" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="admin_name" class="text-sm font-semibold">Votre nom</label>
                <input type="text" id="admin_name" name="admin_name" value="{{ old('admin_name') }}" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="admin_email" class="text-sm font-semibold">Votre email</label>
                <input type="email" id="admin_email" name="admin_email" value="{{ old('admin_email') }}" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="plan_id" class="text-sm font-semibold">Plan</label>
                <select id="plan_id" name="plan_id" required class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
                    @foreach ($plans as $plan)
                        <option value="{{ $plan->id }}">{{ $plan->name }} — {{ number_format($plan->price, 0, ',', ' ') }} FCFA ({{ $plan->duration_months }} mois)</option>
                    @endforeach
                </select>
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="payment_method" class="text-sm font-semibold">Opérateur</label>
                <select id="payment_method" name="payment_method" required class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
                    <option value="mtn_momo">MTN Mobile Money</option>
                    <option value="moov_money">Moov Money</option>
                </select>
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="phone" class="text-sm font-semibold">Numéro de téléphone</label>
                <input type="text" id="phone" name="phone" value="{{ old('phone') }}" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>
            <button type="submit" class="cursor-pointer rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                Payer et créer mon club
            </button>
        </form>
    </div>
</x-layouts.super-admin>
