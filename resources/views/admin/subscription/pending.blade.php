<x-layouts.admin title="Paiement en cours">
    <div class="mx-auto max-w-[420px] rounded-2xl bg-white p-6 text-center shadow-[0_2px_10px_rgba(20,30,50,.06)]">
        <h1 class="font-display text-xl font-extrabold text-navy">Paiement en cours</h1>
        <p class="mt-3 text-sm text-muted">
            Confirmez le paiement mobile money sur votre téléphone. Cette page se mettra à jour automatiquement.
        </p>
        <p class="mt-4 text-xs text-muted" id="status-message">Vérification en cours...</p>
    </div>

    <script>
        (function () {
            const token = @json($token);
            const statusUrl = @json(route('admin.subscription.status'));
            const statusMessage = document.getElementById('status-message');

            function poll() {
                fetch(`${statusUrl}?token=${encodeURIComponent(token)}`)
                    .then((response) => response.json())
                    .then((data) => {
                        if (data.status === 'completed') {
                            window.location.href = @json(route('admin.subscription.index'));
                            return;
                        }
                        if (data.status === 'failed') {
                            statusMessage.textContent = data.message;
                            return;
                        }
                        statusMessage.textContent = data.message;
                        setTimeout(poll, 3000);
                    });
            }

            poll();
        })();
    </script>
</x-layouts.admin>
