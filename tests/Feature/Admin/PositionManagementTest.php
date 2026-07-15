<?php

use App\Models\Attendance;
use App\Models\Position;
use App\Models\User;

it('redirects guests to login for every position route', function () {
    $position = Position::factory()->create();

    $this->get(route('admin.positions.index'))->assertRedirect(route('admin.login'));
    $this->get(route('admin.positions.create'))->assertRedirect(route('admin.login'));
    $this->post(route('admin.positions.store'), [])->assertRedirect(route('admin.login'));
    $this->get(route('admin.positions.edit', $position))->assertRedirect(route('admin.login'));
    $this->put(route('admin.positions.update', $position), [])->assertRedirect(route('admin.login'));
    $this->patch(route('admin.positions.toggle-active', $position))->assertRedirect(route('admin.login'));
    $this->delete(route('admin.positions.destroy', $position))->assertRedirect(route('admin.login'));
});

it('lists positions to an authenticated admin', function () {
    Position::factory()->create(['name' => 'Archiviste']);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.positions.index'))
        ->assertOk()
        ->assertSee('Archiviste');
});

it('creates a position', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('admin.positions.store'), ['name' => 'Porte-drapeau'])
        ->assertRedirect(route('admin.positions.index'));

    expect(Position::where('name', 'Porte-drapeau')->exists())->toBeTrue();
});

it('rejects a duplicate position name', function () {
    Position::factory()->create(['name' => 'Archiviste']);

    $this->actingAs(User::factory()->create())
        ->post(route('admin.positions.store'), ['name' => 'Archiviste'])
        ->assertSessionHasErrors(['name']);
});

it('updates a position', function () {
    $position = Position::factory()->create(['name' => 'Ancien nom']);

    $this->actingAs(User::factory()->create())
        ->put(route('admin.positions.update', $position), ['name' => 'Nouveau nom'])
        ->assertRedirect(route('admin.positions.index'));

    expect($position->fresh()->name)->toBe('Nouveau nom');
});

it('toggles a positions active status', function () {
    $position = Position::factory()->create(['is_active' => true]);

    $this->actingAs(User::factory()->create())
        ->patch(route('admin.positions.toggle-active', $position))
        ->assertRedirect(route('admin.positions.index'));

    expect($position->fresh()->is_active)->toBeFalse();
});

it('deletes an unused position', function () {
    $position = Position::factory()->create();

    $this->actingAs(User::factory()->create())
        ->delete(route('admin.positions.destroy', $position))
        ->assertRedirect(route('admin.positions.index'));

    expect(Position::find($position->id))->toBeNull();
});

it('blocks deleting a position referenced by an attendance with a friendly message', function () {
    $position = Position::factory()->create();
    Attendance::factory()->create(['position_id' => $position->id]);

    $this->actingAs(User::factory()->create())
        ->delete(route('admin.positions.destroy', $position))
        ->assertRedirect(route('admin.positions.index'))
        ->assertSessionHas('error');

    expect(Position::find($position->id))->not->toBeNull();
});
