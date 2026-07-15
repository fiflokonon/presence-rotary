<?php

use App\Models\Attendance;
use App\Models\MeetingSession;
use App\Models\Member;
use App\Models\Title;
use App\Models\User;

it('redirects guests to login for every member route', function () {
    $member = Member::factory()->create();

    $this->get(route('admin.members.index'))->assertRedirect(route('admin.login'));
    $this->get(route('admin.members.show', $member))->assertRedirect(route('admin.login'));
    $this->get(route('admin.members.edit', $member))->assertRedirect(route('admin.login'));
    $this->put(route('admin.members.update', $member), [])->assertRedirect(route('admin.login'));
});

it('lists members to an authenticated admin', function () {
    Member::factory()->create(['name' => 'Jean Dupont', 'email' => 'jean@example.com']);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.members.index'))
        ->assertOk()
        ->assertSee('Jean Dupont')
        ->assertSee('jean@example.com');
});

it('filters the member list by search term', function () {
    Member::factory()->create(['name' => 'Jean Dupont', 'club' => 'RC Cotonou Ife']);
    Member::factory()->create(['name' => 'Awa Bello', 'club' => 'RC Porto-Novo']);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.members.index', ['search' => 'Porto-Novo']))
        ->assertOk()
        ->assertSee('Awa Bello')
        ->assertDontSee('Jean Dupont');
});

it('shows a member detail page with their attendance history', function () {
    $member = Member::factory()->create(['name' => 'Jean Dupont']);
    $meetingSession = MeetingSession::factory()->create(['title' => 'Réunion du 10 janvier']);

    Attendance::factory()->create([
        'member_id' => $member->id,
        'meeting_session_id' => $meetingSession->id,
        'classification' => 'Classification A',
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.members.show', $member))
        ->assertOk()
        ->assertSee('Réunion du 10 janvier')
        ->assertSee('Classification A');
});

it('updates a member', function () {
    $member = Member::factory()->create(['club' => 'RC Cotonou Ife']);

    $this->actingAs(User::factory()->create())
        ->put(route('admin.members.update', $member), [
            'title_id' => Title::where('name', 'Rotary')->sole()->id,
            'position_id' => Title::where('name', 'Rotary')->sole()->positions()->where('name', 'Membre')->sole()->id,
            'name' => $member->name,
            'club' => 'RC Porto-Novo',
            'phone' => $member->phone,
            'classification' => $member->classification,
            'email' => $member->email,
        ])->assertRedirect(route('admin.members.show', $member));

    expect($member->fresh()->club)->toBe('RC Porto-Novo');
});

it('rejects an email that collides with another member', function () {
    Member::factory()->create(['email' => 'existing@example.com']);
    $member = Member::factory()->create(['email' => 'jean@example.com']);

    $this->actingAs(User::factory()->create())
        ->put(route('admin.members.update', $member), [
            'title_id' => Title::where('name', 'Rotary')->sole()->id,
            'position_id' => Title::where('name', 'Rotary')->sole()->positions()->where('name', 'Membre')->sole()->id,
            'name' => $member->name,
            'club' => $member->club,
            'phone' => $member->phone,
            'classification' => $member->classification,
            'email' => 'existing@example.com',
        ])->assertSessionHasErrors(['email']);
});
