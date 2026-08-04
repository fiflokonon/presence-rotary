<?php

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\TenantContext;

it('shows the current plan, end date, and access state', function () {
    $tenant = app(TenantContext::class)->current();
    $plan = Plan::factory()->create(['name' => 'Mensuel Actuel']);
    Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'end_date' => now()->addDays(20),
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.subscription.index'))
        ->assertOk()
        ->assertSee('Mensuel Actuel')
        ->assertSee('actif', escape: false);
});

it('lists active plans to renew into and hides inactive ones', function () {
    Plan::factory()->create(['name' => 'Plan Actif', 'is_active' => true]);
    Plan::factory()->create(['name' => 'Plan Inactif', 'is_active' => false]);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.subscription.index'))
        ->assertOk()
        ->assertSee('Plan Actif')
        ->assertDontSee('Plan Inactif');
});
