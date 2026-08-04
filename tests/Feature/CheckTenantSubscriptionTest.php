<?php

use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\SuperAdmin;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    PlatformSetting::current()->update(['default_grace_period_days' => 7]);
});

function createReachableTenant(array $attributes = []): Tenant
{
    $tenant = Tenant::factory()->create($attributes);

    if (! is_dir(dirname($tenant->sqlite_path))) {
        mkdir(dirname($tenant->sqlite_path), recursive: true);
    }
    touch($tenant->sqlite_path);

    config(['database.connections.sqlite.database' => $tenant->sqlite_path]);
    DB::purge('sqlite');
    Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);

    return $tenant;
}

// Switching the sqlite connection to a real file mid-test (as
// createReachableTenant() does) leaves RefreshDatabase's cached in-memory
// PDO out of sync with its own bookkeeping. Drop the stale cache so the
// next test re-migrates a clean :memory: connection instead of colliding
// with it (see TenantContextTest / TenantProvisioningLoginTest for the
// same workaround).
function resetRefreshDatabaseState(): void
{
    unset(RefreshDatabaseState::$inMemoryConnections['sqlite']);
    RefreshDatabaseState::$migrated = false;
}

it('shows the service-unavailable page on the public check-in form when the tenant is blocked', function () {
    $tenant = createReachableTenant(['host' => 'blocked.example.test']);
    Subscription::factory()->expiredDaysAgo(10)->create(['tenant_id' => $tenant->id]);

    $this->get('http://blocked.example.test/')
        ->assertOk()
        ->assertSee('indisponible', escape: false);

    @unlink($tenant->sqlite_path);
    resetRefreshDatabaseState();
});

it('shows the public check-in form as normal when the tenant is active', function () {
    $tenant = createReachableTenant(['host' => 'active.example.test']);
    Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'end_date' => now()->addMonth(),
    ]);

    $this->get('http://active.example.test/')->assertOk()->assertDontSee('indisponible');

    @unlink($tenant->sqlite_path);
    resetRefreshDatabaseState();
});

it('redirects a blocked admin to the subscription page instead of the dashboard', function () {
    $tenant = createReachableTenant(['host' => 'blocked-admin.example.test']);
    Subscription::factory()->expiredDaysAgo(10)->create(['tenant_id' => $tenant->id]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('http://blocked-admin.example.test/admin/dashboard')
        ->assertRedirect('http://blocked-admin.example.test/admin/subscription');

    @unlink($tenant->sqlite_path);
    resetRefreshDatabaseState();
});

it('lets a blocked admin still reach the subscription page', function () {
    $tenant = createReachableTenant(['host' => 'blocked-admin2.example.test']);
    Subscription::factory()->expiredDaysAgo(10)->create(['tenant_id' => $tenant->id]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('http://blocked-admin2.example.test/admin/subscription')
        ->assertOk();

    @unlink($tenant->sqlite_path);
    resetRefreshDatabaseState();
});

it('shows a grace banner but does not block a grace-period admin', function () {
    $tenant = createReachableTenant(['host' => 'grace-admin.example.test']);
    Subscription::factory()->expiredDaysAgo(2)->create(['tenant_id' => $tenant->id]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('http://grace-admin.example.test/admin/dashboard')
        ->assertOk()
        ->assertSee('souscription a expiré', escape: false);

    @unlink($tenant->sqlite_path);
    resetRefreshDatabaseState();
});

it('never blocks an authenticated super-admin, even while impersonating a blocked tenant', function () {
    $tenant = createReachableTenant(['host' => 'blocked-super.example.test']);
    Subscription::factory()->expiredDaysAgo(10)->create(['tenant_id' => $tenant->id]);
    $superAdmin = SuperAdmin::factory()->create();

    $this->actingAs($superAdmin, 'super_admin')
        ->withSession(['impersonating_tenant_id' => $tenant->id])
        ->get('http://blocked-super.example.test/admin/dashboard')
        ->assertOk();

    @unlink($tenant->sqlite_path);
    resetRefreshDatabaseState();
});
