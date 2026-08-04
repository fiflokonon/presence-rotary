<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

class SubscriptionActivationService
{
    public function __construct(
        private readonly PayPlusGateway $gateway,
        private readonly TenantProvisioningService $provisioningService,
    ) {}

    /**
     * @return array{success: bool, status: string, message: string}
     */
    public function activateFromToken(string $token): array
    {
        $status = $this->gateway->fetchStatus($token);

        if (! $status['success']) {
            return ['success' => false, 'status' => 'pending', 'message' => 'Vérification en cours...'];
        }

        return match ($status['status']) {
            'completed' => $this->activate($status),
            'pending' => ['success' => true, 'status' => 'pending', 'message' => 'Paiement en attente de confirmation...'],
            default => ['success' => true, 'status' => 'failed', 'message' => 'Le paiement a échoué. Veuillez réessayer.'],
        };
    }

    /**
     * @param  array{custom_data: array<string, mixed>}  $apiStatus
     * @return array{success: bool, status: string, message: string}
     */
    private function activate(array $apiStatus): array
    {
        $reference = $apiStatus['custom_data']['reference'] ?? null;

        if ($reference === null) {
            return ['success' => false, 'status' => 'failed', 'message' => 'Données de paiement incomplètes'];
        }

        $transaction = Transaction::where('reference', $reference)->first();

        if ($transaction === null) {
            return ['success' => false, 'status' => 'failed', 'message' => 'Transaction introuvable'];
        }

        if ($transaction->status === Transaction::STATUS_COMPLETED) {
            return ['success' => true, 'status' => 'completed', 'message' => 'Abonnement déjà activé'];
        }

        DB::connection('central')->transaction(function () use ($transaction) {
            $transaction->update(['status' => Transaction::STATUS_COMPLETED, 'paid_at' => now()]);

            $tenant = $transaction->tenant_id !== null
                ? Tenant::findOrFail($transaction->tenant_id)
                : $this->provisionFromSelfService($transaction);

            $plan = Plan::findOrFail($transaction->plan_id);
            $current = $tenant->currentSubscription();
            $startDate = ($current !== null && $current->end_date->isFuture()) ? $current->end_date : now();

            Subscription::create([
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                'transaction_id' => $transaction->id,
                'source' => Subscription::SOURCE_PAID,
                'amount' => $transaction->amount,
                'start_date' => $startDate,
                'end_date' => $startDate->copy()->addMonths($plan->duration_months),
            ]);
        });

        return ['success' => true, 'status' => 'completed', 'message' => 'Abonnement activé avec succès'];
    }

    private function provisionFromSelfService(Transaction $transaction): Tenant
    {
        $metadata = $transaction->metadata ?? [];

        $tenant = $this->provisioningService->provision(
            $metadata['club_name'],
            $this->provisioningService->generateUniqueHost($metadata['club_name']),
            $metadata['admin_name'],
            $metadata['admin_email'],
        );

        $transaction->update(['tenant_id' => $tenant->id]);

        return $tenant;
    }
}
