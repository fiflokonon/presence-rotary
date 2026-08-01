<?php

use App\Jobs\SendNewAdminCredentialsMailJob;
use App\Models\SuperAdmin;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Queue;

it('logs in the freshly provisioned tenant admin', function () {
    // Match production's session driver: the array driver used elsewhere in
    // tests skips DatabaseSessionHandler entirely, which is exactly the code
    // path that re-hydrates the authenticated user on the tenant connection.
    config(['session.driver' => 'database']);

    Queue::fake();

    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->post(superAdminUrl('superadmin/tenants'), [
            'name' => 'Rotary Club Nouveau',
            'host' => 'nouveau.example.test',
            'admin_name' => 'Première Admin',
            'admin_email' => 'premiere.admin@example.test',
        ])->assertRedirect(superAdminUrl('superadmin/tenants'));

    $tenant = Tenant::where('host', 'nouveau.example.test')->firstOrFail();

    $password = null;
    Queue::assertPushed(SendNewAdminCredentialsMailJob::class, function (SendNewAdminCredentialsMailJob $job) use (&$password) {
        $password = $job->password;

        return true;
    });

    // actingAs() mutates auth.defaults.guard as a side effect (AuthManager::shouldUse).
    // A real, separate HTTP request would never carry that over, so undo it here to
    // keep this test's second request faithful to production.
    config(['auth.defaults.guard' => 'web']);
    $this->app['auth']->forgetGuards();

    $response = $this->post('http://nouveau.example.test/admin/login', [
        'email' => 'premiere.admin@example.test',
        'password' => $password,
    ]);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect('http://nouveau.example.test/admin/dashboard');

    // A second, separate request re-using the session cookie is where the
    // DatabaseSessionHandler must re-hydrate the user via the tenant connection.
    $dashboard = $this->get('http://nouveau.example.test/admin/dashboard');
    $dashboard->assertOk();

    @unlink($tenant->sqlite_path);

    // Repeatedly switching the sqlite connection mid-test (as the tenant
    // provisioning + login requests above do) leaves RefreshDatabase's
    // cached in-memory PDO out of sync with its own bookkeeping. Drop the
    // stale cache so the next test in the suite re-migrates a clean
    // connection instead of colliding with it (see TenantContextTest for
    // the same workaround).
    unset(RefreshDatabaseState::$inMemoryConnections['sqlite']);
    RefreshDatabaseState::$migrated = false;
});
