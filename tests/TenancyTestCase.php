<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TenancyTestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $uniqueId = uniqid();
        $sqlitePath = base_path("storage/framework/testing/tenancy-sqlite-{$uniqueId}.sqlite");
        $centralPath = base_path("storage/framework/testing/tenancy-central-{$uniqueId}.sqlite");

        foreach ([$sqlitePath, $centralPath] as $path) {
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), recursive: true);
            }

            touch($path);
        }

        config(['database.connections.sqlite.database' => $sqlitePath]);
        config(['database.connections.central.database' => $centralPath]);
        DB::purge('sqlite');
        DB::purge('central');

        $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true]);
        $this->artisan('migrate', [
            '--database' => 'central',
            '--path' => 'database/migrations/central',
            '--force' => true,
        ]);
    }
}
