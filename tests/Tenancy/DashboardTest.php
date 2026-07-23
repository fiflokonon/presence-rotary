<?php

use App\Models\Attendance;
use App\Models\MeetingSession;
use App\Models\Member;
use App\Models\SuperAdmin;
use App\Models\Tenant;
use App\Services\TenantContext;
use Illuminate\Support\Facades\Artisan;

it('shows member and attendance counts per tenant', function () {
    $tenantContext = app(TenantContext::class);

    $tenantA = Tenant::factory()->create(['name' => 'Club A']);
    touch($tenantA->sqlite_path);
    $tenantContext->use($tenantA);
    Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
    Member::factory()->count(3)->create();
    $session = MeetingSession::factory()->create();
    Attendance::factory()->for($session)->create(['present' => true]);

    $tenantB = Tenant::factory()->create(['name' => 'Club B']);
    touch($tenantB->sqlite_path);
    $tenantContext->use($tenantB);
    Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
    Member::factory()->count(5)->create();

    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->get(superAdminUrl('superadmin/dashboard'))
        ->assertOk()
        ->assertSee('Club A')
        ->assertSee('Club B')
        ->assertSeeInOrder(['Club A', '3'])
        ->assertSeeInOrder(['Club B', '5']);

    @unlink($tenantA->sqlite_path);
    @unlink($tenantB->sqlite_path);
});
