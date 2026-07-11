# Attendance Thank-You Email Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an admin optionally send a formatted thank-you email (logo, attendee name, optional next-session date) to every present attendee with an email address when they close a meeting session.

**Architecture:** A new `ShouldQueue` Mailable (`AttendanceThankYouMail`) + plain-HTML Blade view render the email. `MeetingSessionController::toggleOpen` gains a `ToggleMeetingSessionOpenRequest` and, on an open→closed transition with the checkbox set, loops over present attendees with an email and queues one mailable each. The admin UI turns the existing "Clôturer la séance" button into an Alpine popover panel (same pattern as the existing QR code panel) holding the checkboxes and the next-session picker.

**Tech Stack:** Laravel 13 Mail (`ShouldQueue`, database queue already configured), Form Requests, Blade + Alpine.js, Pest v4 (`Mail::fake()`, `assertSeeInHtml()`).

## Global Constraints

- Recipients: only `Attendance` rows on the closing session with `present = true` and a non-empty `email`.
- The Mailable implements `ShouldQueue` — sending must never block the close-session HTTP request.
- No "already sent" tracking — the checkbox is a deliberate manual choice each time, per user's explicit call.
- Email view uses plain inline-styled HTML (no Tailwind/`@vite`), matching the navy `#12213D` / gold `#F2B94D` palette used elsewhere.
- Logo images always use a fixed height with `width:auto` (or an explicit `width` attribute) — never force both dimensions equal — since the source file is a wide ~4:1 lockup, not a square icon.
- Run `vendor/bin/pint --dirty --format agent` after any PHP/Blade file changes.
- Use `Mail::assertQueued()` / `assertNotQueued()` / `assertNothingQueued()`, never `assertSent()`, since the mailable is queued.
- Call `Mail::fake()` after factory setup in tests, never before (mirrors the project's `Event::fake()` convention).

---

### Task 1: `AttendanceThankYouMail` Mailable and email view

**Files:**
- Create: `app/Mail/AttendanceThankYouMail.php`
- Create: `resources/views/mail/attendance-thank-you.blade.php`
- Test: `tests/Feature/Mail/AttendanceThankYouMailTest.php`

**Interfaces:**
- Consumes: `App\Models\Attendance`, `App\Models\MeetingSession` (existing).
- Produces: `App\Mail\AttendanceThankYouMail` with constructor
  `(Attendance $attendance, MeetingSession $meetingSession, ?string $nextSessionTitle = null, ?Carbon $nextSessionDate = null)`,
  public properties of the same names, `ShouldQueue`. Used by Task 2's controller.

- [ ] **Step 1: Scaffold the Mailable**

```bash
php artisan make:mail AttendanceThankYouMail --no-interaction
```

- [ ] **Step 2: Write the failing content test**

```bash
php artisan make:test --pest Mail/AttendanceThankYouMailTest
```

Replace the generated file's contents with:

```php
<?php

use App\Mail\AttendanceThankYouMail;
use App\Models\Attendance;
use App\Models\MeetingSession;
use Illuminate\Support\Carbon;

it('renders the attendee name and session details in the email body', function () {
    $meetingSession = MeetingSession::factory()->create([
        'title' => 'Réunion hebdomadaire',
        'date' => '2026-07-11',
    ]);
    $attendance = Attendance::factory()->for($meetingSession)->create(['name' => 'Jean Dupont']);

    $mailable = new AttendanceThankYouMail($attendance, $meetingSession);

    $mailable->assertHasSubject('Merci pour votre présence — RC Cotonou Nexus');
    $mailable->assertSeeInHtml('Jean Dupont');
    $mailable->assertSeeInHtml('Réunion hebdomadaire');
    $mailable->assertSeeInHtml('11 juillet 2026');
    $mailable->assertSeeInHtml('rotary-nexus-logo.png');
});

it('mentions the next session with its title when one is provided', function () {
    $meetingSession = MeetingSession::factory()->create();
    $attendance = Attendance::factory()->for($meetingSession)->create();

    $mailable = new AttendanceThankYouMail(
        $attendance,
        $meetingSession,
        nextSessionTitle: 'Assemblée annuelle',
        nextSessionDate: Carbon::parse('2026-08-15'),
    );

    $mailable->assertSeeInHtml('Assemblée annuelle');
    $mailable->assertSeeInHtml('15 août 2026');
});

it('mentions only the date when no next session title is provided', function () {
    $meetingSession = MeetingSession::factory()->create();
    $attendance = Attendance::factory()->for($meetingSession)->create();

    $mailable = new AttendanceThankYouMail(
        $attendance,
        $meetingSession,
        nextSessionDate: Carbon::parse('2026-08-15'),
    );

    $mailable->assertSeeInHtml('15 août 2026');
});

it('omits any next-session mention when none is provided', function () {
    $meetingSession = MeetingSession::factory()->create();
    $attendance = Attendance::factory()->for($meetingSession)->create();

    $mailable = new AttendanceThankYouMail($attendance, $meetingSession);

    $mailable->assertDontSeeInHtml('prochaine séance');
});
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `php artisan test --compact --filter=AttendanceThankYouMailTest`
Expected: FAIL (constructor signature mismatch / view not found — the generated Mailable stub doesn't match yet)

- [ ] **Step 4: Implement the Mailable**

Replace `app/Mail/AttendanceThankYouMail.php` with:

```php
<?php

namespace App\Mail;

use App\Models\Attendance;
use App\Models\MeetingSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class AttendanceThankYouMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Attendance $attendance,
        public MeetingSession $meetingSession,
        public ?string $nextSessionTitle = null,
        public ?Carbon $nextSessionDate = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Merci pour votre présence — RC Cotonou Nexus',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.attendance-thank-you',
        );
    }
}
```

- [ ] **Step 5: Create the email view**

Create `resources/views/mail/attendance-thank-you.blade.php`:

```blade
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Merci pour votre présence</title>
</head>
<body style="margin:0; padding:0; background-color:#F5F3EE; font-family: Arial, Helvetica, sans-serif; color:#12213D;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#F5F3EE; padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px; background-color:#ffffff; border-radius:12px; overflow:hidden;">
                    <tr>
                        <td style="background-color:#12213D; padding:24px; text-align:center;">
                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto; background-color:#ffffff; border-radius:12px;">
                                <tr>
                                    <td style="padding:8px 16px;">
                                        <img src="{{ asset('assets/rotary-nexus-logo.png') }}" alt="RC Cotonou Nexus" width="180" style="display:block; height:auto; width:180px;">
                                    </td>
                                </tr>
                            </table>
                            <p style="margin:16px 0 0; color:#ffffff; font-size:16px; font-weight:bold;">RC Cotonou Nexus</p>
                            <p style="margin:4px 0 0; color:#F2B94D; font-size:11px; letter-spacing:0.05em; text-transform:uppercase;">District 9103</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px 24px;">
                            <p style="margin:0 0 16px; font-size:16px;">Bonjour {{ $attendance->name }},</p>
                            <p style="margin:0 0 16px; font-size:15px; line-height:1.6;">
                                Merci pour votre présence à <strong>{{ $meetingSession->title }}</strong>
                                du {{ $meetingSession->date->translatedFormat('d F Y') }}.
                            </p>
                            <p style="margin:0 0 16px; font-size:15px; line-height:1.6;">
                                Au plaisir de vous revoir lors de notre prochaine réunion !
                            </p>
                            @if ($nextSessionDate)
                                <p style="margin:0 0 16px; font-size:15px; line-height:1.6; padding:12px 16px; background-color:#F5F3EE; border-radius:8px;">
                                    @if ($nextSessionTitle)
                                        Notre prochaine séance, <strong>{{ $nextSessionTitle }}</strong>, aura lieu le
                                        {{ $nextSessionDate->translatedFormat('d F Y') }}.
                                    @else
                                        Notre prochaine séance aura lieu le {{ $nextSessionDate->translatedFormat('d F Y') }}.
                                    @endif
                                </p>
                            @endif
                            <p style="margin:24px 0 0; font-size:15px;">À bientôt,<br>RC Cotonou Nexus</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test --compact --filter=AttendanceThankYouMailTest`
Expected: PASS (4 passed)

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Mail/AttendanceThankYouMail.php resources/views/mail/attendance-thank-you.blade.php tests/Feature/Mail/AttendanceThankYouMailTest.php
git commit -m "feat: add attendance thank-you email mailable and view"
```

