<x-layouts.admin title="Paramètres mail — Administration">
    <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)] md:p-8">
        <h1 class="font-display text-xl font-extrabold text-navy">Paramètres mail</h1>
        <p class="mt-1 text-sm text-muted">
            Configurez le serveur SMTP utilisé pour envoyer les emails (identifiants d'admin, remerciements de présence).
        </p>

        @if (session('status'))
            <div class="mt-4 rounded-lg bg-success-bg px-4 py-3 text-sm text-success">
                {{ session('status') }}
            </div>
        @endif

        <form method="POST" action="{{ route('admin.mail-settings.update') }}" class="mt-4 flex max-w-md flex-col gap-3">
            @csrf
            @method('PUT')
            <div class="flex flex-col gap-1.5">
                <label for="host" class="text-sm font-semibold">Hôte SMTP</label>
                <input type="text" id="host" name="host" value="{{ old('host', $mailSetting?->host) }}" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="port" class="text-sm font-semibold">Port</label>
                <input type="number" id="port" name="port" value="{{ old('port', $mailSetting?->port) }}" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="username" class="text-sm font-semibold">Utilisateur</label>
                <input type="text" id="username" name="username" value="{{ old('username', $mailSetting?->username) }}"
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="password" class="text-sm font-semibold">Mot de passe</label>
                <input type="password" id="password" name="password" value=""
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
                <p class="text-xs text-muted">Laisser vide pour conserver le mot de passe actuel.</p>
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="encryption" class="text-sm font-semibold">Chiffrement</label>
                <select id="encryption" name="encryption"
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
                    <option value="" {{ old('encryption', $mailSetting?->encryption) === null ? 'selected' : '' }}>Aucun</option>
                    <option value="tls" {{ old('encryption', $mailSetting?->encryption) === 'tls' ? 'selected' : '' }}>TLS</option>
                    <option value="ssl" {{ old('encryption', $mailSetting?->encryption) === 'ssl' ? 'selected' : '' }}>SSL</option>
                </select>
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="from_address" class="text-sm font-semibold">Adresse d'expédition</label>
                <input type="email" id="from_address" name="from_address" value="{{ old('from_address', $mailSetting?->from_address) }}" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="from_name" class="text-sm font-semibold">Nom d'expédition</label>
                <input type="text" id="from_name" name="from_name" value="{{ old('from_name', $mailSetting?->from_name) }}" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>
            <button type="submit"
                class="mt-2 cursor-pointer self-start rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                Enregistrer
            </button>
        </form>

        @if ($mailSetting)
            <form method="POST" action="{{ route('admin.mail-settings.test') }}" class="mt-6 flex max-w-md flex-col gap-3 border-t border-border pt-6">
                @csrf
                <label for="test_email" class="text-sm font-semibold">Envoyer un mail de test</label>
                <div class="flex gap-2">
                    <input type="email" id="test_email" name="test_email" placeholder="destinataire@example.com" required
                        class="flex-1 rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
                    <button type="submit"
                        class="cursor-pointer rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                        Envoyer
                    </button>
                </div>
            </form>
        @endif

        @if ($errors->any())
            <div class="mt-4 rounded-lg bg-error-bg px-4 py-3 text-sm text-error">
                {{ $errors->first() }}
            </div>
        @endif
    </div>
</x-layouts.admin>
