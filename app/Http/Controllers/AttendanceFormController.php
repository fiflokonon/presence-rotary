<?php

namespace App\Http\Controllers;

use App\Http\Requests\LookupAttendanceEmailRequest;
use App\Http\Requests\StoreAttendanceRequest;
use App\Models\Attendance;
use App\Models\MeetingSession;
use App\Models\Member;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AttendanceFormController extends Controller
{
    public function show(): View
    {
        // `name` only exists in old-input after a failed step-2 (store) submit,
        // never after a failed step-1 (lookup) submit — use it to tell the two apart.
        $email = session()->hasOldInput('name') ? old('email') : null;

        return view('attendance.show', [
            'meetingSession' => MeetingSession::active(),
            'email' => $email,
            'member' => $email !== null ? Member::firstWhere('email', Member::normalizeEmail($email)) : null,
        ]);
    }

    public function lookup(LookupAttendanceEmailRequest $request): View
    {
        $meetingSession = MeetingSession::active();

        abort_if($meetingSession === null, 404);

        $email = Member::normalizeEmail($request->validated('email'));

        return view('attendance.show', [
            'meetingSession' => $meetingSession,
            'email' => $email,
            'member' => Member::firstWhere('email', $email),
        ]);
    }

    public function store(StoreAttendanceRequest $request): RedirectResponse
    {
        $meetingSession = MeetingSession::active();

        abort_if($meetingSession === null, 404);

        $email = Member::normalizeEmail($request->validated('email'));

        $member = Member::updateOrCreate(
            ['email' => $email],
            $request->safe()->only(['title', 'name', 'club', 'phone', 'classification']),
        );

        $alreadyCheckedIn = Attendance::where('member_id', $member->id)
            ->where('meeting_session_id', $meetingSession->id)
            ->exists();

        if ($alreadyCheckedIn) {
            return redirect()
                ->route('attendance.show')
                ->with('attendanceAlreadyCheckedIn', true);
        }

        Attendance::create([
            ...$request->validated(),
            'email' => $email,
            'member_id' => $member->id,
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
