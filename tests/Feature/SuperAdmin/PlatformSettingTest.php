<?php

use App\Models\PlatformSetting;
use App\Models\SuperAdmin;

it('shows the current default grace period', function () {
    PlatformSetting::current()->update(['default_grace_period_days' => 10]);

    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->get(superAdminUrl('superadmin/settings'))
        ->assertOk()
        ->assertSee('10');
});

it('updates the default grace period', function () {
    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->put(superAdminUrl('superadmin/settings'), ['default_grace_period_days' => 14])
        ->assertRedirect(superAdminUrl('superadmin/settings'));

    expect(PlatformSetting::current()->default_grace_period_days)->toBe(14);
});

it('validates the grace period is a non-negative integer', function () {
    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->put(superAdminUrl('superadmin/settings'), ['default_grace_period_days' => -1])
        ->assertSessionHasErrors(['default_grace_period_days']);
});
