<?php

use App\Mail\AttendanceThankYouMail;
use App\Models\Attendance;
use App\Models\MeetingSession;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

it('does not send any email when closing without checking the box', function () {
    $meetingSession = MeetingSession::factory()->create(['is_open' => true]);
    Attendance::factory()->for($meetingSession)->create(['present' => true, 'email' => 'present@example.com']);
    $admin = User::factory()->create();

    Mail::fake();

    $this->actingAs($admin)
        ->post(route('admin.sessions.toggle-open', $meetingSession))
        ->assertRedirect();

    Mail::assertNothingQueued();
});

it('queues a thank-you email only to present attendees with an email when closing with the box checked', function () {
    $meetingSession = MeetingSession::factory()->create(['is_open' => true]);
    $withEmail = Attendance::factory()->for($meetingSession)->create(['present' => true, 'email' => 'present@example.com']);
    Attendance::factory()->for($meetingSession)->create(['present' => true, 'email' => null]);
    Attendance::factory()->for($meetingSession)->create(['present' => false, 'email' => 'absent@example.com']);
    $admin = User::factory()->create();

    Mail::fake();

    $this->actingAs($admin)
        ->post(route('admin.sessions.toggle-open', $meetingSession), [
            'send_thank_you_email' => '1',
        ])
        ->assertRedirect();

    Mail::assertQueued(
        AttendanceThankYouMail::class,
        fn (AttendanceThankYouMail $mail) => $mail->hasTo($withEmail->email)
    );
    Mail::assertQueuedCount(1);
});

it('never sends mail when reopening an already-closed session', function () {
    $meetingSession = MeetingSession::factory()->create(['is_open' => false]);
    Attendance::factory()->for($meetingSession)->create(['present' => true, 'email' => 'present@example.com']);
    $admin = User::factory()->create();

    Mail::fake();

    $this->actingAs($admin)
        ->post(route('admin.sessions.toggle-open', $meetingSession), [
            'send_thank_you_email' => '1',
        ])
        ->assertRedirect();

    Mail::assertNothingQueued();
});

it('passes the selected upcoming session title and date when mentioning the next session', function () {
    $meetingSession = MeetingSession::factory()->create(['is_open' => true]);
    Attendance::factory()->for($meetingSession)->create(['present' => true, 'email' => 'present@example.com']);
    $nextSession = MeetingSession::factory()->create([
        'title' => 'Assemblée annuelle',
        'date' => now()->addWeek()->toDateString(),
    ]);
    $admin = User::factory()->create();

    Mail::fake();

    $this->actingAs($admin)
        ->post(route('admin.sessions.toggle-open', $meetingSession), [
            'send_thank_you_email' => '1',
            'mention_next_session' => '1',
            'next_session_option' => "session:{$nextSession->id}",
        ])
        ->assertRedirect();

    Mail::assertQueued(
        AttendanceThankYouMail::class,
        fn (AttendanceThankYouMail $mail) => $mail->nextSessionTitle === $nextSession->title
            && $mail->nextSessionDate->isSameDay($nextSession->date)
    );
});

it('passes a manually typed next session date without a title', function () {
    $meetingSession = MeetingSession::factory()->create(['is_open' => true]);
    Attendance::factory()->for($meetingSession)->create(['present' => true, 'email' => 'present@example.com']);
    $admin = User::factory()->create();

    Mail::fake();

    $this->actingAs($admin)
        ->post(route('admin.sessions.toggle-open', $meetingSession), [
            'send_thank_you_email' => '1',
            'mention_next_session' => '1',
            'next_session_option' => 'manual',
            'next_session_date' => '2026-08-15',
        ])
        ->assertRedirect();

    Mail::assertQueued(
        AttendanceThankYouMail::class,
        fn (AttendanceThankYouMail $mail) => $mail->nextSessionTitle === null
            && $mail->nextSessionDate->toDateString() === '2026-08-15'
    );
});

it('shows the close-session panel with the thank-you email options when the session is open', function () {
    $meetingSession = MeetingSession::factory()->create(['is_open' => true]);
    MeetingSession::factory()->create([
        'title' => 'Assemblée annuelle',
        'date' => now()->addWeek()->toDateString(),
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.show', $meetingSession))
        ->assertOk()
        ->assertSee('closeSessionPanel(', false)
        ->assertSee('Envoyer un mail de remerciement aux présents')
        ->assertSee('Mentionner la prochaine séance')
        ->assertSee('Assemblée annuelle');
});

it('does not show the close-session panel checkboxes when the session is already closed', function () {
    $meetingSession = MeetingSession::factory()->create(['is_open' => false]);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.show', $meetingSession))
        ->assertOk()
        ->assertDontSee('Envoyer un mail de remerciement aux présents');
});
