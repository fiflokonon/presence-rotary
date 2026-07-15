<?php

use App\Enums\AttendanceCategory;
use App\Models\Member;
use App\Models\Position;
use App\Models\Title;
use App\Models\User;

it('redirects guests to login for every title route', function () {
    $title = Title::factory()->create();

    $this->get(route('admin.titles.index'))->assertRedirect(route('admin.login'));
    $this->get(route('admin.titles.create'))->assertRedirect(route('admin.login'));
    $this->post(route('admin.titles.store'), [])->assertRedirect(route('admin.login'));
    $this->get(route('admin.titles.edit', $title))->assertRedirect(route('admin.login'));
    $this->put(route('admin.titles.update', $title), [])->assertRedirect(route('admin.login'));
    $this->patch(route('admin.titles.toggle-active', $title))->assertRedirect(route('admin.login'));
    $this->delete(route('admin.titles.destroy', $title))->assertRedirect(route('admin.login'));
});

it('lists titles with their category to an authenticated admin', function () {
    Title::factory()->create(['name' => 'Zonta', 'category' => AttendanceCategory::Guests]);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.titles.index'))
        ->assertOk()
        ->assertSee('Zonta');
});

it('creates a title and links the selected positions', function () {
    $president = Position::factory()->create(['name' => 'Représentant']);
    $secretary = Position::factory()->create(['name' => 'Rapporteur']);

    $this->actingAs(User::factory()->create())
        ->post(route('admin.titles.store'), [
            'name' => 'Kiwanis',
            'category' => AttendanceCategory::Guests->value,
            'position_ids' => [$president->id, $secretary->id],
        ])->assertRedirect(route('admin.titles.index'));

    $title = Title::where('name', 'Kiwanis')->sole();
    expect($title->category)->toBe(AttendanceCategory::Guests)
        ->and($title->positions()->pluck('id')->sort()->values()->all())
        ->toBe([$president->id, $secretary->id]);
});

it('rejects a duplicate title name', function () {
    Title::factory()->create(['name' => 'Zonta']);

    $this->actingAs(User::factory()->create())
        ->post(route('admin.titles.store'), ['name' => 'Zonta', 'category' => AttendanceCategory::Guests->value])
        ->assertSessionHasErrors(['name']);
});

it('updates a title and replaces its linked positions', function () {
    $title = Title::factory()->create(['category' => AttendanceCategory::Guests]);
    $oldPosition = Position::factory()->create();
    $newPosition = Position::factory()->create();
    $title->positions()->attach($oldPosition);

    $this->actingAs(User::factory()->create())
        ->put(route('admin.titles.update', $title), [
            'name' => $title->name,
            'category' => AttendanceCategory::Members->value,
            'position_ids' => [$newPosition->id],
        ])->assertRedirect(route('admin.titles.index'));

    expect($title->fresh()->category)->toBe(AttendanceCategory::Members)
        ->and($title->positions()->pluck('id')->all())->toBe([$newPosition->id]);
});

it('toggles a titles active status', function () {
    $title = Title::factory()->create(['is_active' => true]);

    $this->actingAs(User::factory()->create())
        ->patch(route('admin.titles.toggle-active', $title))
        ->assertRedirect(route('admin.titles.index'));

    expect($title->fresh()->is_active)->toBeFalse();

    $this->actingAs(User::factory()->create())
        ->patch(route('admin.titles.toggle-active', $title))
        ->assertRedirect(route('admin.titles.index'));

    expect($title->fresh()->is_active)->toBeTrue();
});

it('deletes an unused title', function () {
    $title = Title::factory()->create();

    $this->actingAs(User::factory()->create())
        ->delete(route('admin.titles.destroy', $title))
        ->assertRedirect(route('admin.titles.index'));

    expect(Title::find($title->id))->toBeNull();
});

it('blocks deleting a title referenced by a member with a friendly message', function () {
    $title = Title::factory()->create();
    Member::factory()->create(['title_id' => $title->id]);

    $this->actingAs(User::factory()->create())
        ->delete(route('admin.titles.destroy', $title))
        ->assertRedirect(route('admin.titles.index'))
        ->assertSessionHas('error');

    expect(Title::find($title->id))->not->toBeNull();
});
