<?php

use App\Models\Attendance;
use App\Models\MeetingSession;
use App\Models\Member;
use App\Models\Position;
use App\Models\Title;

it('shows a blank confirmation form when the email is unknown', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);

    $this->post(route('attendance.lookup'), ['email' => 'inconnu@example.com'])
        ->assertOk()
        ->assertSee('Nom et prénoms')
        ->assertSee('inconnu@example.com');
});

it('shows a pre-filled confirmation form when the email matches a member', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);

    Member::factory()->create([
        'email' => 'jean@example.com',
        'name' => 'Jean Dupont',
        'club' => 'RC Cotonou Ife',
        'phone' => '+229 90 00 00 00',
    ]);

    $this->post(route('attendance.lookup'), ['email' => 'JEAN@example.com'])
        ->assertOk()
        ->assertSee('Jean Dupont')
        ->assertSee('+229 90 00 00 00');
});

it('rejects an invalid email at the lookup step', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);

    $this->post(route('attendance.lookup'), ['email' => 'not-an-email'])
        ->assertSessionHasErrors(['email']);
});

it('re-shows the step-1 email form after a failed lookup submission', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);

    $this->post(route('attendance.lookup'), ['email' => 'not-an-email'])
        ->assertSessionHasErrors(['email']);

    $this->get(route('attendance.show'))
        ->assertOk()
        ->assertSee('Continuer')
        ->assertDontSee('Nom et prénoms');
});

it('creates a member on first check-in and links the attendance to it', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);

    $this->post(route('attendance.store'), [
        'title_id' => Title::where('name', 'Rotary')->sole()->id,
        'position_id' => Title::where('name', 'Rotary')->sole()->positions()->where('name', 'Membre')->sole()->id,
        'name' => 'Jean Dupont',
        'club' => 'RC Cotonou Ife',
        'phone' => '+229 90 00 00 00',
        'email' => 'jean.dupont@example.com',
    ])->assertRedirect(route('attendance.show'));

    $member = Member::where('email', 'jean.dupont@example.com')->sole();

    expect(Attendance::first()->member_id)->toBe($member->id);
});

it('updates the existing member with newly submitted info on a later check-in', function () {
    $member = Member::factory()->create([
        'email' => 'jean.dupont@example.com',
        'classification' => 'Ancienne classification',
        'club' => 'RC Cotonou Ife',
    ]);

    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);

    $this->post(route('attendance.store'), [
        'title_id' => Title::where('name', 'Rotary')->sole()->id,
        'position_id' => Title::where('name', 'Rotary')->sole()->positions()->where('name', 'Membre')->sole()->id,
        'name' => $member->name,
        'club' => 'RC Porto-Novo',
        'phone' => $member->phone,
        'classification' => 'Nouvelle classification',
        'email' => 'jean.dupont@example.com',
    ])->assertRedirect(route('attendance.show'));

    expect($member->fresh())
        ->club->toBe('RC Porto-Novo')
        ->classification->toBe('Nouvelle classification');
});

it('rejects a second check-in for the same member on the same session', function () {
    $meetingSession = MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);
    $member = Member::factory()->create(['email' => 'jean.dupont@example.com']);

    Attendance::factory()->create([
        'meeting_session_id' => $meetingSession->id,
        'member_id' => $member->id,
        'email' => 'jean.dupont@example.com',
    ]);

    $this->post(route('attendance.store'), [
        'title_id' => Title::where('name', 'Rotary')->sole()->id,
        'position_id' => Title::where('name', 'Rotary')->sole()->positions()->where('name', 'Membre')->sole()->id,
        'name' => $member->name,
        'club' => $member->club,
        'phone' => $member->phone,
        'email' => 'jean.dupont@example.com',
    ])->assertRedirect(route('attendance.show'))
        ->assertSessionHas('attendanceAlreadyCheckedIn', true);

    expect(Attendance::count())->toBe(1);
});

it('shows the late check-in confirmation form when looking up an unknown email on a closed session', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => false]);

    $this->post(route('attendance.lookup'), ['email' => 'inconnu@example.com'])
        ->assertOk()
        ->assertSee('Nom et prénoms')
        ->assertSee('lateMode: true', false);
});

it('re-shows the pre-filled confirmation form after a failed submission', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);

    Member::factory()->create([
        'email' => 'jean.dupont@example.com',
        'name' => 'Jean Dupont',
    ]);

    // 'title_id', 'club' and 'phone' omitted on purpose to trigger validation errors.
    $this->post(route('attendance.store'), [
        'name' => 'Jean Dupont',
        'email' => 'jean.dupont@example.com',
    ])->assertSessionHasErrors(['title_id', 'club', 'phone']);

    $this->get(route('attendance.show'))
        ->assertOk()
        ->assertSee('Jean Dupont')
        ->assertSee('jean.dupont@example.com')
        ->assertDontSee('Adresse e-mail*');
});

it('still shows a returning members inactive title and position, marked inactive', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);
    $title = Title::factory()->create(['name' => 'Titre Retraité', 'is_active' => false]);
    $position = Position::factory()->create(['name' => 'Poste Retraité', 'is_active' => false]);
    $title->positions()->attach($position);

    Member::factory()->create([
        'email' => 'ancien@example.com',
        'title_id' => $title->id,
        'position_id' => $position->id,
    ]);

    $this->post(route('attendance.lookup'), ['email' => 'ancien@example.com'])
        ->assertOk()
        ->assertSee('Titre Retraité (inactif)')
        ->assertSee('Poste Retraité (inactif)', false);
});
