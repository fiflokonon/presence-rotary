<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use Illuminate\Http\RedirectResponse;

class AttendanceController extends Controller
{
    public function togglePresent(Attendance $attendance): RedirectResponse
    {
        $attendance->update(['present' => ! $attendance->present]);

        return redirect()->route('admin.sessions.show', $attendance->meeting_session_id);
    }
}
