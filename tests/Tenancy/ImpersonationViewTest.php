<?php

use App\Models\SuperAdmin;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('lets a super admin view a tenant admin panel after starting impersonation', function () {
    $tenant = Tenant::factory()->create();
    config(['database.connections.sqlite.database' => $tenant->sqlite_path]);
    DB::purge('sqlite');
    Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
    $tenantAdmin = User::factory()->create();

    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->post(superAdminUrl("superadmin/tenants/{$tenant->id}/impersonate"))
        ->assertRedirect(route('admin.sessions.index'));

    $this->withSession(['impersonating_tenant_id' => $tenant->id])
        ->actingAs($tenantAdmin)
        ->get(superAdminUrl('admin/sessions'))
        ->assertOk()
        ->assertSee('RC Cotonou Ife');

    @unlink($tenant->sqlite_path);
});
