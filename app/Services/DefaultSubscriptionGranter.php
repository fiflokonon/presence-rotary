<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;

class DefaultSubscriptionGranter
{
    /**
     * Grant an offered subscription for the given plan to every tenant that
     * does not already have one. Idempotent: re-running never duplicates.
     */
    public function grantOfferedPlanToAllTenants(Plan $plan): int
    {
        $granted = 0;

        Tenant::all()->each(function (Tenant $tenant) use ($plan, &$granted): void {
            $subscription = Subscription::firstOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'plan_id' => $plan->id,
                    'source' => Subscription::SOURCE_OFFERED,
                ],
                [
                    'transaction_id' => null,
                    'amount' => 0,
                    'start_date' => now(),
                    'end_date' => now()->addMonths($plan->duration_months),
                ],
            );

            if ($subscription->wasRecentlyCreated) {
                $granted++;
            }
        });

        return $granted;
    }
}
