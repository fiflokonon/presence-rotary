<?php

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;

it('belongs to a tenant and a plan', function () {
    $tenant = Tenant::factory()->create();
    $plan = Plan::factory()->create();
    $subscription = Subscription::factory()->create(['tenant_id' => $tenant->id, 'plan_id' => $plan->id]);

    expect($subscription->tenant->id)->toBe($tenant->id)
        ->and($subscription->plan->id)->toBe($plan->id)
        ->and($subscription->source)->toBe(Subscription::SOURCE_OFFERED);
});

it('supports the expiredDaysAgo factory state', function () {
    $subscription = Subscription::factory()->expiredDaysAgo(3)->create();

    expect($subscription->end_date->isPast())->toBeTrue()
        ->and($subscription->end_date->diffInDays(now()))->toBeGreaterThanOrEqual(3);
});
