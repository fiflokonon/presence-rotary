<?php

use App\Jobs\SendNewAdminCredentialsMailJob;
use App\Models\ClubSetting;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PayPlusGateway;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

it('provisions a tenant, admin, and paid subscription from a confirmed self-service payment', function () {
    Queue::fake();
    config(['tenancy.base_domain' => 'example.test']);

    $plan = Plan::factory()->create(['duration_months' => 1, 'price' => 5000]);
    Transaction::factory()->selfService()->create([
        'plan_id' => $plan->id,
        'reference' => 'SUB-E2E',
        'amount' => 5000,
        'metadata' => [
            'club_name' => 'Rotary Club E2E',
            'admin_name' => 'Admin E2E',
            'admin_email' => 'admin@e2e.test',
        ],
    ]);

    $this->mock(PayPlusGateway::class, function ($mock) {
        $mock->shouldReceive('fetchStatus')->once()->andReturn([
            'success' => true,
            'status' => 'completed',
            'custom_data' => ['reference' => 'SUB-E2E'],
        ]);
    });

    $this->postJson('/payplus/callback', ['token' => 'tok-e2e', 'response_code' => '00'])
        ->assertOk()
        ->assertJson(['status' => 'success']);

    $tenant = Tenant::where('host', 'rotary-club-e2e.example.test')->firstOrFail();
    expect($tenant->name)->toBe('Rotary Club E2E')
        ->and(file_exists($tenant->sqlite_path))->toBeTrue();

    $subscription = $tenant->currentSubscription();
    expect($subscription->source)->toBe(Subscription::SOURCE_PAID)
        ->and($subscription->amount)->toBe(5000)
        ->and($tenant->accessState())->toBe(Tenant::ACCESS_ACTIVE);

    config(['database.connections.sqlite.database' => $tenant->sqlite_path]);
    DB::purge('sqlite');
    expect(Schema::hasTable('club_settings'))->toBeTrue();
    expect(ClubSetting::current()->name)->toBe('Rotary Club E2E');

    $admin = User::where('email', 'admin@e2e.test')->firstOrFail();
    Queue::assertPushed(
        SendNewAdminCredentialsMailJob::class,
        fn (SendNewAdminCredentialsMailJob $job) => $job->tenantId === $tenant->id && $job->userId === $admin->id
    );

    @unlink($tenant->sqlite_path);

    // Switching the sqlite connection to a real file above leaves
    // RefreshDatabase's bookkeeping stale for the in-memory central
    // connection instead of colliding with it (see TenantContextTest for
    // the same workaround).
    unset(RefreshDatabaseState::$inMemoryConnections['sqlite']);
    RefreshDatabaseState::$migrated = false;
});
