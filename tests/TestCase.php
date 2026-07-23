<?php

namespace Tests;

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

        app(TenantContext::class)->use($tenant);
    }
}
