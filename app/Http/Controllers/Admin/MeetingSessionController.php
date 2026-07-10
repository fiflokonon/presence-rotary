<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMeetingSessionRequest;
use App\Models\MeetingSession;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
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

        return redirect()->route('admin.sessions.show', $meetingSession);
    }

    public function toggleOpen(MeetingSession $meetingSession): RedirectResponse
    {
        $meetingSession->update(['is_open' => ! $meetingSession->is_open]);

        return redirect()->route('admin.sessions.show', $meetingSession);
    }

    public function show(MeetingSession $meetingSession): View
    {
        return view('admin.sessions.show', [
            'meetingSession' => $meetingSession,
            'attendances' => $meetingSession->attendances,
        ]);
    }

    public function exportPdf(MeetingSession $meetingSession): Response
    {
        $pdf = Pdf::loadView('admin.sessions.pdf', [
            'meetingSession' => $meetingSession,
            'attendances' => $meetingSession->attendances,
        ]);

        return $pdf->download("liste-presence-{$meetingSession->id}.pdf");
    }
}
