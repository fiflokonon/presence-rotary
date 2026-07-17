<?php

use App\Enums\AttendanceCategory;
use App\Models\Position;
use App\Models\Title;

it('casts category to the AttendanceCategory enum', function () {
    $title = Title::factory()->create(['category' => AttendanceCategory::Guests]);

    expect($title->category)->toBe(AttendanceCategory::Guests);
});

it('can have many positions attached', function () {
    $title = Title::factory()->create();
    $position = Position::factory()->create();

    $title->positions()->attach($position);

    expect($title->positions()->pluck('name')->all())->toBe([$position->name]);
});

it('exposes the titles a position is linked to', function () {
    $position = Position::factory()->create();
    $titleA = Title::factory()->create();
    $titleB = Title::factory()->create();

    $position->titles()->attach([$titleA->id, $titleB->id]);

    expect($position->titles()->pluck('id')->sort()->values()->all())
        ->toBe([$titleA->id, $titleB->id]);
});

it('defaults is_active to true', function () {
    $title = Title::factory()->create();

    expect($title->is_active)->toBeTrue();
});

it('scopes to active titles only', function () {
    $active = Title::factory()->create(['is_active' => true]);
    $inactive = Title::factory()->create(['is_active' => false]);

    $activeIds = Title::active()->pluck('id');

    expect($activeIds)->toContain($active->id)
        ->and($activeIds)->not->toContain($inactive->id);
});

it('scopes to active titles plus a specific inactive id', function () {
    $active = Title::factory()->create(['is_active' => true]);
    $inactive = Title::factory()->create(['is_active' => false]);
    $otherInactive = Title::factory()->create(['is_active' => false]);

    $ids = Title::activeOrId($inactive->id)->pluck('id');

    expect($ids)->toContain($active->id)
        ->and($ids)->toContain($inactive->id)
        ->and($ids)->not->toContain($otherInactive->id);
});

it('activeOrId with a null id behaves like active alone', function () {
    $active = Title::factory()->create(['is_active' => true]);
    $inactive = Title::factory()->create(['is_active' => false]);

    $ids = Title::activeOrId(null)->pluck('id');

    expect($ids)->toContain($active->id)
        ->and($ids)->not->toContain($inactive->id);
});

it('defaults is_principal to false', function () {
    $title = Title::factory()->create();

    expect($title->is_principal)->toBeFalse();
});

it('scopes to principal titles only', function () {
    $principal = Title::factory()->create(['is_principal' => true]);
    $other = Title::factory()->create(['is_principal' => false]);

    $ids = Title::principal()->pluck('id');

    expect($ids)->toContain($principal->id)
        ->and($ids)->not->toContain($other->id);
});
