<?php

use App\Models\SuperAdmin;

it('shows the super-admin login page', function () {
    $this->get(superAdminUrl('superadmin/login'))->assertOk();
});

it('logs a super admin in with valid credentials', function () {
    $superAdmin = SuperAdmin::factory()->create(['email' => 'root@example.test', 'password' => 'secret-password']);

    $this->post(superAdminUrl('superadmin/login'), [
        'email' => 'root@example.test',
        'password' => 'secret-password',
    ])->assertRedirect();

    $this->assertAuthenticatedAs($superAdmin, 'super_admin');
});

it('rejects invalid credentials', function () {
    SuperAdmin::factory()->create(['email' => 'root@example.test', 'password' => 'secret-password']);

    $this->post(superAdminUrl('superadmin/login'), [
        'email' => 'root@example.test',
        'password' => 'wrong-password',
    ])->assertSessionHasErrors();

    $this->assertGuest('super_admin');
});

it('logs a super admin out', function () {
    $superAdmin = SuperAdmin::factory()->create();

    $this->actingAs($superAdmin, 'super_admin')
        ->post(superAdminUrl('superadmin/logout'))
        ->assertRedirect();

    $this->assertGuest('super_admin');
});
