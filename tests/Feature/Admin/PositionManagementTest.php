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

it('creates a position with the next order value appended at the end', function () {
    // Clear seeded positions to test with clean slate
    Position::query()->delete();

    Position::factory()->create(['order' => 5]);

    $this->actingAs(User::factory()->create())
        ->post(route('admin.positions.store'), ['name' => 'Porte-étendard']);

    expect(Position::where('name', 'Porte-étendard')->sole()->order)->toBe(6);
});

it('lists positions ordered by their order value rather than name', function () {
    // Clear seeded positions to test with clean slate
    Position::query()->delete();

    Position::factory()->create(['name' => 'Zed', 'order' => 0]);
    Position::factory()->create(['name' => 'Alpha', 'order' => 1]);

    $response = $this->actingAs(User::factory()->create())
        ->get(route('admin.positions.index'));

    $response->assertOk();
    $content = $response->getContent();

    expect(strpos($content, 'Zed'))->toBeLessThan(strpos($content, 'Alpha'));
});

it('moves a position up, swapping order with the previous one', function () {
    // Clear seeded positions to test with clean slate
    Position::query()->delete();

    $first = Position::factory()->create(['order' => 0]);
    $second = Position::factory()->create(['order' => 1]);

    $this->actingAs(User::factory()->create())
        ->patch(route('admin.positions.move-order', [$second, 'up']))
        ->assertRedirect(route('admin.positions.index'));

    expect($second->fresh()->order)->toBe(0)
        ->and($first->fresh()->order)->toBe(1);
});

it('moves a position down, swapping order with the next one', function () {
    // Clear seeded positions to test with clean slate
    Position::query()->delete();

    $first = Position::factory()->create(['order' => 0]);
    $second = Position::factory()->create(['order' => 1]);

    $this->actingAs(User::factory()->create())
        ->patch(route('admin.positions.move-order', [$first, 'down']))
        ->assertRedirect(route('admin.positions.index'));

    expect($first->fresh()->order)->toBe(1)
        ->and($second->fresh()->order)->toBe(0);
});

it('does nothing when moving the first position up', function () {
    // Clear seeded positions to test with clean slate
    Position::query()->delete();

    $first = Position::factory()->create(['order' => 0]);
    Position::factory()->create(['order' => 1]);

    $this->actingAs(User::factory()->create())
        ->patch(route('admin.positions.move-order', [$first, 'up']))
        ->assertRedirect(route('admin.positions.index'));

    expect($first->fresh()->order)->toBe(0);
});

it('assigns an order to a position with a null order before moving it', function () {
    // Clear seeded positions to test with clean slate
    Position::query()->delete();

    $position = Position::factory()->create(['order' => null]);
    Position::factory()->create(['order' => 0]);

    $this->actingAs(User::factory()->create())
        ->patch(route('admin.positions.move-order', [$position, 'up']))
        ->assertRedirect(route('admin.positions.index'));

    expect($position->fresh()->order)->not->toBeNull();
});

it('rejects an invalid move direction', function () {
    // Clear seeded positions to test with clean slate
    Position::query()->delete();

    $position = Position::factory()->create(['order' => 0]);

    $this->actingAs(User::factory()->create())
        ->patch(route('admin.positions.move-order', [$position, 'sideways']))
        ->assertNotFound();
});

it('requires authentication to move a positions order', function () {
    $position = Position::factory()->create();

    $this->patch(route('admin.positions.move-order', [$position, 'up']))
        ->assertRedirect(route('admin.login'));
});

it('shows up and down order controls on the positions index', function () {
    $position = Position::factory()->create(['order' => 0]);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.positions.index'))
        ->assertOk()
        ->assertSee('action="'.route('admin.positions.move-order', [$position, 'up']).'"', false)
        ->assertSee('action="'.route('admin.positions.move-order', [$position, 'down']).'"', false);
});
