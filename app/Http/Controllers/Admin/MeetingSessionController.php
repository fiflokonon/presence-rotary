<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMeetingSessionRequest;
use App\Models\MeetingSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

class MeetingSessionController extends Controller
{
    public function index(): View
    {
        return view('admin.sessions.index', [
            'meetingSessions' => MeetingSession::orderByDesc('date')->orderByDesc('time')->get(),
        ]);
    }

    public function store(StoreMeetingSessionRequest $request): RedirectResponse
    {
        $meetingSession = MeetingSession::create([
            ...$request->validated(),
            'is_open' => true,
        ]);

        $meetingSession->activate();

        return $this->redirectToSession($meetingSession);
    }

    public function toggleOpen(MeetingSession $meetingSession): RedirectResponse
    {
        $meetingSession->update(['is_open' => ! $meetingSession->is_open]);

        return $this->redirectToSession($meetingSession);
    }

    public function show(MeetingSession $meetingSession): View
    {
        return view('admin.sessions.show', [
            'meetingSession' => $meetingSession,
            'attendances' => $meetingSession->attendances,
        ]);
    }

    /**
     * Redirect to the session detail page (Task 9). Falls back to the
     * sessions index until `admin.sessions.show` is registered, since
     * resolving a redirect to an unregistered named route throws
     * immediately rather than only when followed.
     */
    private function redirectToSession(MeetingSession $meetingSession): RedirectResponse
    {
        return Route::has('admin.sessions.show')
            ? redirect()->route('admin.sessions.show', $meetingSession)
            : redirect()->route('admin.sessions.index');
    }
}
