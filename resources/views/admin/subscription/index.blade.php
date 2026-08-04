<x-layouts.admin title="Souscription">
    <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)]">
        <h1 class="font-display text-xl font-extrabold text-navy">Souscription</h1>

        @if (session('status'))
            <div class="mt-4 rounded-lg bg-cream px-4 py-3 text-sm text-navy">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div class="mt-4 rounded-lg bg-error-bg px-4 py-3 text-sm text-error">{{ session('error') }}</div>
        @endif

        @if ($currentSubscription)
            <div class="mt-4 rounded-lg border border-divider p-4">
                <p class="text-sm text-muted">Plan actuel</p>
                <p class="font-semibold text-navy">{{ $currentSubscription->plan->name }}</p>
                <p class="mt-2 text-sm text-muted">Statut : <span class="font-semibold">{{ ['active' => 'actif', 'grace' => 'en délai de grâce', 'blocked' => 'bloqué'][$accessState] }}</span></p>
                <p class="text-sm text-muted">Expire le {{ $currentSubscription->end_date->format('d/m/Y') }}</p>
            </div>
        @else
            <p class="mt-4 text-sm text-muted">Aucune souscription pour le moment.</p>
        @endif

        <h2 class="mt-8 font-display text-lg font-bold text-navy">Renouveler</h2>
        <form method="POST" action="{{ route('admin.subscription.checkout') }}" class="mt-4 flex flex-col gap-4 max-w-[420px]">
            @csrf
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
                <input type="text" id="phone" name="phone" required class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>
            <button type="submit" class="cursor-pointer rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                Payer
            </button>
        </form>

        @if ($history->isNotEmpty())
            <h2 class="mt-8 font-display text-lg font-bold text-navy">Historique</h2>
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-divider text-muted-strong">
                            <th class="py-2 pr-4 font-semibold">Plan</th>
                            <th class="py-2 pr-4 font-semibold">Période</th>
                            <th class="py-2 pr-4 font-semibold">Source</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-divider">
                        @foreach ($history as $subscription)
                            <tr>
                                <td class="py-2 pr-4">{{ $subscription->plan->name }}</td>
                                <td class="py-2 pr-4">{{ $subscription->start_date->format('d/m/Y') }} — {{ $subscription->end_date->format('d/m/Y') }}</td>
                                <td class="py-2 pr-4">{{ $subscription->source === 'paid' ? 'Payé' : 'Offert' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-layouts.admin>
