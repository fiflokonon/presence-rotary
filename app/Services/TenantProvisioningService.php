<?php

namespace App\Services;

use App\Jobs\SendNewAdminCredentialsMailJob;
use App\Models\ClubSetting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

class TenantProvisioningService
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function provision(string $name, string $host, string $adminName, string $adminEmail): Tenant
    {
        $previousTenant = $this->tenantContext->current();

        $directory = database_path('data/tenants');

        if (! is_dir($directory)) {
            mkdir($directory, recursive: true);
        }

        $sqlitePath = $directory.'/'.Str::uuid().'.sqlite';
        touch($sqlitePath);

        $this->tenantContext->use(new Tenant([
            'name' => $name,
            'host' => $host,
            'sqlite_path' => $sqlitePath,
        ]));
        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);

        ClubSetting::current()?->update([
            'name' => $name,
            'tagline' => null,
        ]);

        $tenant = Tenant::create([
            'name' => $name,
            'host' => $host,
            'sqlite_path' => $sqlitePath,
        ]);

        $password = Str::password(16);

        $admin = User::create([
            'name' => $adminName,
            'email' => $adminEmail,
            'password' => $password,
        ]);

        SendNewAdminCredentialsMailJob::dispatch($tenant->id, $admin->id, $password);

        if ($previousTenant !== null) {
            $this->tenantContext->use($previousTenant);
        } else {
            $this->tenantContext->clear();
        }

        return $tenant;
    }

    public function generateUniqueHost(string $clubName): string
    {
        $baseSlug = Str::slug($clubName);
        $baseDomain = config('tenancy.base_domain');

        $host = "{$baseSlug}.{$baseDomain}";
        $suffix = 2;

        while (Tenant::where('host', $host)->exists()) {
            $host = "{$baseSlug}-{$suffix}.{$baseDomain}";
            $suffix++;
        }

        return $host;
    }
}
