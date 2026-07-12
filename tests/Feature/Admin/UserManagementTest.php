<?php

use App\Mail\NewAdminCredentialsMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

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

it('creates a new admin and emails their generated credentials', function () {
    Mail::fake();

    $this->actingAs(User::factory()->create())
        ->post(route('admin.users.store'), [
            'name' => 'Nouvel Admin',
            'email' => 'nouvel.admin@example.com',
        ])->assertRedirect(route('admin.users.index'));

    $created = User::where('email', 'nouvel.admin@example.com')->firstOrFail();

    expect($created->name)->toBe('Nouvel Admin');

    Mail::assertQueued(NewAdminCredentialsMail::class, fn ($mail) => $mail->user->is($created));
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
