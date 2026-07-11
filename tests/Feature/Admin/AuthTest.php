<?php

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
        ->assertSee('rotary-nexus-logo.png', false);
});
