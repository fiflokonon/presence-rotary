<?php

use App\Models\SuperAdmin;
use App\Models\Tenant;

it('sets a grace period override for one or more selected tenants', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->patch(superAdminUrl('superadmin/tenants/grace-period'), [
            'tenant_ids' => [$tenantA->id, $tenantB->id],
            'grace_period_days' => 15,
        ])->assertRedirect(superAdminUrl('superadmin/tenants'));

    expect($tenantA->refresh()->grace_period_days)->toBe(15)
        ->and($tenantB->refresh()->grace_period_days)->toBe(15);
});

it('clears the override back to the platform default when grace_period_days is empty', function () {
    $tenant = Tenant::factory()->create(['grace_period_days' => 20]);

    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->patch(superAdminUrl('superadmin/tenants/grace-period'), [
            'tenant_ids' => [$tenant->id],
            'grace_period_days' => null,
        ])->assertRedirect(superAdminUrl('superadmin/tenants'));

    expect($tenant->refresh()->grace_period_days)->toBeNull();
});

it('requires at least one tenant', function () {
    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->patch(superAdminUrl('superadmin/tenants/grace-period'), ['tenant_ids' => []])
        ->assertSessionHasErrors(['tenant_ids']);
});
