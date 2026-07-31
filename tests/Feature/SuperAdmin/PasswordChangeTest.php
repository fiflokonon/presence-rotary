<?php

use App\Models\SuperAdmin;
use Illuminate\Support\Facades\Hash;

it('redirects guests to login on edit', function () {
    $this->get(superAdminUrl('superadmin/password'))->assertRedirect(superAdminUrl('superadmin/login'));
});

it('redirects guests to login on update', function () {
    $this->put(superAdminUrl('superadmin/password'), [])->assertRedirect(superAdminUrl('superadmin/login'));
});

it('rejects an incorrect current password', function () {
    $superAdmin = SuperAdmin::factory()->create(['password' => 'old-password-123']);

    $this->actingAs($superAdmin, 'super_admin')
        ->put(superAdminUrl('superadmin/password'), [
            'current_password' => 'wrong-password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertSessionHasErrors(['current_password']);

    expect(Hash::check('old-password-123', $superAdmin->fresh()->password))->toBeTrue();
});

it('rejects a new password that does not match its confirmation', function () {
    $superAdmin = SuperAdmin::factory()->create(['password' => 'old-password-123']);

    $this->actingAs($superAdmin, 'super_admin')
        ->put(superAdminUrl('superadmin/password'), [
            'current_password' => 'old-password-123',
            'password' => 'new-password-123',
            'password_confirmation' => 'something-else',
        ])->assertSessionHasErrors(['password']);

    expect(Hash::check('old-password-123', $superAdmin->fresh()->password))->toBeTrue();
});

it('updates the password and lets the super-admin log in with the new one', function () {
    $superAdmin = SuperAdmin::factory()->create(['password' => 'old-password-123']);

    $this->actingAs($superAdmin, 'super_admin')
        ->put(superAdminUrl('superadmin/password'), [
            'current_password' => 'old-password-123',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertRedirect()
        ->assertSessionHas('status', 'Mot de passe mis à jour.');

    expect(Hash::check('new-password-123', $superAdmin->fresh()->password))->toBeTrue();

    // actingAs() mutates auth.defaults.guard as a side effect (AuthManager::shouldUse).
    // A real, separate HTTP request would never carry that over, so undo it here.
    config(['auth.defaults.guard' => 'web']);
    simulateNewRequestBoundary();

    $this->post(superAdminUrl('superadmin/login'), [
        'email' => $superAdmin->email,
        'password' => 'new-password-123',
    ])->assertRedirect(superAdminUrl('superadmin/tenants'));
});

it('logs out other active sessions on the same account when the password changes', function () {
    config(['session.driver' => 'database']);

    $superAdmin = SuperAdmin::factory()->create(['password' => 'old-password-123']);
    $cookieName = config('session.cookie');

    $deviceA = $this->post(superAdminUrl('superadmin/login'), [
        'email' => $superAdmin->email,
        'password' => 'old-password-123',
    ]);
    $deviceA->assertRedirect(superAdminUrl('superadmin/tenants'));
    $cookieA = collect($deviceA->headers->getCookies())->first(fn ($c) => $c->getName() === $cookieName)->getValue();

    // A real browser immediately follows the post-login redirect with a GET,
    // which is what seeds this session's password-hash baseline in the
    // middleware. Without this, device A's login alone leaves nothing to
    // compare against later.
    simulateNewRequestBoundary();
    $this->withUnencryptedCookie($cookieName, $cookieA)->get(superAdminUrl('superadmin/tenants'))->assertOk();

    simulateNewRequestBoundary();

    // Device A's cookie is still attached from the previous request above —
    // clear it so this login looks like a brand-new, cookie-less browser.
    $this->defaultCookies = [];
    $this->unencryptedCookies = [];

    $deviceB = $this->post(superAdminUrl('superadmin/login'), [
        'email' => $superAdmin->email,
        'password' => 'old-password-123',
    ]);
    $deviceB->assertRedirect(superAdminUrl('superadmin/tenants'));
    $cookieB = collect($deviceB->headers->getCookies())->first(fn ($c) => $c->getName() === $cookieName)->getValue();

    // Same as device A above: follow the redirect to seed device B's own
    // baseline hash before the password change happens elsewhere.
    simulateNewRequestBoundary();
    $this->withUnencryptedCookie($cookieName, $cookieB)->get(superAdminUrl('superadmin/tenants'))->assertOk();

    simulateNewRequestBoundary();
    $this->withUnencryptedCookie($cookieName, $cookieA)
        ->put(superAdminUrl('superadmin/password'), [
            'current_password' => 'old-password-123',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertRedirect();

    simulateNewRequestBoundary();
    $this->withUnencryptedCookie($cookieName, $cookieA)
        ->get(superAdminUrl('superadmin/tenants'))
        ->assertOk();

    simulateNewRequestBoundary();
    $this->withUnencryptedCookie($cookieName, $cookieB)
        ->get(superAdminUrl('superadmin/tenants'))
        ->assertRedirect(superAdminUrl('superadmin/login'));
});
