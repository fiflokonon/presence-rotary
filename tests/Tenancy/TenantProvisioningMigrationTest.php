<?php

use App\Jobs\SendNewAdminCredentialsMailJob;
use App\Models\SuperAdmin;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

it('provisions a new tenant with a migrated database and its first admin user', function () {
    Queue::fake();

    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->post(superAdminUrl('superadmin/tenants'), [
            'name' => 'Rotary Club Nouveau',
            'host' => 'nouveau.example.test',
            'admin_name' => 'Première Admin',
            'admin_email' => 'premiere.admin@example.test',
        ])->assertRedirect(superAdminUrl('superadmin/tenants'));

    $tenant = Tenant::where('host', 'nouveau.example.test')->firstOrFail();

    expect($tenant->name)->toBe('Rotary Club Nouveau')
        ->and($tenant->sqlite_path)->toEndWith('.sqlite')
        ->and(file_exists($tenant->sqlite_path))->toBeTrue();

    config(['database.connections.sqlite.database' => $tenant->sqlite_path]);
    DB::purge('sqlite');

    expect(Schema::hasTable('club_settings'))->toBeTrue();

    $admin = User::where('email', 'premiere.admin@example.test')->firstOrFail();
    expect($admin->name)->toBe('Première Admin');

    Queue::assertPushed(
        SendNewAdminCredentialsMailJob::class,
        fn (SendNewAdminCredentialsMailJob $job) => $job->tenantId === $tenant->id && $job->userId === $admin->id
    );

    @unlink($tenant->sqlite_path);
});
