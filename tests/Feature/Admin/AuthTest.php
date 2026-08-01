<?php

use App\Models\ClubSetting;
use App\Models\User;

it('shows the login form to a guest', function () {
    $this->get(route('admin.login'))->assertOk();
});

it('redirects guests hitting admin routes to the login form', function () {
    $this->get(route('admin.sessions.index'))->assertRedirect(route('admin.login'));
});

it('logs an admin in with valid credentials', function () {
    $user = User::factory()->create(['password' => bcrypt('secret123')]);

    $this->post(route('admin.login'), [
        'email' => $user->email,
        'password' => 'secret123',
    ])->assertRedirect(route('admin.sessions.index'));

    $this->assertAuthenticatedAs($user);
});

it('rejects invalid credentials', function () {
    $user = User::factory()->create(['password' => bcrypt('secret123')]);

    $this->post(route('admin.login'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('redirects an already-authenticated admin visiting the login form to the admin panel', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('admin.login'))
        ->assertRedirect(route('admin.sessions.index'));
});

it('logs an admin out', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('admin.logout'))
        ->assertRedirect(route('admin.login'));

    $this->assertGuest();
});

it('shows the club logo on the login page', function () {
    $this->get(route('admin.login'))
        ->assertOk()
        ->assertSee('ife-logo.png', false);
});

it('shows the tenant club name and logo on the login page instead of the default branding', function () {
    ClubSetting::current()->update([
        'name' => 'Rotary Club Ailleurs',
        'logo_path' => 'tenants/1/club/custom-logo.png',
    ]);

    $this->get(route('admin.login'))
        ->assertOk()
        ->assertSee('Rotary Club Ailleurs', false)
        ->assertSee('custom-logo.png', false);
});
