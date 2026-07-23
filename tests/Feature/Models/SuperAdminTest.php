<?php

use App\Models\SuperAdmin;
use Illuminate\Support\Facades\Auth;

it('persists on the central connection', function () {
    $superAdmin = SuperAdmin::factory()->create();

    expect($superAdmin->getConnectionName())->toBe('central');
});

it('authenticates through the super_admin guard', function () {
    $superAdmin = SuperAdmin::factory()->create();

    Auth::guard('super_admin')->login($superAdmin);

    expect(Auth::guard('super_admin')->check())->toBeTrue()
        ->and(Auth::guard('web')->check())->toBeFalse();
});
