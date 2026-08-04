<?php

use App\Models\Plan;
use App\Models\SuperAdmin;

it('lists plans', function () {
    Plan::factory()->create(['name' => 'Annuel Test']);

    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->get(superAdminUrl('superadmin/plans'))
        ->assertOk()
        ->assertSee('Annuel Test');
});

it('creates a plan', function () {
    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->post(superAdminUrl('superadmin/plans'), [
            'name' => 'Trimestriel',
            'duration_months' => 3,
            'price' => 15000,
        ])->assertRedirect(superAdminUrl('superadmin/plans'));

    expect(Plan::where('name', 'Trimestriel')->exists())->toBeTrue();
});

it('validates required fields when creating a plan', function () {
    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->post(superAdminUrl('superadmin/plans'), [])
        ->assertSessionHasErrors(['name', 'duration_months', 'price']);
});

it('updates a plan', function () {
    $plan = Plan::factory()->create(['name' => 'Ancien nom']);

    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->put(superAdminUrl("superadmin/plans/{$plan->id}"), [
            'name' => 'Nouveau nom',
            'duration_months' => $plan->duration_months,
            'price' => $plan->price,
        ])->assertRedirect(superAdminUrl('superadmin/plans'));

    expect($plan->refresh()->name)->toBe('Nouveau nom');
});

it('toggles a plan active state', function () {
    $plan = Plan::factory()->create(['is_active' => true]);

    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->patch(superAdminUrl("superadmin/plans/{$plan->id}/toggle-active"))
        ->assertRedirect(superAdminUrl('superadmin/plans'));

    expect($plan->refresh()->is_active)->toBeFalse();
});

it('redirects guests to the super-admin login', function () {
    $this->get(superAdminUrl('superadmin/plans'))->assertRedirect(superAdminUrl('superadmin/login'));
});
