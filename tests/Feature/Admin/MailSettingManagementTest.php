<?php

use App\Mail\MailSettingTestMail;
use App\Models\MailSetting;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

it('redirects guests to login on edit', function () {
    $this->get(route('admin.mail-settings.edit'))->assertRedirect(route('admin.login'));
});

it('redirects guests to login on update', function () {
    $this->put(route('admin.mail-settings.update'), [])->assertRedirect(route('admin.login'));
});

it('shows an empty form when no settings are saved yet', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.mail-settings.edit'))
        ->assertOk()
        ->assertSee('Paramètres mail');
});

it('creates the mail settings row on first save', function () {
    $this->actingAs(User::factory()->create())
        ->put(route('admin.mail-settings.update'), [
            'host' => 'smtp.example.com',
            'port' => 587,
            'username' => 'bot@example.com',
            'password' => 'secret-password',
            'encryption' => 'tls',
            'from_address' => 'no-reply@example.com',
            'from_name' => 'RC Cotonou Ife',
        ])->assertRedirect(route('admin.mail-settings.edit'));

    $mailSetting = MailSetting::current();

    expect($mailSetting->host)->toBe('smtp.example.com')
        ->and($mailSetting->password)->toBe('secret-password');
});

it('does not render the plaintext password back into the edit page', function () {
    MailSetting::create([
        'host' => 'smtp.example.com',
        'port' => 587,
        'username' => 'bot@example.com',
        'password' => 'super-secret-value',
        'encryption' => 'tls',
        'from_address' => 'no-reply@example.com',
        'from_name' => 'RC Cotonou Ife',
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.mail-settings.edit'))
        ->assertOk()
        ->assertDontSee('super-secret-value');
});

it('keeps the existing password when the password field is left blank on update', function () {
    $mailSetting = MailSetting::create([
        'host' => 'smtp.example.com',
        'port' => 587,
        'username' => 'bot@example.com',
        'password' => 'original-password',
        'encryption' => 'tls',
        'from_address' => 'no-reply@example.com',
        'from_name' => 'RC Cotonou Ife',
    ]);

    $this->actingAs(User::factory()->create())
        ->put(route('admin.mail-settings.update'), [
            'host' => 'smtp.changed.com',
            'port' => 2525,
            'username' => 'bot@example.com',
            'password' => '',
            'encryption' => '',
            'from_address' => 'no-reply@example.com',
            'from_name' => 'RC Cotonou Ife',
        ])->assertRedirect(route('admin.mail-settings.edit'));

    expect($mailSetting->fresh()->password)->toBe('original-password')
        ->and($mailSetting->fresh()->host)->toBe('smtp.changed.com')
        ->and($mailSetting->fresh()->encryption)->toBeNull();
});

it('overwrites the password when a new one is submitted', function () {
    $mailSetting = MailSetting::create([
        'host' => 'smtp.example.com',
        'port' => 587,
        'username' => 'bot@example.com',
        'password' => 'original-password',
        'encryption' => 'tls',
        'from_address' => 'no-reply@example.com',
        'from_name' => 'RC Cotonou Ife',
    ]);

    $this->actingAs(User::factory()->create())
        ->put(route('admin.mail-settings.update'), [
            'host' => 'smtp.example.com',
            'port' => 587,
            'username' => 'bot@example.com',
            'password' => 'brand-new-password',
            'encryption' => 'tls',
            'from_address' => 'no-reply@example.com',
            'from_name' => 'RC Cotonou Ife',
        ])->assertRedirect(route('admin.mail-settings.edit'));

    expect($mailSetting->fresh()->password)->toBe('brand-new-password');
});

it('rejects an invalid payload', function () {
    $this->actingAs(User::factory()->create())
        ->put(route('admin.mail-settings.update'), [
            'host' => '',
            'port' => 'not-a-number',
            'from_address' => 'not-an-email',
            'from_name' => '',
        ])->assertSessionHasErrors(['host', 'port', 'from_address', 'from_name']);
});

it('rejects a test-email request when no settings are saved yet', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('admin.mail-settings.test'), ['test_email' => 'someone@example.com'])
        ->assertSessionHasErrors(['test_email']);
});

it('sends a test email synchronously using the saved settings', function () {
    Mail::fake();

    MailSetting::create([
        'host' => 'smtp.example.com',
        'port' => 587,
        'username' => 'bot@example.com',
        'password' => 'secret-password',
        'encryption' => 'tls',
        'from_address' => 'no-reply@example.com',
        'from_name' => 'RC Cotonou Ife',
    ]);

    $this->actingAs(User::factory()->create())
        ->post(route('admin.mail-settings.test'), ['test_email' => 'someone@example.com'])
        ->assertRedirect();

    Mail::assertSent(MailSettingTestMail::class, function ($mail) {
        return $mail->hasTo('someone@example.com');
    });

    Mail::assertNotQueued(MailSettingTestMail::class);
});

it('rejects an invalid test-email address', function () {
    MailSetting::create([
        'host' => 'smtp.example.com',
        'port' => 587,
        'username' => 'bot@example.com',
        'password' => 'secret-password',
        'encryption' => 'tls',
        'from_address' => 'no-reply@example.com',
        'from_name' => 'RC Cotonou Ife',
    ]);

    $this->actingAs(User::factory()->create())
        ->post(route('admin.mail-settings.test'), ['test_email' => 'not-an-email'])
        ->assertSessionHasErrors(['test_email']);
});