---

### Task 2: Validation + controller logic to send the emails on close

**Files:**
- Create: `app/Http/Requests/ToggleMeetingSessionOpenRequest.php`
- Modify: `app/Http/Controllers/Admin/MeetingSessionController.php`
- Test: `tests/Feature/Admin/AttendanceThankYouEmailTest.php`

**Interfaces:**
- Consumes: `App\Mail\AttendanceThankYouMail` from Task 1.
- Produces: `$upcomingSessions` (an `Illuminate\Support\Collection<int, MeetingSession>`) passed to the `admin.sessions.show` view — consumed by Task 3's Blade panel.

- [ ] **Step 1: Write the failing controller/sending tests**

```bash
php artisan make:test --pest Admin/AttendanceThankYouEmailTest
```

Replace the generated file's contents with:

```php
<?php

use App\Mail\AttendanceThankYouMail;
use App\Models\Attendance;
use App\Models\MeetingSession;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

it('does not send any email when closing without checking the box', function () {
    $meetingSession = MeetingSession::factory()->create(['is_open' => true]);
    Attendance::factory()->for($meetingSession)->create(['present' => true, 'email' => 'present@example.com']);
    $admin = User::factory()->create();

    Mail::fake();

    $this->actingAs($admin)
        ->post(route('admin.sessions.toggle-open', $meetingSession))
        ->assertRedirect();

    Mail::assertNothingQueued();
});

it('queues a thank-you email only to present attendees with an email when closing with the box checked', function () {
    $meetingSession = MeetingSession::factory()->create(['is_open' => true]);
    $withEmail = Attendance::factory()->for($meetingSession)->create(['present' => true, 'email' => 'present@example.com']);
    Attendance::factory()->for($meetingSession)->create(['present' => true, 'email' => null]);
    Attendance::factory()->for($meetingSession)->create(['present' => false, 'email' => 'absent@example.com']);
    $admin = User::factory()->create();

    Mail::fake();

    $this->actingAs($admin)
        ->post(route('admin.sessions.toggle-open', $meetingSession), [
            'send_thank_you_email' => '1',
        ])
        ->assertRedirect();

    Mail::assertQueued(
        AttendanceThankYouMail::class,
        fn (AttendanceThankYouMail $mail) => $mail->hasTo($withEmail->email)
    );
    Mail::assertQueuedCount(1);
});

it('never sends mail when reopening an already-closed session', function () {
    $meetingSession = MeetingSession::factory()->create(['is_open' => false]);
    Attendance::factory()->for($meetingSession)->create(['present' => true, 'email' => 'present@example.com']);
    $admin = User::factory()->create();

    Mail::fake();

    $this->actingAs($admin)
        ->post(route('admin.sessions.toggle-open', $meetingSession), [
            'send_thank_you_email' => '1',
        ])
        ->assertRedirect();

    Mail::assertNothingQueued();
});

it('passes the selected upcoming session title and date when mentioning the next session', function () {
    $meetingSession = MeetingSession::factory()->create(['is_open' => true]);
    Attendance::factory()->for($meetingSession)->create(['present' => true, 'email' => 'present@example.com']);
    $nextSession = MeetingSession::factory()->create([
        'title' => 'Assemblée annuelle',
        'date' => now()->addWeek()->toDateString(),
    ]);
    $admin = User::factory()->create();

    Mail::fake();

    $this->actingAs($admin)
        ->post(route('admin.sessions.toggle-open', $meetingSession), [
            'send_thank_you_email' => '1',
            'mention_next_session' => '1',
            'next_session_option' => "session:{$nextSession->id}",
        ])
        ->assertRedirect();

    Mail::assertQueued(
        AttendanceThankYouMail::class,
        fn (AttendanceThankYouMail $mail) => $mail->nextSessionTitle === $nextSession->title
            && $mail->nextSessionDate->isSameDay($nextSession->date)
    );
});

it('passes a manually typed next session date without a title', function () {
    $meetingSession = MeetingSession::factory()->create(['is_open' => true]);
    Attendance::factory()->for($meetingSession)->create(['present' => true, 'email' => 'present@example.com']);
    $admin = User::factory()->create();

    Mail::fake();

    $this->actingAs($admin)
        ->post(route('admin.sessions.toggle-open', $meetingSession), [
            'send_thank_you_email' => '1',
            'mention_next_session' => '1',
            'next_session_option' => 'manual',
            'next_session_date' => '2026-08-15',
        ])
        ->assertRedirect();

    Mail::assertQueued(
        AttendanceThankYouMail::class,
        fn (AttendanceThankYouMail $mail) => $mail->nextSessionTitle === null
            && $mail->nextSessionDate->toDateString() === '2026-08-15'
    );
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=AttendanceThankYouEmailTest`
Expected: FAIL — at minimum the "checked" scenarios fail because no mail is ever queued yet (the first "unchecked" test may already pass, that's fine).

- [ ] **Step 3: Create the Form Request**

```bash
php artisan make:request ToggleMeetingSessionOpenRequest --no-interaction
```

Replace its contents with:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ToggleMeetingSessionOpenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'send_thank_you_email' => ['nullable', 'boolean'],
            'mention_next_session' => ['nullable', 'boolean'],
            'next_session_option' => ['nullable', 'string'],
            'next_session_date' => ['nullable', 'date'],
        ];
    }
}
```

- [ ] **Step 4: Update the controller**

In `app/Http/Controllers/Admin/MeetingSessionController.php`, add imports:

```php
use App\Http\Requests\ToggleMeetingSessionOpenRequest;
use App\Mail\AttendanceThankYouMail;
use App\Models\Attendance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
```

Replace the `toggleOpen` method:

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
```

