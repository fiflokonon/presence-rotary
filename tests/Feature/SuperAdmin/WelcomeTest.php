<?php

use App\Models\SuperAdmin;

it('shows the welcome page to a guest', function () {
    $this->get(superAdminUrl('/'))
        ->assertOk()
        ->assertSee('Se connecter');
});

it('redirects an authenticated super admin to the dashboard', function () {
    $superAdmin = SuperAdmin::factory()->create();

    $this->actingAs($superAdmin, 'super_admin')
        ->get(superAdminUrl('/'))
        ->assertRedirect(route('super-admin.dashboard'));
});
