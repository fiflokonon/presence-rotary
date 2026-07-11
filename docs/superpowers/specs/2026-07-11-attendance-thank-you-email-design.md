# Attendance thank-you email on session close — spec

Date: 2026-07-11

## Context

`app/Http/Controllers/Admin/MeetingSessionController.php:34-39` (`toggleOpen`) is a
single action that flips `MeetingSession::is_open` — used both to close an open
session and to reopen a closed one. It's triggered from a plain
`<form method="POST">` in `resources/views/admin/sessions/show.blade.php:60-65`,
next to a "QR code" popover (`resources/views/admin/sessions/show.blade.php:32-51`,
Alpine `qrCodePanel` component in `resources/js/app.js`) that establishes the
existing pattern for an inline popover panel.

`Attendance` (`app/Models/Attendance.php`) has a nullable `email` column and a
`present` boolean (toggled via `AttendanceController::togglePresent`). No
`app/Mail` classes exist yet. `QUEUE_CONNECTION=database` and the `jobs` table
migration are already in place, and `composer.json`'s `dev` script already runs
`php artisan queue:listen` alongside Vite/Pail — a queued Mailable will be
processed automatically in local dev with no extra setup.

## Goal

When an admin closes a session, let them optionally send a formatted
thank-you email (with the club logo, the attendee's name, and a wish to see
them again) to every attendee who was marked present and provided an email
address. Optionally, the email can also mention the next session's date —
picked from an existing future `MeetingSession` in the database, or typed in
by hand if there's no matching record yet.

## Design

### 1. UI: close-session panel

The "Clôturer la séance" trigger becomes a popover panel (Alpine
`closeSessionPanel`, structurally identical to the existing `qrCodePanel`
pattern: `x-data="closeSessionPanel()"`, `@click="toggle()"` on the trigger
button, `x-show="open"` + `@click.outside="open = false"` on the panel). It
replaces the current plain submit button, but only when the session is
**open** — the "Rouvrir la séance" path (session currently closed) is
untouched, still a plain one-click `<form>` submit.

Inside the panel, one `<form method="POST" action="{{ route('admin.sessions.toggle-open', $meetingSession) }}">`:

1. Checkbox `send_thank_you_email` — "Envoyer un mail de remerciement aux présents".
2. Checkbox `mention_next_session` — "Mentionner la prochaine séance" — only
   shown (`x-show`) once checkbox 1 is checked.
3. If there are upcoming sessions in the database (see query below) and
   checkbox 2 is checked: a `<select name="next_session_option">` listing
   each as `"{title} — {date}"` with value `"session:{id}"`, plus a trailing
   `"Autre date…"` option with value `"manual"`. Choosing "Autre date…"
   reveals (`x-show="nextSessionOption === 'manual'"`) a `<input
   type="date" name="next_session_date">`. If there are **no** upcoming
   sessions in the database at all, the `<select>` isn't rendered — the date
   input shows directly once checkbox 2 is checked.
4. Submit button — "Confirmer la clôture".

All fields are inside the same form as the close action itself — no separate
AJAX round-trip; unchecked/unselected fields are simply absent or ignored
server-side, so there's no need to strip hidden inputs from the DOM before
submit.

**Upcoming sessions query** (in `MeetingSessionController::show`, passed to
the view as `$upcomingSessions`):

```php
MeetingSession::where('id', '!=', $meetingSession->id)
    ->where('date', '>=', now()->toDateString())
    ->orderBy('date')
    ->get();
```

### 2. Validation

New `app/Http/Requests/ToggleMeetingSessionOpenRequest.php`:

```php
public function rules(): array
{
    return [
        'send_thank_you_email' => ['nullable', 'boolean'],
        'mention_next_session' => ['nullable', 'boolean'],
        'next_session_option' => ['nullable', 'string'],
        'next_session_date' => ['nullable', 'date'],
    ];
}
```

All fields stay optional at the validation layer since the same route/request
also handles the no-op "reopen" case, where none of them are submitted.

### 3. Controller logic

