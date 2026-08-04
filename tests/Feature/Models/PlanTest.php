<?php

use App\Models\Plan;

it('creates a plan with the expected attributes', function () {
    $plan = Plan::factory()->create(['name' => 'Annuel', 'duration_months' => 12, 'price' => 50000]);

    expect($plan->name)->toBe('Annuel')
        ->and($plan->duration_months)->toBe(12)
        ->and($plan->price)->toBe(50000)
        ->and($plan->is_active)->toBeTrue();
});

it('can be marked inactive via the factory state', function () {
    $plan = Plan::factory()->inactive()->create();

    expect($plan->is_active)->toBeFalse();
});
