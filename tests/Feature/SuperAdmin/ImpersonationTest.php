<?php

use App\Models\SuperAdmin;
use App\Models\Tenant;

it('returns 404 on the admin panel host when not impersonating anyone', function () {
    $this->get(superAdminUrl('admin/login'))->assertNotFound();
});

it('stops impersonation and clears the session flag', function () {
    $tenant = Tenant::factory()->create();

    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->withSession(['impersonating_tenant_id' => $tenant->id])
        ->post(superAdminUrl('superadmin/impersonate/stop'))
        ->assertRedirect(route('super-admin.tenants.index'));

    $this->assertFalse(session()->has('impersonating_tenant_id'));
});
