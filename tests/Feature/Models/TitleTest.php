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
    Title::factory()->create(['is_active' => true, 'name' => 'Active One']);
    Title::factory()->create(['is_active' => false, 'name' => 'Inactive One']);

    expect(Title::active()->pluck('name')->all())->toBe(['Active One']);
});

it('scopes to active titles plus a specific inactive id', function () {
    $active = Title::factory()->create(['is_active' => true]);
    $inactive = Title::factory()->create(['is_active' => false]);
    Title::factory()->create(['is_active' => false]);

    $ids = Title::activeOrId($inactive->id)->pluck('id')->sort()->values()->all();

    expect($ids)->toBe(collect([$active->id, $inactive->id])->sort()->values()->all());
});

it('activeOrId with a null id behaves like active alone', function () {
    $active = Title::factory()->create(['is_active' => true]);
    Title::factory()->create(['is_active' => false]);

    expect(Title::activeOrId(null)->pluck('id')->all())->toBe([$active->id]);
});
