<?php

use App\Models\MailSetting;
use App\Models\Tenant;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;

it('switches the sqlite connection to the tenant path and back', function () {
    $tenantContext = app(TenantContext::class);
    $originalPath = config('database.connections.sqlite.database');

    $tenantPath = database_path('data/tenants/switch-test.sqlite');
    if (! is_dir(dirname($tenantPath))) {
        mkdir(dirname($tenantPath), recursive: true);
    }
    touch($tenantPath);

    $tenant = Tenant::factory()->make(['sqlite_path' => $tenantPath]);

    $tenantContext->use($tenant);

    expect(config('database.connections.sqlite.database'))->toBe($tenant->sqlite_path)
        ->and($tenantContext->current())->toBe($tenant);

    config(['database.connections.sqlite.database' => $originalPath]);
    DB::purge('sqlite');

    // Purging the sqlite connection while it's backed by :memory: leaves
    // RefreshDatabase's cached PDO (RefreshDatabaseState) pointing at a
    // now-detached connection. Drop the stale cache and mark the database
    // as unmigrated so RefreshDatabase re-migrates a clean in-memory
    // connection before the next test in this file runs, instead of trying
    // to reuse a PDO whose transaction state no longer matches Laravel's
    // internal bookkeeping.
    unset(RefreshDatabaseState::$inMemoryConnections['sqlite']);
    RefreshDatabaseState::$migrated = false;

    unlink($tenantPath);
});

it('does not purge the connection when switching to the already-active path', function () {
    $tenantContext = app(TenantContext::class);
    $activePath = config('database.connections.sqlite.database');

    DB::connection('sqlite')->statement('CREATE TABLE IF NOT EXISTS switch_marker (id INTEGER)');
    DB::connection('sqlite')->table('switch_marker')->insert(['id' => 1]);

    $tenant = Tenant::factory()->make(['sqlite_path' => $activePath]);
    $tenantContext->use($tenant);

    expect(DB::connection('sqlite')->table('switch_marker')->count())->toBe(1);
});

it('clears the current tenant', function () {
    $tenantContext = app(TenantContext::class);
    $tenantContext->use(Tenant::factory()->make(['sqlite_path' => config('database.connections.sqlite.database')]));

    $tenantContext->clear();

    expect($tenantContext->current())->toBeNull();
});

it('leaves mail config untouched when the tenant has no mail settings row', function () {
    app(TenantContext::class)->use(Tenant::factory()->make(['sqlite_path' => config('database.connections.sqlite.database')]));

    expect(config('mail.default'))->toBe('array')
        ->and(MailSetting::current())->toBeNull();
});

it('applies the tenant mail settings when a row exists', function () {
    MailSetting::create([
        'host' => 'smtp.custom.test',
        'port' => 2526,
        'username' => 'custom-user',
        'password' => 'custom-pass',
        'encryption' => 'ssl',
        'from_address' => 'custom@example.com',
        'from_name' => 'Custom Sender',
    ]);

    app(TenantContext::class)->use(Tenant::factory()->make(['sqlite_path' => config('database.connections.sqlite.database')]));

    expect(config('mail.default'))->toBe('smtp')
        ->and(config('mail.mailers.smtp.host'))->toBe('smtp.custom.test')
        ->and(config('mail.mailers.smtp.scheme'))->toBe('smtps')
        ->and(config('mail.from.name'))->toBe('Custom Sender');
});
