<?php

use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\Tenant;

it('is active while now is before the subscription end date', function () {
    $tenant = Tenant::factory()->create();
    Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'start_date' => now()->subDay(),
        'end_date' => now()->addDays(10),
    ]);

    expect($tenant->accessState())->toBe(Tenant::ACCESS_ACTIVE);
});

it('is in grace when past end date but within the platform default grace period', function () {
    PlatformSetting::current()->update(['default_grace_period_days' => 7]);
    $tenant = Tenant::factory()->create(['grace_period_days' => null]);
    Subscription::factory()->expiredDaysAgo(3)->create(['tenant_id' => $tenant->id]);

    expect($tenant->accessState())->toBe(Tenant::ACCESS_GRACE);
});

it('is blocked once past end date and the grace period', function () {
    PlatformSetting::current()->update(['default_grace_period_days' => 7]);
    $tenant = Tenant::factory()->create(['grace_period_days' => null]);
    Subscription::factory()->expiredDaysAgo(10)->create(['tenant_id' => $tenant->id]);

    expect($tenant->accessState())->toBe(Tenant::ACCESS_BLOCKED);
});

it('prefers a per-tenant grace_period_days override over the platform default', function () {
    PlatformSetting::current()->update(['default_grace_period_days' => 1]);
    $tenant = Tenant::factory()->create(['grace_period_days' => 30]);
    Subscription::factory()->expiredDaysAgo(5)->create(['tenant_id' => $tenant->id]);

    expect($tenant->accessState())->toBe(Tenant::ACCESS_GRACE);
});

it('is blocked when it has no subscription at all', function () {
    $tenant = Tenant::factory()->create();

    expect($tenant->accessState())->toBe(Tenant::ACCESS_BLOCKED);
});

it('uses the subscription with the latest end date as current', function () {
    $tenant = Tenant::factory()->create();
    Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'start_date' => now()->subMonths(2),
        'end_date' => now()->subMonth(),
    ]);
    Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'start_date' => now(),
        'end_date' => now()->addMonth(),
    ]);

    expect($tenant->accessState())->toBe(Tenant::ACCESS_ACTIVE);
});
