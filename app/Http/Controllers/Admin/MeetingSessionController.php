<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMeetingSessionRequest;
use App\Http\Requests\ToggleMeetingSessionOpenRequest;
use App\Jobs\SendAttendanceThankYouMailJob;
use App\Models\Attendance;
use App\Models\MeetingSession;
use App\Models\Title;
use App\Services\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class MeetingSessionController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

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

        $tenantId = $this->tenantContext->current()->id;

        $meetingSession->attendances()
            ->where('present', true)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->get()
            ->each(fn (Attendance $attendance) => SendAttendanceThankYouMailJob::dispatch(
                $tenantId, $attendance->id, $meetingSession->id, $nextSessionTitle, $nextSessionDate
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
            'groups' => $this->buildGroups($this->principalTitles()),
        ]);
    }

    public function exportPdf(MeetingSession $meetingSession): Response
    {
        $pdf = Pdf::loadView('admin.sessions.pdf', [
            'meetingSession' => $meetingSession,
            'attendances' => $meetingSession->attendances()->with(['title', 'position'])->get(),
            'groupLabels' => [...$this->principalTitles()->pluck('name')->all(), Title::OTHER_ORGANIZATIONS_LABEL],
        ]);

        $filename = $meetingSession->date->translatedFormat('Y-m-d').' - '.$meetingSession->title.'.pdf';
        $filename = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '', $filename);

        return $pdf->download($filename);
    }

    /**
     * @return Collection<int, Title>
     */
    private function principalTitles(): Collection
    {
        return Title::principal()->orderBy('order')->orderBy('name')->get();
    }

    /**
     * @param  Collection<int, Title>  $principalTitles
     * @return array<int, array{label: string, colors: array{bg: string, accent: string}}>
     */
    private function buildGroups(Collection $principalTitles): array
    {
        $palette = [
            ['bg' => '#EAF1FB', 'accent' => '#17458F'],
            ['bg' => '#E7F5F1', 'accent' => '#0E7C66'],
            ['bg' => '#FDF3E2', 'accent' => '#C77700'],
        ];

        $groups = $principalTitles->values()->map(fn (Title $title, int $index): array => [
            'label' => $title->name,
            'colors' => $palette[$index],
        ])->all();

        $groups[] = ['label' => Title::OTHER_ORGANIZATIONS_LABEL, 'colors' => ['bg' => '#F1EFEA', 'accent' => '#6B6558']];

        return $groups;
    }
}
