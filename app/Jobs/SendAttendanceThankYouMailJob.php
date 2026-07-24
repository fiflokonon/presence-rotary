<?php

namespace App\Jobs;

use App\Mail\AttendanceThankYouMail;
use App\Models\Attendance;
use App\Models\MeetingSession;
use App\Models\Tenant;
use App\Services\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

class SendAttendanceThankYouMailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $tenantId,
        public int $attendanceId,
        public int $meetingSessionId,
        public ?string $nextSessionTitle = null,
        public ?Carbon $nextSessionDate = null,
    ) {}

    public function handle(TenantContext $tenantContext): void
    {
        $tenantContext->use(Tenant::findOrFail($this->tenantId));

        $attendance = Attendance::findOrFail($this->attendanceId);
        $meetingSession = MeetingSession::findOrFail($this->meetingSessionId);

        Mail::to($attendance->email)->send(
            new AttendanceThankYouMail($attendance, $meetingSession, $this->nextSessionTitle, $this->nextSessionDate)
        );
    }
}
