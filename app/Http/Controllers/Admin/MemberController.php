<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateMemberRequest;
use App\Models\Attendance;
use App\Models\Member;
use App\Models\Title;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MemberController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->string('search'));

        $members = Member::query()
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('club', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->get();

        return view('admin.members.index', [
            'members' => $members,
            'search' => $search,
        ]);
    }

    public function show(Member $member): View
    {
        $attendances = $member->attendances()
            ->with('meetingSession')
            ->get()
            ->sortByDesc(fn (Attendance $attendance) => $attendance->meetingSession->date);

        return view('admin.members.show', [
            'member' => $member,
            'attendances' => $attendances,
        ]);
    }

    public function edit(Member $member): View
    {
        return view('admin.members.edit', [
            'member' => $member,
            'titles' => Title::with('positions')->orderBy('name')->get(),
        ]);
    }

    public function update(UpdateMemberRequest $request, Member $member): RedirectResponse
    {
        $member->update($request->validated());

        return redirect()->route('admin.members.show', $member);
    }
}
