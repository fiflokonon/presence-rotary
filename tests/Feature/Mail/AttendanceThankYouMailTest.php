<?php

use App\Mail\AttendanceThankYouMail;
use App\Models\Attendance;
use App\Models\MeetingSession;
use Illuminate\Support\Carbon;

it('renders the attendee name and session details in the email body', function () {
    $meetingSession = MeetingSession::factory()->create([
        'title' => 'Réunion hebdomadaire',
        'date' => '2026-07-11',
    ]);
    $attendance = Attendance::factory()->for($meetingSession)->create(['name' => 'Jean Dupont']);

    $mailable = new AttendanceThankYouMail($attendance, $meetingSession);

    $mailable->assertHasSubject('Merci pour votre présence — RC Cotonou Ife');
    $mailable->assertSeeInHtml('Jean Dupont');
    $mailable->assertSeeInHtml('Réunion hebdomadaire');
    $mailable->assertSeeInHtml('11 juillet 2026');
    $mailable->assertSeeInHtml('ife-logo.png');
});

it('mentions the next session with its title when one is provided', function () {
    $meetingSession = MeetingSession::factory()->create();
    $attendance = Attendance::factory()->for($meetingSession)->create();

    $mailable = new AttendanceThankYouMail(
        $attendance,
        $meetingSession,
        nextSessionTitle: 'Assemblée annuelle',
        nextSessionDate: Carbon::parse('2026-08-15'),
    );

    $mailable->assertSeeInHtml('Assemblée annuelle');
    $mailable->assertSeeInHtml('15 août 2026');
});

it('mentions only the date when no next session title is provided', function () {
    $meetingSession = MeetingSession::factory()->create();
    $attendance = Attendance::factory()->for($meetingSession)->create();

    $mailable = new AttendanceThankYouMail(
        $attendance,
        $meetingSession,
        nextSessionDate: Carbon::parse('2026-08-15'),
    );

    $mailable->assertSeeInHtml('15 août 2026');
});

it('omits any next-session mention when none is provided', function () {
    $meetingSession = MeetingSession::factory()->create();
    $attendance = Attendance::factory()->for($meetingSession)->create();

    $mailable = new AttendanceThankYouMail($attendance, $meetingSession);

    $mailable->assertDontSeeInHtml('prochaine séance');
});
