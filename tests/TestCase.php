<?php

namespace Tests;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--database' => 'central',
            '--path' => 'database/migrations/central',
            '--force' => true,
        ]);

        $tenant = Tenant::factory()->create([
            'host' => 'localhost',
            'sqlite_path' => ':memory:',
        ]);

        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => Plan::factory()->create()->id,
            'start_date' => now()->subDay(),
            'end_date' => now()->addYear(),
        ]);

        app(TenantContext::class)->use($tenant);
    }
}
