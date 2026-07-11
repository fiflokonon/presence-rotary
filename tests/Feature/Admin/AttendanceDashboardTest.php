<?php

use App\Enums\AttendanceTitle;
use App\Models\Attendance;
use App\Models\MeetingSession;
use App\Models\User;

it('redirects guests to login', function () {
    $meetingSession = MeetingSession::factory()->create();

    $this->get(route('admin.sessions.show', $meetingSession))
        ->assertRedirect(route('admin.login'));
});

it('shows counters and the roster to an authenticated admin', function () {
    $meetingSession = MeetingSession::factory()->create();
    Attendance::factory()->for($meetingSession)->create(['title' => AttendanceTitle::Rotarien, 'name' => 'Jean Dupont', 'present' => true]);
    Attendance::factory()->for($meetingSession)->create(['title' => AttendanceTitle::Invite, 'name' => 'Awa Bello', 'present' => false]);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.show', $meetingSession))
        ->assertOk()
        ->assertSee('Jean Dupont')
        ->assertSee('Awa Bello')
        ->assertSee('1/2');
});

it('toggles an attendance present flag', function () {
    $meetingSession = MeetingSession::factory()->create();
    $attendance = Attendance::factory()->for($meetingSession)->create(['present' => true]);

    $this->actingAs(User::factory()->create())
        ->patch(route('admin.attendances.toggle-present', $attendance))
        ->assertRedirect(route('admin.sessions.show', $meetingSession));

    expect($attendance->fresh()->present)->toBeFalse();
});

it('requires authentication to toggle presence', function () {
    $attendance = Attendance::factory()->create();

    $this->patch(route('admin.attendances.toggle-present', $attendance))
        ->assertRedirect(route('admin.login'));

    expect($attendance->fresh()->present)->toBeTrue();
});

it('exposes a QR code panel for sharing the public form link', function () {
    $meetingSession = MeetingSession::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.show', $meetingSession))
        ->assertOk()
        ->assertSee('QR code')
        ->assertSee('qrCodePanel(', false);
});

it('exposes a title/qualité filter listing the titles present in the roster', function () {
    $meetingSession = MeetingSession::factory()->create();
    Attendance::factory()->for($meetingSession)->create(['title' => AttendanceTitle::Rotarien, 'name' => 'Jean Dupont']);
    Attendance::factory()->for($meetingSession)->create(['title' => AttendanceTitle::Invite, 'name' => 'Awa Bello']);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.show', $meetingSession))
        ->assertOk()
        ->assertSee('x-model="activeTitle"', false)
        ->assertSee('Tous les titres')
        ->assertSee('Rotarien')
        ->assertSee('Invité');
});

it('shows a link back to the sessions list', function () {
    $meetingSession = MeetingSession::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.show', $meetingSession))
        ->assertOk()
        ->assertSee('Retour aux séances')
        ->assertSee('href="'.route('admin.sessions.index').'"', false);
});
