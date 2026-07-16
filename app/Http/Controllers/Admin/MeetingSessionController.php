<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMeetingSessionRequest;
use App\Http\Requests\ToggleMeetingSessionOpenRequest;
use App\Mail\AttendanceThankYouMail;
use App\Models\Attendance;
use App\Models\MeetingSession;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
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

    public function toggleOpen(ToggleMeetingSessionOpenRequest $request, MeetingSession $meetingSession): RedirectResponse
    {
        $wasOpen = $meetingSession->is_open;

        $meetingSession->update(['is_open' => ! $wasOpen]);

        if ($wasOpen && $request->boolean('send_thank_you_email')) {
            $this->sendThankYouEmails($request, $meetingSession);
        }

        return redirect()->route('admin.sessions.show', $meetingSession);
    }

    private function sendThankYouEmails(ToggleMeetingSessionOpenRequest $request, MeetingSession $meetingSession): void
    {
        $nextSessionTitle = null;
        $nextSessionDate = null;

        if ($request->boolean('mention_next_session')) {
            $option = (string) $request->string('next_session_option');

            if (str_starts_with($option, 'session:')) {
                $nextSession = MeetingSession::find((int) substr($option, strlen('session:')));
                $nextSessionTitle = $nextSession?->title;
                $nextSessionDate = $nextSession?->date;
            } elseif ($request->filled('next_session_date')) {
                $nextSessionDate = Carbon::parse((string) $request->string('next_session_date'));
            }
        }

        $meetingSession->attendances()
            ->where('present', true)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->get()
            ->each(fn (Attendance $attendance) => Mail::to($attendance->email)->queue(
                new AttendanceThankYouMail($attendance, $meetingSession, $nextSessionTitle, $nextSessionDate)
            ));
    }

    public function show(MeetingSession $meetingSession): View
    {
        return view('admin.sessions.show', [
            'meetingSession' => $meetingSession,
            'attendances' => $meetingSession->attendances()->with(['title', 'position'])->get(),
            'upcomingSessions' => MeetingSession::where('id', '!=', $meetingSession->id)
                ->where('date', '>=', now()->toDateString())
                ->orderBy('date')
                ->get(),
        ]);
    }

    public function exportPdf(MeetingSession $meetingSession): Response
    {
        $pdf = Pdf::loadView('admin.sessions.pdf', [
            'meetingSession' => $meetingSession,
            'attendances' => $meetingSession->attendances()->with(['title', 'position'])->get(),
        ]);

        $filename = $meetingSession->date->translatedFormat('Y-m-d').' - '.$meetingSession->title.'.pdf';
        $filename = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '', $filename);

        return $pdf->download($filename);
    }
}
