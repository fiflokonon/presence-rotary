<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAttendanceRequest;
use App\Models\Attendance;
use App\Models\MeetingSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AttendanceFormController extends Controller
{
    public function show(): View
    {
        return view('attendance.show', [
            'meetingSession' => MeetingSession::active(),
        ]);
    }

    public function store(StoreAttendanceRequest $request): RedirectResponse
    {
        $meetingSession = MeetingSession::active();

        abort_if($meetingSession === null, 404);

        Attendance::create([
            ...$request->validated(),
            'meeting_session_id' => $meetingSession->id,
            'present' => true,
            'is_late' => ! $meetingSession->is_open,
        ]);

        return redirect()
            ->route('attendance.show')
            ->with('attendanceSubmitted', true)
            ->with('attendanceWasLate', ! $meetingSession->is_open);
    }
}
