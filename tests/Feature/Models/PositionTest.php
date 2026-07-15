<?php

use App\Models\Position;

it('defaults is_active to true', function () {
    $position = Position::factory()->create();

    expect($position->is_active)->toBeTrue();
});

it('scopes to active positions only', function () {
    Position::factory()->create(['is_active' => true, 'name' => 'Active Poste']);
    Position::factory()->create(['is_active' => false, 'name' => 'Inactive Poste']);

    expect(Position::active()->pluck('name')->all())->toBe(['Active Poste']);
});

it('scopes to active positions plus a specific inactive id', function () {
    $active = Position::factory()->create(['is_active' => true]);
    $inactive = Position::factory()->create(['is_active' => false]);
    Position::factory()->create(['is_active' => false]);

    $ids = Position::activeOrId($inactive->id)->pluck('id')->sort()->values()->all();

    expect($ids)->toBe(collect([$active->id, $inactive->id])->sort()->values()->all());
});

it('activeOrId with a null id behaves like active alone', function () {
    $active = Position::factory()->create(['is_active' => true]);
    Position::factory()->create(['is_active' => false]);

    expect(Position::activeOrId(null)->pluck('id')->all())->toBe([$active->id]);
});
