<?php

use App\Enums\AttendanceTitle;
use App\Models\Attendance;
use App\Models\MeetingSession;

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
        'title' => AttendanceTitle::Rotarien->value,
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
        'title' => AttendanceTitle::Invite->value,
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
        ->assertSessionHasErrors(['title', 'club', 'phone', 'email']);

    expect(Attendance::count())->toBe(0);
});

it('shows the club logo on the attendance form page', function () {
    $this->get(route('attendance.show'))
        ->assertOk()
        ->assertSee('ife-logo.png', false);
});
