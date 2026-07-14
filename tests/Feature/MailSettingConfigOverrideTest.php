<?php

use App\Models\MailSetting;
use App\Providers\AppServiceProvider;

it('leaves the default mail config untouched when no MailSetting row exists', function () {
    (new AppServiceProvider($this->app))->overrideMailConfigFromDatabase();

    expect(config('mail.default'))->toBe('array')
        ->and(config('mail.mailers.smtp.host'))->toBe('127.0.0.1')
        ->and((int) config('mail.mailers.smtp.port'))->toBe(2525)
        ->and(config('mail.from.address'))->toBe('hello@example.com')
        ->and(MailSetting::current())->toBeNull();
});

it('overrides the runtime mail config when a MailSetting row exists', function () {
    MailSetting::create([
        'host' => 'smtp.custom.test',
        'port' => 2526,
        'username' => 'custom-user',
        'password' => 'custom-pass',
        'encryption' => 'ssl',
        'from_address' => 'custom@example.com',
        'from_name' => 'Custom Sender',
    ]);

    (new AppServiceProvider($this->app))->overrideMailConfigFromDatabase();

    expect(config('mail.default'))->toBe('smtp')
        ->and(config('mail.mailers.smtp.host'))->toBe('smtp.custom.test')
        ->and((int) config('mail.mailers.smtp.port'))->toBe(2526)
        ->and(config('mail.mailers.smtp.username'))->toBe('custom-user')
        ->and(config('mail.mailers.smtp.password'))->toBe('custom-pass')
        ->and(config('mail.mailers.smtp.encryption'))->toBe('ssl')
        ->and(config('mail.from.address'))->toBe('custom@example.com')
        ->and(config('mail.from.name'))->toBe('Custom Sender');
});
