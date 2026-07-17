<?php

use App\Models\Position;

it('defaults is_active to true', function () {
    $position = Position::factory()->create();

    expect($position->is_active)->toBeTrue();
});

it('scopes to active positions only', function () {
    $active = Position::factory()->create(['is_active' => true]);
    $inactive = Position::factory()->create(['is_active' => false]);

    $activeIds = Position::active()->pluck('id');

    expect($activeIds)->toContain($active->id)
        ->and($activeIds)->not->toContain($inactive->id);
});

it('scopes to active positions plus a specific inactive id', function () {
    $active = Position::factory()->create(['is_active' => true]);
    $inactive = Position::factory()->create(['is_active' => false]);
    $otherInactive = Position::factory()->create(['is_active' => false]);

    $ids = Position::activeOrId($inactive->id)->pluck('id');

    expect($ids)->toContain($active->id)
        ->and($ids)->toContain($inactive->id)
        ->and($ids)->not->toContain($otherInactive->id);
});

it('activeOrId with a null id behaves like active alone', function () {
    $active = Position::factory()->create(['is_active' => true]);
    $inactive = Position::factory()->create(['is_active' => false]);

    $ids = Position::activeOrId(null)->pluck('id');

    expect($ids)->toContain($active->id)
        ->and($ids)->not->toContain($inactive->id);
});

it('defaults order to null for a factory-created position', function () {
    $position = Position::factory()->create();

    expect($position->order)->toBeNull();
});
