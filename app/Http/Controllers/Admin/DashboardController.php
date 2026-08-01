<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MeetingSession;
use App\Models\Member;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $recentSessions = MeetingSession::query()
            ->latest('date')
            ->take(10)
            ->withCount([
                'attendances',
                'attendances as present_count' => fn ($query) => $query->where('present', true),
            ])
            ->get();

        $rates = $recentSessions
            ->filter(fn (MeetingSession $session) => $session->attendances_count > 0)
            ->map(fn (MeetingSession $session) => round($session->present_count / $session->attendances_count * 100));

        $lastSession = $recentSessions->first();

        return view('admin.dashboard', [
            'activeMembersCount' => Member::count(),
            'totalSessionsCount' => MeetingSession::count(),
            'averageAttendanceRate' => $rates->isNotEmpty() ? (int) round($rates->average()) : null,
            'lastSession' => $lastSession,
            'attendanceTrend' => $recentSessions->reverse()->values(),
            'lastSessionBreakdown' => $lastSession
                ? $lastSession->attendances()->where('present', true)->get()->groupBy(fn ($attendance) => $attendance->groupLabel)->map->count()
                : collect(),
            'recentSessions' => $recentSessions->take(5),
        ]);
    }
}
