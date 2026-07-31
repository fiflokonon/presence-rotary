<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('redirects guests to login on edit', function () {
    $this->get(route('admin.password.edit'))->assertRedirect(route('admin.login'));
});

it('redirects guests to login on update', function () {
    $this->put(route('admin.password.update'), [])->assertRedirect(route('admin.login'));
});

it('rejects an incorrect current password', function () {
    $user = User::factory()->create(['password' => 'old-password-123']);

    $this->actingAs($user)
        ->put(route('admin.password.update'), [
            'current_password' => 'wrong-password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertSessionHasErrors(['current_password']);

    expect(Hash::check('old-password-123', $user->fresh()->password))->toBeTrue();
});

it('rejects a new password that does not match its confirmation', function () {
    $user = User::factory()->create(['password' => 'old-password-123']);

    $this->actingAs($user)
        ->put(route('admin.password.update'), [
            'current_password' => 'old-password-123',
            'password' => 'new-password-123',
            'password_confirmation' => 'something-else',
        ])->assertSessionHasErrors(['password']);

    expect(Hash::check('old-password-123', $user->fresh()->password))->toBeTrue();
});

it('updates the password and lets the admin log in with the new one', function () {
    $user = User::factory()->create(['password' => 'old-password-123']);

    $this->actingAs($user)
        ->put(route('admin.password.update'), [
            'current_password' => 'old-password-123',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertRedirect()
        ->assertSessionHas('status', 'Mot de passe mis à jour.');

    expect(Hash::check('new-password-123', $user->fresh()->password))->toBeTrue();

    simulateNewRequestBoundary();

    $this->post(route('admin.login'), [
        'email' => $user->email,
        'password' => 'new-password-123',
    ])->assertRedirect(route('admin.sessions.index'));
});

it('logs out other active sessions on the same account when the password changes', function () {
    config(['session.driver' => 'database']);

    $user = User::factory()->create(['password' => 'old-password-123']);
    $cookieName = config('session.cookie');

    $deviceA = $this->post(route('admin.login'), [
        'email' => $user->email,
        'password' => 'old-password-123',
    ]);
    $deviceA->assertRedirect(route('admin.sessions.index'));
    $cookieA = collect($deviceA->headers->getCookies())->first(fn ($c) => $c->getName() === $cookieName)->getValue();

    // A real browser immediately follows the post-login redirect with a GET,
    // which is what seeds this session's password-hash baseline in the
    // middleware. Without this, device A's login alone leaves nothing to
    // compare against later.
    simulateNewRequestBoundary();
    $this->withUnencryptedCookie($cookieName, $cookieA)->get(route('admin.sessions.index'))->assertOk();

    simulateNewRequestBoundary();

    // Device A's cookie is still attached from the previous request above —
    // clear it so this login looks like a brand-new, cookie-less browser.
    $this->defaultCookies = [];
    $this->unencryptedCookies = [];

    $deviceB = $this->post(route('admin.login'), [
        'email' => $user->email,
        'password' => 'old-password-123',
    ]);
    $deviceB->assertRedirect(route('admin.sessions.index'));
    $cookieB = collect($deviceB->headers->getCookies())->first(fn ($c) => $c->getName() === $cookieName)->getValue();

    // Same as device A above: follow the redirect to seed device B's own
    // baseline hash before the password change happens elsewhere.
    simulateNewRequestBoundary();
    $this->withUnencryptedCookie($cookieName, $cookieB)->get(route('admin.sessions.index'))->assertOk();

    simulateNewRequestBoundary();
    $this->withUnencryptedCookie($cookieName, $cookieA)
        ->put(route('admin.password.update'), [
            'current_password' => 'old-password-123',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertRedirect();

    simulateNewRequestBoundary();
    $this->withUnencryptedCookie($cookieName, $cookieA)
        ->get(route('admin.sessions.index'))
        ->assertOk();

    simulateNewRequestBoundary();
    $this->withUnencryptedCookie($cookieName, $cookieB)
        ->get(route('admin.sessions.index'))
        ->assertRedirect(route('admin.login'));
});
