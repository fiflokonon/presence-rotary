<?php

use App\Models\MeetingSession;
use App\Models\User;

it('redirects guests to login', function () {
    $this->get(route('admin.sessions.index'))->assertRedirect(route('admin.login'));
});

it('lists existing sessions to an authenticated admin', function () {
    $meetingSession = MeetingSession::factory()->create(['title' => 'Réunion hebdomadaire']);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.index'))
        ->assertOk()
        ->assertSee('Réunion hebdomadaire');
});

it('creates a session and auto-activates it, deactivating the previous one', function () {
    $previous = MeetingSession::factory()->create(['is_active' => true]);

    $this->actingAs(User::factory()->create())
        ->post(route('admin.sessions.store'), [
            'title' => 'Réunion du 10 juillet',
            'date' => '2026-07-10',
            'time' => '12:30',
        ])->assertRedirect();

    $created = MeetingSession::where('title', 'Réunion du 10 juillet')->firstOrFail();

    expect($created->is_active)->toBeTrue()
        ->and($created->is_open)->toBeTrue()
        ->and($previous->fresh()->is_active)->toBeFalse();
});

it('rejects an invalid session creation payload', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('admin.sessions.store'), ['title' => ''])
        ->assertSessionHasErrors(['title', 'date', 'time']);
});

it('toggles a session open state', function () {
    $meetingSession = MeetingSession::factory()->create(['is_open' => true]);

    $this->actingAs(User::factory()->create())
        ->post(route('admin.sessions.toggle-open', $meetingSession))
        ->assertRedirect();

    expect($meetingSession->fresh()->is_open)->toBeFalse();
});

it('exposes a title filter with every session in the client-side payload', function () {
    MeetingSession::factory()->create(['title' => 'Réunion hebdomadaire']);
    MeetingSession::factory()->create(['title' => 'Assemblée annuelle']);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.index'))
        ->assertOk()
        ->assertSee('sessionsList(', false)
        ->assertSee('Rechercher un titre…')
        ->assertSee('Réunion hebdomadaire')
        ->assertSee('Assemblée annuelle');
});
