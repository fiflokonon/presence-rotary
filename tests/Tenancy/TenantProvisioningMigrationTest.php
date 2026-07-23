<?php

use App\Models\SuperAdmin;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('provisions a new tenant with a migrated database', function () {
    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->post(superAdminUrl('superadmin/tenants'), [
            'name' => 'Rotary Club Nouveau',
            'host' => 'nouveau.example.test',
        ])->assertRedirect(superAdminUrl('superadmin/tenants'));

    $tenant = Tenant::where('host', 'nouveau.example.test')->firstOrFail();

    expect($tenant->name)->toBe('Rotary Club Nouveau')
        ->and($tenant->sqlite_path)->toEndWith('.sqlite')
        ->and(file_exists($tenant->sqlite_path))->toBeTrue();

    config(['database.connections.sqlite.database' => $tenant->sqlite_path]);
    DB::purge('sqlite');

    expect(Schema::hasTable('club_settings'))->toBeTrue();

    @unlink($tenant->sqlite_path);
});
