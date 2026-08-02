<?php

namespace App\Http\Controllers\Admin;

use App\Exports\MembersImportTemplateExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMemberRequest;
use App\Http\Requests\UpdateMemberRequest;
use App\Models\Attendance;
use App\Models\Member;
use App\Models\Title;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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

    public function create(): View
    {
        return view('admin.members.create', [
            'titles' => Title::activeOrId(null)
                ->with(['positions' => fn ($query) => $query->activeOrId(null)])
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(StoreMemberRequest $request): RedirectResponse
    {
        $member = Member::create([
            ...$request->safe()->except('is_club_member'),
            'is_club_member' => $request->boolean('is_club_member'),
        ]);

        return redirect()->route('admin.members.show', $member);
    }

    public function importTemplate(): BinaryFileResponse
    {
        return Excel::download(new MembersImportTemplateExport, 'gabarit-membres-du-club.xlsx');
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
            'titles' => Title::activeOrId($member->title_id)
                ->with(['positions' => fn ($query) => $query->activeOrId($member->position_id)])
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function update(UpdateMemberRequest $request, Member $member): RedirectResponse
    {
        $member->update([
            ...$request->safe()->except('is_club_member'),
            'is_club_member' => $request->boolean('is_club_member'),
        ]);

        return redirect()->route('admin.members.show', $member);
    }
}
