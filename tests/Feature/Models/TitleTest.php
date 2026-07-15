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
