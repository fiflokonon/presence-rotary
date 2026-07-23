<?php

use App\Models\Tenant;
use Illuminate\Database\QueryException;

it('persists a tenant on the central connection', function () {
    $tenant = Tenant::factory()->create(['host' => 'club1.example.test']);

    expect($tenant->getConnectionName())->toBe('central')
        ->and(Tenant::where('host', 'club1.example.test')->exists())->toBeTrue();
});

it('requires a unique host', function () {
    Tenant::factory()->create(['host' => 'club1.example.test']);

    expect(fn () => Tenant::factory()->create(['host' => 'club1.example.test']))
        ->toThrow(QueryException::class);
});
