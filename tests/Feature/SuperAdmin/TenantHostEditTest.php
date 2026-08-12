<?php

use App\Models\SuperAdmin;
use App\Models\Tenant;

it('displays the tenant edit page to a super-admin', function () {
    $tenant = Tenant::factory()->create(['name' => 'Rotary Club Test', 'host' => 'test.example.test']);

    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->get(superAdminUrl("superadmin/tenants/{$tenant->id}/edit"))
        ->assertOk()
        ->assertSee('Rotary Club Test')
        ->assertSee('test.example.test');
});

it('updates the host of a tenant', function () {
    $tenant = Tenant::factory()->create(['host' => 'old.example.test']);

    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->patch(superAdminUrl("superadmin/tenants/{$tenant->id}"), [
            'host' => 'new.example.test',
        ])->assertRedirect(superAdminUrl('superadmin/tenants'));

    expect($tenant->refresh()->host)->toBe('new.example.test');
});

it('rejects a host already used by another tenant', function () {
    Tenant::factory()->create(['host' => 'taken.example.test']);
    $tenant = Tenant::factory()->create(['host' => 'mine.example.test']);

    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->patch(superAdminUrl("superadmin/tenants/{$tenant->id}"), [
            'host' => 'taken.example.test',
        ])->assertSessionHasErrors(['host']);

    expect($tenant->refresh()->host)->toBe('mine.example.test');
});

it('accepts the tenant keeping its own unchanged host', function () {
    $tenant = Tenant::factory()->create(['host' => 'same.example.test']);

    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->patch(superAdminUrl("superadmin/tenants/{$tenant->id}"), [
            'host' => 'same.example.test',
        ])->assertRedirect(superAdminUrl('superadmin/tenants'));

    expect($tenant->refresh()->host)->toBe('same.example.test');
});

it('requires guests to authenticate before editing', function () {
    $tenant = Tenant::factory()->create();

    $this->get(superAdminUrl("superadmin/tenants/{$tenant->id}/edit"))
        ->assertRedirect();
});
