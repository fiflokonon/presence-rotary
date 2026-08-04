<?php

namespace App\Http\Controllers;

use App\Http\Requests\LookupAttendanceEmailRequest;
use App\Http\Requests\StoreAttendanceRequest;
use App\Models\Attendance;
use App\Models\CheckinSetting;
use App\Models\MeetingSession;
use App\Models\Member;
use App\Models\Title;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class AttendanceFormController extends Controller
{
    public function show(): View
    {
        // `name` only exists in old-input after a failed step-2 (store) submit,
        // never after a failed step-1 (lookup) submit — use it to tell the two apart.
        $email = session()->hasOldInput('name') ? old('email') : null;
        $member = $email !== null ? Member::firstWhere('email', Member::normalizeEmail($email)) : null;

        return view('attendance.show', [
            'meetingSession' => MeetingSession::active(),
            'email' => $email,
            'member' => $member,
            ...$this->attendanceFormData($member),
        ]);
    }

    public function lookup(LookupAttendanceEmailRequest $request): View|RedirectResponse
    {
        $meetingSession = MeetingSession::active();

        abort_if($meetingSession === null, 404);

        $email = Member::normalizeEmail($request->validated('email'));
        $member = Member::firstWhere('email', $email);

        if ($member?->is_club_member) {
            return $this->checkInClubMember($member, $meetingSession);
        }

        return view('attendance.show', [
            'meetingSession' => $meetingSession,
            'email' => $email,
            'member' => $member,
            ...$this->attendanceFormData($member),
        ]);
    }

    public function store(StoreAttendanceRequest $request): RedirectResponse
    {
        $meetingSession = MeetingSession::active();

        abort_if($meetingSession === null, 404);

        $email = Member::normalizeEmail($request->validated('email'));

        $existingMember = Member::firstWhere('email', $email);

        if ($existingMember !== null && $this->alreadyCheckedIn($existingMember, $meetingSession)) {
            return redirect()
                ->route('attendance.show')
                ->with('attendanceAlreadyCheckedIn', true);
        }

        $member = Member::updateOrCreate(
            ['email' => $email],
            $request->safe()->only(['title_id', 'position_id', 'name', 'club', 'phone', 'classification']),
        );

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

    private function checkInClubMember(Member $member, MeetingSession $meetingSession): RedirectResponse
    {
        if ($this->alreadyCheckedIn($member, $meetingSession)) {
            return redirect()
                ->route('attendance.show')
                ->with('attendanceAlreadyCheckedIn', true);
        }

        Attendance::create([
            'meeting_session_id' => $meetingSession->id,
            'member_id' => $member->id,
            'title_id' => $member->title_id,
            'position_id' => $member->position_id,
            'name' => $member->name,
            'club' => $member->club,
            'phone' => $member->phone,
            'classification' => $member->classification,
            'email' => $member->email,
            'present' => true,
            'is_late' => ! $meetingSession->is_open,
        ]);

        return redirect()
            ->route('attendance.show')
            ->with('attendanceSubmitted', true)
            ->with('attendanceWasLate', ! $meetingSession->is_open);
    }

    private function alreadyCheckedIn(Member $member, MeetingSession $meetingSession): bool
    {
        return Attendance::where('member_id', $member->id)
            ->where('meeting_session_id', $meetingSession->id)
            ->exists();
    }

    /**
     * @return array{titles: Collection<int, Title>, guestTitleId: ?int, clubFieldEnabledForGuests: bool}
     */
    private function attendanceFormData(?Member $member): array
    {
        $titles = Title::activeOrId($member?->title_id)
            ->where(function ($query) use ($member) {
                $query->where('name', '!=', Title::GUEST_NAME)
                    ->when(
                        $member?->title_id !== null,
                        fn ($q) => $q->orWhere('id', $member->title_id),
                    );
            })
            ->with(['positions' => fn ($query) => $query->activeOrId($member?->position_id)])
            ->orderBy('order')
            ->orderBy('name')
            ->get();

        $guestTitle = Title::with('positions')->firstWhere('name', Title::GUEST_NAME);

        if ($guestTitle !== null && $guestTitle->id !== $member?->title_id && CheckinSetting::guestOptionEnabled()) {
            $titles->push($guestTitle);
        }

        return [
            'titles' => $titles,
            'guestTitleId' => $guestTitle?->id,
            'clubFieldEnabledForGuests' => CheckinSetting::clubFieldEnabledForGuests(),
        ];
    }
}
