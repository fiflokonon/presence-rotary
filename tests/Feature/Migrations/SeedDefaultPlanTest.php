<?php

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\DefaultSubscriptionGranter;

it('seeds an active 3-month default plan', function () {
    $plan = Plan::where('duration_months', 3)->where('name', 'Trimestriel')->first();

    expect($plan)->not->toBeNull()
        ->and($plan->is_active)->toBeTrue()
        ->and((int) $plan->price)->toBe(15000);
});

it('grants an offered 3-month subscription to every existing club', function () {
    $plan = Plan::where('duration_months', 3)->where('name', 'Trimestriel')->firstOrFail();
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    app(DefaultSubscriptionGranter::class)->grantOfferedPlanToAllTenants($plan);

    foreach ([$tenantA, $tenantB] as $tenant) {
        $subscription = Subscription::where('tenant_id', $tenant->id)
            ->where('plan_id', $plan->id)
            ->where('source', Subscription::SOURCE_OFFERED)
            ->first();

        expect($subscription)->not->toBeNull()
            ->and($subscription->end_date->toDateString())->toBe(now()->addMonths(3)->toDateString());
    }
});

it('does not duplicate the offered subscription when granted twice', function () {
    $plan = Plan::where('duration_months', 3)->where('name', 'Trimestriel')->firstOrFail();
    $tenant = Tenant::factory()->create();

    $granter = app(DefaultSubscriptionGranter::class);
    $granter->grantOfferedPlanToAllTenants($plan);
    $granter->grantOfferedPlanToAllTenants($plan);

    expect(
        Subscription::where('tenant_id', $tenant->id)
            ->where('plan_id', $plan->id)
            ->where('source', Subscription::SOURCE_OFFERED)
            ->count()
    )->toBe(1);
});
