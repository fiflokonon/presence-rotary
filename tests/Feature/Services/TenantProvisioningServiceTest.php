<?php

use App\Jobs\SendNewAdminCredentialsMailJob;
use App\Models\ClubSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

it('provisions a migrated tenant database with a first admin user', function () {
    Queue::fake();

    $tenant = app(TenantProvisioningService::class)->provision(
        'Rotary Club Provisioning',
        'provisioning.example.test',
        'Première Admin',
        'premiere.admin@provisioning.test',
    );

    expect($tenant->name)->toBe('Rotary Club Provisioning')
        ->and(Tenant::where('host', 'provisioning.example.test')->exists())->toBeTrue();

    config(['database.connections.sqlite.database' => $tenant->sqlite_path]);
    DB::purge('sqlite');

    expect(Schema::hasTable('club_settings'))->toBeTrue();
    expect(ClubSetting::current()->name)->toBe('Rotary Club Provisioning');

    $admin = User::where('email', 'premiere.admin@provisioning.test')->firstOrFail();
    expect($admin->name)->toBe('Première Admin');

    Queue::assertPushed(
        SendNewAdminCredentialsMailJob::class,
        fn (SendNewAdminCredentialsMailJob $job) => $job->tenantId === $tenant->id && $job->userId === $admin->id
    );

    @unlink($tenant->sqlite_path);

    // Switching the sqlite connection to a real file mid-test leaves
    // RefreshDatabase's cached in-memory PDO out of sync with its own
    // bookkeeping. Drop the stale cache so the next test re-migrates a
    // clean connection instead of colliding with it (see TenantContextTest
    // for the same workaround).
    unset(RefreshDatabaseState::$inMemoryConnections['sqlite']);
    RefreshDatabaseState::$migrated = false;
});

it('slugs the club name against the configured base domain', function () {
    config(['tenancy.base_domain' => 'example.test']);

    $host = app(TenantProvisioningService::class)->generateUniqueHost('Rotary Club Nouveau');

    expect($host)->toBe('rotary-club-nouveau.example.test');
});

it('de-duplicates the slug when the host already exists', function () {
    config(['tenancy.base_domain' => 'example.test']);
    Tenant::factory()->create(['host' => 'rotary-club-doublon.example.test']);

    $host = app(TenantProvisioningService::class)->generateUniqueHost('Rotary Club Doublon');

    expect($host)->toBe('rotary-club-doublon-2.example.test');
});
