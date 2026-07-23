<?php

use App\Models\SuperAdmin;
use App\Models\Tenant;

it('redirects guests to the super-admin login', function () {
    $this->get(superAdminUrl('superadmin/tenants'))->assertRedirect(superAdminUrl('superadmin/login'));
});

it('lists existing tenants', function () {
    Tenant::factory()->create(['name' => 'Rotary Club Test']);

    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->get(superAdminUrl('superadmin/tenants'))
        ->assertOk()
        ->assertSee('Rotary Club Test');
});

it('rejects a duplicate host', function () {
    Tenant::factory()->create(['host' => 'existing.example.test']);

    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->post(superAdminUrl('superadmin/tenants'), [
            'name' => 'Doublon',
            'host' => 'existing.example.test',
        ])->assertSessionHasErrors(['host']);
});
