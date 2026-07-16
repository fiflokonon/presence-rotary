<?php

use App\Models\Attendance;
use App\Models\MeetingSession;
use App\Models\Position;
use App\Models\Title;

it('shows an informational screen when no session is active', function () {
    $this->get(route('attendance.show'))
        ->assertOk()
        ->assertSee('Aucune séance en cours');
});

it('shows the email step by default when the active session is open', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);

    $this->get(route('attendance.show'))
        ->assertOk()
        ->assertSee('Adresse e-mail')
        ->assertSee('Continuer')
        ->assertDontSee('Numéro de téléphone')
        ->assertDontSee('Aucune séance en cours')
        ->assertDontSee('La séance est clôturée');
});

it('shows the closed-door screen when the active session is closed', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => false]);

    $this->get(route('attendance.show'))
        ->assertOk()
        ->assertSee('La séance est clôturée');
});

it('records an on-time attendance when the session is open', function () {
    $meetingSession = MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);

    $this->post(route('attendance.store'), [
        'title_id' => Title::where('name', 'Rotary')->sole()->id,
        'position_id' => Title::where('name', 'Rotary')->sole()->positions()->where('name', 'Membre')->sole()->id,
        'name' => 'Jean Dupont',
        'club' => 'RC Cotonou Ife',
        'phone' => '+229 90 00 00 00',
        'email' => 'jean.dupont@example.com',
    ])->assertRedirect(route('attendance.show'));

    expect(Attendance::first())
        ->meeting_session_id->toBe($meetingSession->id)
        ->present->toBeTrue()
        ->is_late->toBeFalse();
});

it('records a late attendance when the session is closed', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => false]);

    $this->post(route('attendance.store'), [
        'title_id' => Title::where('name', 'Invité')->sole()->id,
        'name' => 'Awa Bello',
        'club' => 'RC Porto-Novo',
        'phone' => '+229 91 00 00 00',
        'email' => 'awa.bello@example.com',
    ])->assertRedirect(route('attendance.show'));

    expect(Attendance::first())
        ->present->toBeTrue()
        ->is_late->toBeTrue();
});

it('rejects a submission missing required fields', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);

    $this->post(route('attendance.store'), ['name' => 'Jean Dupont'])
        ->assertSessionHasErrors(['title_id', 'club', 'phone', 'email']);

    expect(Attendance::count())->toBe(0);
});

it('shows the club logo on the attendance form page', function () {
    $this->get(route('attendance.show'))
        ->assertOk()
        ->assertSee('ife-logo.png', false);
});

it('requires a position when the submitted title has linked positions', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);
    $rotary = Title::where('name', 'Rotary')->sole();

    $this->post(route('attendance.store'), [
        'title_id' => $rotary->id,
        'name' => 'Jean Dupont',
        'club' => 'RC Cotonou Ife',
        'phone' => '+229 90 00 00 00',
        'email' => 'jean.dupont@example.com',
    ])->assertSessionHasErrors(['position_id']);

    expect(Attendance::count())->toBe(0);
});

it('allows no position when the submitted title has none', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);
    $invite = Title::where('name', 'Invité')->sole();

    $this->post(route('attendance.store'), [
        'title_id' => $invite->id,
        'name' => 'Awa Bello',
        'club' => 'RC Porto-Novo',
        'phone' => '+229 91 00 00 00',
        'email' => 'awa.bello@example.com',
    ])->assertRedirect(route('attendance.show'))
        ->assertSessionDoesntHaveErrors();

    expect(Attendance::first())
        ->title_id->toBe($invite->id)
        ->position_id->toBeNull();
});

it('allows no position when the submitted title only has inactive positions', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);
    $title = Title::factory()->create(['is_active' => true]);
    $title->positions()->attach(Position::factory()->create(['is_active' => false]));

    $this->post(route('attendance.store'), [
        'title_id' => $title->id,
        'name' => 'Jean Dupont',
        'club' => 'RC Cotonou Ife',
        'phone' => '+229 90 00 00 00',
        'email' => 'jean.dupont@example.com',
    ])->assertRedirect(route('attendance.show'))
        ->assertSessionDoesntHaveErrors();

    expect(Attendance::first())
        ->title_id->toBe($title->id)
        ->position_id->toBeNull();
});

it('rejects a position that is not linked to the submitted title', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);
    // JCI is only linked to the 5 generic officer positions (Président,
    // Vice-Président, Secrétaire, Trésorier, Membre) — PDG is Rotary-only.
    $jci = Title::where('name', 'JCI')->sole();
    $rotaryOnlyPosition = Title::where('name', 'Rotary')->sole()->positions()->where('name', 'PDG')->sole();

    $this->post(route('attendance.store'), [
        'title_id' => $jci->id,
        'position_id' => $rotaryOnlyPosition->id,
        'name' => 'Jean Dupont',
        'club' => 'RC Cotonou Ife',
        'phone' => '+229 90 00 00 00',
        'email' => 'jean.dupont@example.com',
    ])->assertSessionHasErrors(['position_id']);

    expect(Attendance::count())->toBe(0);
});

it('accepts a position that is linked to the submitted title', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);
    $rotary = Title::where('name', 'Rotary')->sole();
    $president = $rotary->positions()->where('name', 'Président')->sole();

    $this->post(route('attendance.store'), [
        'title_id' => $rotary->id,
        'position_id' => $president->id,
        'name' => 'Jean Dupont',
        'club' => 'RC Cotonou Ife',
        'phone' => '+229 90 00 00 00',
        'email' => 'jean.dupont@example.com',
    ])->assertRedirect(route('attendance.show'))
        ->assertSessionDoesntHaveErrors();

    expect(Attendance::first())->position_id->toBe($president->id);
});

it('stores the invited_by name when submitted for a guest check-in', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);
    $invite = Title::where('name', 'Invité')->sole();

    $this->post(route('attendance.store'), [
        'title_id' => $invite->id,
        'invited_by' => 'Jean Membre',
        'name' => 'Awa Bello',
        'club' => 'RC Porto-Novo',
        'phone' => '+229 91 00 00 00',
        'email' => 'awa.bello@example.com',
    ])->assertRedirect(route('attendance.show'));

    expect(Attendance::first()->invited_by)->toBe('Jean Membre');
});

it('allows omitting invited_by', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);
    $invite = Title::where('name', 'Invité')->sole();

    $this->post(route('attendance.store'), [
        'title_id' => $invite->id,
        'name' => 'Awa Bello',
        'club' => 'RC Porto-Novo',
        'phone' => '+229 91 00 00 00',
        'email' => 'awa.bello@example.com',
    ])->assertRedirect(route('attendance.show'))
        ->assertSessionDoesntHaveErrors();

    expect(Attendance::first()->invited_by)->toBeNull();
});
