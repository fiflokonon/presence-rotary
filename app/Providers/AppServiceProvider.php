<?php

namespace App\Providers;

use App\Models\MailSetting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        $this->overrideMailConfigFromDatabase();
    }

    public function overrideMailConfigFromDatabase(): void
    {
        if (! Schema::hasTable('mail_settings')) {
            return;
        }

        $mailSetting = MailSetting::current();

        if ($mailSetting === null) {
            return;
        }

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => $mailSetting->host,
            'mail.mailers.smtp.port' => $mailSetting->port,
            'mail.mailers.smtp.username' => $mailSetting->username,
            'mail.mailers.smtp.password' => $mailSetting->password,
            'mail.mailers.smtp.scheme' => match ($mailSetting->encryption) {
                'ssl' => 'smtps',
                'tls' => 'smtp',
                default => null,
            },
            'mail.from.address' => $mailSetting->from_address,
            'mail.from.name' => $mailSetting->from_name,
        ]);
    }
}
