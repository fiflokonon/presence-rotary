<?php

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SuperAdmin;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

it('redirects guests to the super-admin login', function () {
    $this->get(superAdminUrl('superadmin/tenants'))->assertRedirect(superAdminUrl('superadmin/login'));
});

it('lists existing tenants', function () {
    Tenant::factory()->create(['name' => 'Rotary Club Test']);

    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->get(superAdminUrl('superadmin/tenants'))
        ->assertOk()
        ->assertSee('Rotary Club Test');
});

it('rejects a duplicate host', function () {
    Tenant::factory()->create(['host' => 'existing.example.test']);

    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->post(superAdminUrl('superadmin/tenants'), [
            'name' => 'Doublon',
            'host' => 'existing.example.test',
        ])->assertSessionHasErrors(['host']);
});

it('creates an offered subscription atomically with the tenant', function () {
    $plan = Plan::factory()->create(['duration_months' => 3]);

    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->post(superAdminUrl('superadmin/tenants'), [
            'name' => 'Rotary Club Offert',
            'host' => 'offert.example.test',
            'admin_name' => 'Admin Offert',
            'admin_email' => 'admin@offert.test',
            'plan_id' => $plan->id,
        ])->assertRedirect(superAdminUrl('superadmin/tenants'));

    $tenant = Tenant::where('host', 'offert.example.test')->firstOrFail();
    $subscription = $tenant->currentSubscription();

    expect($subscription->source)->toBe(Subscription::SOURCE_OFFERED)
        ->and($subscription->amount)->toBe(0)
        ->and($subscription->plan_id)->toBe($plan->id)
        ->and($tenant->accessState())->toBe(Tenant::ACCESS_ACTIVE);

    @unlink($tenant->sqlite_path);

    // Switching the sqlite connection to a real file mid-test leaves
    // RefreshDatabase's cached in-memory PDO out of sync with its own
    // bookkeeping. Drop the stale cache so the next test re-migrates a
    // clean connection instead of colliding with it (see TenantContextTest
    // for the same workaround).
    unset(RefreshDatabaseState::$inMemoryConnections['sqlite']);
    RefreshDatabaseState::$migrated = false;
});

it('rejects tenant creation without a plan', function () {
    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->post(superAdminUrl('superadmin/tenants'), [
            'name' => 'Sans Plan',
            'host' => 'sansplan.example.test',
            'admin_name' => 'Admin',
            'admin_email' => 'admin@sansplan.test',
        ])->assertSessionHasErrors(['plan_id']);
});