Update the `show` method to also pass upcoming sessions:

```php
    public function show(MeetingSession $meetingSession): View
    {
        return view('admin.sessions.show', [
            'meetingSession' => $meetingSession,
            'attendances' => $meetingSession->attendances,
            'upcomingSessions' => MeetingSession::where('id', '!=', $meetingSession->id)
                ->where('date', '>=', now()->toDateString())
                ->orderBy('date')
                ->get(),
        ]);
    }
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=AttendanceThankYouEmailTest`
Expected: PASS (5 passed)

- [ ] **Step 6: Run the full test suite to check for regressions**

Run: `php artisan test --compact`
Expected: all tests PASS (existing `tests/Feature/Admin/MeetingSessionManagementTest.php` `show` route now also loads `$upcomingSessions` — confirm nothing else broke)

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Requests/ToggleMeetingSessionOpenRequest.php \
    app/Http/Controllers/Admin/MeetingSessionController.php \
    tests/Feature/Admin/AttendanceThankYouEmailTest.php
git commit -m "feat: queue thank-you emails to present attendees on session close"
```

---

### Task 3: Close-session panel UI (checkboxes + next-session picker)

**Files:**
- Modify: `resources/views/admin/sessions/show.blade.php`
- Modify: `resources/js/app.js` (add `closeSessionPanel` Alpine component)
- Test: `tests/Feature/Admin/AttendanceThankYouEmailTest.php` (append UI-presence tests)

**Interfaces:**
- Consumes: `$upcomingSessions` from Task 2's `show()`; `Alpine.data('closeSessionPanel', ...)`.
- Produces: nothing consumed by a later task — this is the last functional task.

- [ ] **Step 1: Write the failing UI tests**

Append to `tests/Feature/Admin/AttendanceThankYouEmailTest.php`:

```php
it('shows the close-session panel with the thank-you email options when the session is open', function () {
    $meetingSession = MeetingSession::factory()->create(['is_open' => true]);
    MeetingSession::factory()->create([
        'title' => 'Assemblée annuelle',
        'date' => now()->addWeek()->toDateString(),
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.show', $meetingSession))
        ->assertOk()
        ->assertSee('closeSessionPanel(', false)
        ->assertSee('Envoyer un mail de remerciement aux présents')
        ->assertSee('Mentionner la prochaine séance')
        ->assertSee('Assemblée annuelle');
});

it('does not show the close-session panel checkboxes when the session is already closed', function () {
    $meetingSession = MeetingSession::factory()->create(['is_open' => false]);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.show', $meetingSession))
        ->assertOk()
        ->assertDontSee('Envoyer un mail de remerciement aux présents');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=AttendanceThankYouEmailTest`
Expected: FAIL on the two new tests (panel markup doesn't exist yet)

- [ ] **Step 3: Add the `closeSessionPanel` Alpine component**

In `resources/js/app.js`, insert this block after the existing `Alpine.data('adminShell', ...)` block and before `Alpine.store('pageLoading', ...)`:

```js
Alpine.data('closeSessionPanel', (hasUpcomingSessions, initialNextSessionOption) => ({
    open: false,
    sendThankYouEmail: false,
    mentionNextSession: false,
    nextSessionOption: initialNextSessionOption,
    toggle() {
        this.open = !this.open;
    },
}));
```

- [ ] **Step 4: Replace the toggle-open form in `resources/views/admin/sessions/show.blade.php`**

Replace:

```blade
                    <form method="POST" action="{{ route('admin.sessions.toggle-open', $meetingSession) }}" class="w-full md:w-auto">
                        @csrf
                        <button type="submit" class="cursor-pointer w-full text-sm font-semibold text-navy underline md:w-auto">
                            {{ $meetingSession->is_open ? 'Clôturer la séance' : 'Rouvrir la séance' }}
                        </button>
                    </form>
```

with:

```blade
                    @if ($meetingSession->is_open)
                        <div
                            x-data="closeSessionPanel(@js($upcomingSessions->isNotEmpty()), @js($upcomingSessions->isNotEmpty() ? 'session:'.$upcomingSessions->first()->id : 'manual'))"
                            class="relative w-full md:w-auto"
                        >
                            <button type="button" @click="toggle()"
                                class="cursor-pointer w-full text-sm font-semibold text-navy underline md:w-auto">
                                Clôturer la séance
                            </button>
                            <div x-show="open" x-cloak @click.outside="open = false"
                                class="absolute right-0 top-full z-10 mt-2 w-80 rounded-xl border border-divider bg-white p-4 text-left shadow-[0_2px_10px_rgba(20,30,50,.06)]">
                                <form method="POST" action="{{ route('admin.sessions.toggle-open', $meetingSession) }}" class="flex flex-col gap-3">
                                    @csrf
                                    <label class="flex items-start gap-2 text-sm text-navy">
                                        <input type="checkbox" name="send_thank_you_email" value="1" x-model="sendThankYouEmail" class="mt-0.5">
                                        Envoyer un mail de remerciement aux présents
                                    </label>
                                    <label x-show="sendThankYouEmail" x-cloak class="flex items-start gap-2 text-sm text-navy">
                                        <input type="checkbox" name="mention_next_session" value="1" x-model="mentionNextSession" class="mt-0.5">
                                        Mentionner la prochaine séance
                                    </label>
                                    <div x-show="sendThankYouEmail && mentionNextSession" x-cloak class="flex flex-col gap-2">
                                        @if ($upcomingSessions->isNotEmpty())
                                            <select name="next_session_option" x-model="nextSessionOption"
                                                class="rounded-lg border border-border px-3 py-2 text-sm">
                                                @foreach ($upcomingSessions as $upcomingSession)
                                                    <option value="session:{{ $upcomingSession->id }}">
                                                        {{ $upcomingSession->title }} — {{ $upcomingSession->date->translatedFormat('d F Y') }}
                                                    </option>
                                                @endforeach
                                                <option value="manual">Autre date…</option>
                                            </select>
                                        @endif
                                        <input type="date" name="next_session_date" x-show="nextSessionOption === 'manual'" x-cloak
                                            class="rounded-lg border border-border px-3 py-2 text-sm">
                                    </div>
                                    <button type="submit"
                                        class="cursor-pointer rounded-lg bg-navy px-4 py-2 text-sm font-bold text-white hover:bg-navy-hover">
                                        Confirmer la clôture
                                    </button>
                                </form>
                            </div>
                        </div>
                    @else
                        <form method="POST" action="{{ route('admin.sessions.toggle-open', $meetingSession) }}" class="w-full md:w-auto">
                            @csrf
                            <button type="submit" class="cursor-pointer w-full text-sm font-semibold text-navy underline md:w-auto">
                                Rouvrir la séance
                            </button>
                        </form>
                    @endif
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=AttendanceThankYouEmailTest`
Expected: PASS (7 passed)

- [ ] **Step 6: Run the full test suite to check for regressions**

Run: `php artisan test --compact`
Expected: all tests PASS

- [ ] **Step 7: Build frontend assets**

Run: `npm run build`
Expected: build succeeds with no errors

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/admin/sessions/show.blade.php resources/js/app.js tests/Feature/Admin/AttendanceThankYouEmailTest.php
git commit -m "feat: add close-session panel to opt into the thank-you email"
```

---

### Task 4: Manual verification

**Files:** none (verification only)

- [ ] **Step 1: Confirm the dev server (`composer run dev`) is running**

If not already running, ask the user to start it, or run `composer run dev` in the background.

- [ ] **Step 2: Seed a session with present attendees who have emails**

```bash
php artisan tinker --execute '
$session = App\Models\MeetingSession::factory()->create(["is_open" => true, "title" => "Vérification manuelle"]);
App\Models\Attendance::factory()->for($session)->create(["name" => "Test Un", "email" => "test1@example.com", "present" => true]);
App\Models\Attendance::factory()->for($session)->create(["name" => "Test Deux", "email" => null, "present" => true]);
echo $session->id;
'
```

- [ ] **Step 3: Log in as admin and open the session's detail page**

Log in with `admin@rotarynexus.test` (see `database/seeders/DatabaseSeeder.php`), navigate to `/admin/sessions/{id}` for the session created above.

- [ ] **Step 4: Click "Clôturer la séance" and confirm the panel**

Confirm the popover opens with both checkboxes and, once "Envoyer un mail de remerciement" is checked, "Mentionner la prochaine séance" appears. Check it too and confirm either the dropdown (if another future session exists in the seeded data) or the date input appears.

- [ ] **Step 5: Confirm the closure and inspect the queued/sent email**

Submit the form. Since `MAIL_MAILER=log` in `.env`, check `storage/logs/laravel.log` for the rendered email (the queue worker started via `composer run dev`'s `queue:listen` processes it automatically):

```bash
tail -n 100 storage/logs/laravel.log | grep -A 30 "Merci pour votre présence"
```

Confirm exactly one email was logged (for "Test Un", not "Test Deux" who has no email), containing the logo `<img>` tag, "Test Un", "Vérification manuelle", and — if a next session was mentioned — the expected date/title.

- [ ] **Step 6: Report results to the user**

Summarize what was verified (panel behavior, recipient filtering, email content) and flag anything unexpected for follow-up.
