<?php

use App\Models\MailSetting;

it('returns null from current() when no row exists', function () {
    expect(MailSetting::current())->toBeNull();
});

it('returns the single row from current()', function () {
    MailSetting::create([
        'host' => 'smtp.example.com',
        'port' => 587,
        'username' => 'bot@example.com',
        'password' => 'secret-password',
        'encryption' => 'tls',
        'from_address' => 'no-reply@example.com',
        'from_name' => 'RC Cotonou Ife',
    ]);

    expect(MailSetting::current()->host)->toBe('smtp.example.com');
});

it('encrypts the password at rest and decrypts it back transparently', function () {
    $mailSetting = MailSetting::create([
        'host' => 'smtp.example.com',
        'port' => 587,
        'username' => 'bot@example.com',
        'password' => 'secret-password',
        'encryption' => 'tls',
        'from_address' => 'no-reply@example.com',
        'from_name' => 'RC Cotonou Ife',
    ]);

    $rawColumn = DB::table('mail_settings')->where('id', $mailSetting->id)->value('password');

    expect($rawColumn)->not->toBe('secret-password')
        ->and($mailSetting->fresh()->password)->toBe('secret-password');
});
