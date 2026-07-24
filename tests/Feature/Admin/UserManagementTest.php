<?php

use App\Jobs\SendNewAdminCredentialsMailJob;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

it('redirects guests to login', function () {
    $this->get(route('admin.users.index'))->assertRedirect(route('admin.login'));
});

it('lists existing admins to an authenticated admin', function () {
    User::factory()->create(['name' => 'Jeanne Admin', 'email' => 'jeanne@example.com']);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.users.index'))
        ->assertOk()
        ->assertSee('Jeanne Admin')
        ->assertSee('jeanne@example.com');
});

it('creates a new admin and dispatches their generated credentials email', function () {
    Queue::fake();

    $this->actingAs(User::factory()->create())
        ->post(route('admin.users.store'), [
            'name' => 'Nouvel Admin',
            'email' => 'nouvel.admin@example.com',
        ])->assertRedirect(route('admin.users.index'));

    $created = User::where('email', 'nouvel.admin@example.com')->firstOrFail();

    expect($created->name)->toBe('Nouvel Admin');

    Queue::assertPushed(SendNewAdminCredentialsMailJob::class, fn (SendNewAdminCredentialsMailJob $job) => $job->userId === $created->id);
});

it('rejects an invalid admin creation payload', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('admin.users.store'), ['name' => '', 'email' => 'not-an-email'])
        ->assertSessionHasErrors(['name', 'email']);
});

it('rejects a duplicate admin email', function () {
    User::factory()->create(['email' => 'existing@example.com']);

    $this->actingAs(User::factory()->create())
        ->post(route('admin.users.store'), [
            'name' => 'Doublon',
            'email' => 'existing@example.com',
        ])->assertSessionHasErrors(['email']);
});
