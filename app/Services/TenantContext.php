<?php

namespace App\Services;

use App\Models\MailSetting;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TenantContext
{
    private ?Tenant $current = null;

    public function use(Tenant $tenant): void
    {
        if (config('database.connections.sqlite.database') !== $tenant->sqlite_path) {
            config(['database.connections.sqlite.database' => $tenant->sqlite_path]);
            DB::purge('sqlite');
        }

        $this->current = $tenant;

        $this->applyMailSettings();
    }

    public function current(): ?Tenant
    {
        return $this->current;
    }

    public function clear(): void
    {
        $this->current = null;
    }

    private function applyMailSettings(): void
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
