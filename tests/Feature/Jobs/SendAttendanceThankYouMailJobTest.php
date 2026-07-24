<?php

use App\Jobs\SendAttendanceThankYouMailJob;
use App\Mail\AttendanceThankYouMail;
use App\Models\Attendance;
use App\Models\MeetingSession;
use App\Services\TenantContext;
use Illuminate\Support\Facades\Mail;

it('sends the thank-you mail for the given tenant, attendance and session', function () {
    Mail::fake();
    $tenantId = app(TenantContext::class)->current()->id;

    $meetingSession = MeetingSession::factory()->create();
    $attendance = Attendance::factory()->for($meetingSession)->create(['email' => 'present@example.com']);

    (new SendAttendanceThankYouMailJob($tenantId, $attendance->id, $meetingSession->id))->handle(app(TenantContext::class));

    Mail::assertSent(AttendanceThankYouMail::class, fn (AttendanceThankYouMail $mail) => $mail->hasTo('present@example.com')
        && $mail->attendance->is($attendance)
        && $mail->meetingSession->is($meetingSession));
});