`MeetingSessionController::toggleOpen` captures the pre-update `is_open`
state, flips it as today, and — only on an open→closed transition with
`send_thank_you_email` checked — resolves the "next session" info (from the
selected existing session, or from the manually typed date, or neither) and
queues one `AttendanceThankYouMail` per present attendee with a non-empty
email:

```php
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
            $nextSessionDate = Carbon::parse($request->string('next_session_date'));
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
```

No de-duplication/"already sent" tracking — the checkbox is a deliberate,
manual choice each time a session is closed (including a close → reopen →
re-close cycle), matching the user's explicit call on this point.

### 4. Mailable

New `app/Mail/AttendanceThankYouMail.php`, implementing `ShouldQueue` (so
sending doesn't block the close-session request):

```php
class AttendanceThankYouMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Attendance $attendance,
        public MeetingSession $meetingSession,
        public ?string $nextSessionTitle = null,
        public ?Carbon $nextSessionDate = null,
    ) {}

    public function build(): self
    {
        return $this->subject('Merci pour votre présence — RC Cotonou Nexus')
            ->view('mail.attendance-thank-you');
    }
}
```

### 5. Email view

New `resources/views/mail/attendance-thank-you.blade.php` — plain HTML with
inline styles (no Tailwind classes/`@vite`, for email client compatibility),
matching the site's navy (`#12213D`) / gold (`#F2B94D`) palette:

- Club logo at the top (`asset('assets/rotary-nexus-logo.png')`), sized via
  an explicit `width` attribute with `height:auto` inline style — same
  aspect-ratio fix as the rest of the app (the logo is a wide ~4:1 lockup,
  not a square icon).
- Personalized greeting: "Bonjour {{ $attendance->name }},".
- Thank-you body naming the session: "Merci pour votre présence à
  {{ $meetingSession->title }} du {{ $meetingSession->date->translatedFormat('d F Y') }}."
- Wish to see them again: "Au plaisir de vous revoir lors de notre prochaine
  réunion !"
- Conditionally, if `$nextSessionDate` is set: "Notre prochaine séance,
  {{ $nextSessionTitle }}, aura lieu le {{ $nextSessionDate->translatedFormat('d F Y') }}."
  when `$nextSessionTitle` is also known, or "Notre prochaine séance aura
  lieu le {{ $nextSessionDate->translatedFormat('d F Y') }}." when only the
  date was typed in manually.

### 6. Files touched

- `app/Http/Controllers/Admin/MeetingSessionController.php` (`toggleOpen` +
  `sendThankYouEmails`, `show` passes `$upcomingSessions`)
- `app/Http/Requests/ToggleMeetingSessionOpenRequest.php` (new)
- `app/Mail/AttendanceThankYouMail.php` (new)
- `resources/views/mail/attendance-thank-you.blade.php` (new)
- `resources/views/admin/sessions/show.blade.php` (close-session panel)
- `resources/js/app.js` (new `closeSessionPanel` Alpine component)
- `routes/web.php` (`toggle-open` route now type-hints
  `ToggleMeetingSessionOpenRequest` — no URL/name change)

## Testing

- Feature tests extending `tests/Feature/Admin/MeetingSessionManagementTest.php`
  (or a new `tests/Feature/Admin/AttendanceThankYouEmailTest.php`), using
  `Mail::fake()`:
  - Closing a session with `send_thank_you_email` unchecked sends no mail.
  - Closing a session with it checked sends `AttendanceThankYouMail` only to
    present attendees with a non-empty email (not to absent attendees, not
    to present attendees without an email).
  - Reopening a session (`is_open` already false) never sends mail, even if
    the flags happen to be present in the payload.
  - `mention_next_session` with an existing session selected passes that
    session's title/date into the mailable; with a manually typed date, only
    the date is passed (no title).
- No visual/browser test for the popover interaction itself, consistent with
  how the QR code panel is tested today (not covered by a Dusk/Pest browser
  test) — verified manually per this project's `verify`/`run` skills.

## Out of scope

- Editing/canceling already-queued emails.
- Any "email sent" indicator or history on the session detail page.
- Configuring a real SMTP mailer for production (`MAIL_MAILER=log` stays as
  the local/dev default; this is an existing ops concern, not part of this
  feature).
