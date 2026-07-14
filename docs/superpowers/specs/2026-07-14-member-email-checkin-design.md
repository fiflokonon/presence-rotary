# Member model + email-based check-in — spec

Date: 2026-07-14

## Context

`app/Models/Attendance.php` is a per-meeting check-in record — `title, name,
club, phone, classification, email` are plain columns, `email` is nullable
with no uniqueness constraint (`database/migrations/2026_07_10_140112_create_attendances_table.php`).
There is no persistent "member"/"person" table: the same human creates a
brand-new, disconnected `Attendance` row every meeting. The public check-in
form (`GET /` → `AttendanceFormController::show`, `POST /attendances` →
`AttendanceFormController::store`, `resources/views/attendance/show.blade.php`,
`resources/views/components/attendance-form.blade.php`) is a single-step,
no-JS Blade form with no authentication.

There is no AJAX anywhere in the app — Alpine.js is only used for simple
`x-show`/`x-data` toggles. Admin CRUD screens (`app/Http/Controllers/Admin/`,
e.g. `UserController`, `MeetingSessionController`) are plain
`index`/`create`/`store`/`show` controllers behind the `auth` middleware
group in `routes/web.php`, matching Laravel's default resourceful naming.

## Goal

Let a person entering their attendance type their email first; if they're
already known, pre-fill their name/club/phone/classification/title so they
only need to confirm and submit. Introduce a `Member` model as the
canonical, always-current record for a person, while every `Attendance` row
keeps storing its own snapshot fields exactly as today — so looking at a
member's attendance history still shows what their classification/club
*was* at each past meeting, even after their current info changes. Also add
admin visibility into members (list, search, detail with attendance
history, edit) and prevent the same person from checking into the same
session twice.

## Design

### 1. Data model & migrations

New model `App\Models\Member`, table `members` — the *current* known info
for a person, keyed by email:

```php
Schema::create('members', function (Blueprint $table) {
    $table->id();
    $table->string('title')->nullable();
    $table->string('name');
    $table->string('club');
    $table->string('phone');
    $table->string('classification')->nullable();
    $table->string('email')->unique();
    $table->timestamps();
});
```

`attendances` gets a nullable `member_id`, added in its own migration (kept
separate from the `members` table migration for a clean rollback path):

```php
Schema::table('attendances', function (Blueprint $table) {
    $table->foreignId('member_id')->nullable()->after('meeting_session_id')
        ->constrained()->nullOnDelete();
});
```

All of `attendances`' existing columns (`title, name, club, phone,
classification, email`) are untouched — they remain the frozen snapshot of
that person's info *at that meeting*. `member_id` only links rows that
belong to the same person; it never replaces the snapshot columns, and
nothing that reads those columns today needs to change.

`App\Models\Member`:

```php
class Member extends Model
{
    /** @use HasFactory<MemberFactory> */
    use HasFactory;

    protected $fillable = ['title', 'name', 'club', 'phone', 'classification', 'email'];

    protected function casts(): array
    {
        return ['title' => AttendanceTitle::class];
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public static function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }
}
```

`Attendance::class` gains `member_id` in `$fillable` and a `member(): BelongsTo`.

**Backfill migration** (third migration, after both of the above): for every
distinct normalized, non-blank email among existing `attendances` rows,
create one `Member` seeded from that email's most recent attendance (by
meeting date), then link every attendance sharing that email to it. Rows
with a blank/null email keep `member_id = null` — they simply predate this
feature. Uses the query builder directly (not the Eloquent models), the
standard approach for one-off data migrations:

```php
public function up(): void
{
    $rows = DB::table('attendances')
        ->join('meeting_sessions', 'attendances.meeting_session_id', '=', 'meeting_sessions.id')
        ->whereNotNull('attendances.email')
        ->where('attendances.email', '!=', '')
        ->orderBy('meeting_sessions.date')
        ->orderBy('meeting_sessions.time')
        ->select('attendances.id', 'attendances.title', 'attendances.name', 'attendances.club',
            'attendances.phone', 'attendances.classification', 'attendances.email')
        ->get()
        ->groupBy(fn ($row) => Str::lower(trim($row->email)));

    foreach ($rows as $normalizedEmail => $group) {
        // Rows are ordered oldest-to-newest per session date, so the last
        // one in each group is that email's most recent attendance.
        $latest = $group->last();

        $memberId = DB::table('members')->insertGetId([
            'title' => $latest->title,
            'name' => $latest->name,
            'club' => $latest->club,
            'phone' => $latest->phone,
            'classification' => $latest->classification,
            'email' => $normalizedEmail,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('attendances')->whereIn('id', $group->pluck('id'))->update(['member_id' => $memberId]);
    }
}

public function down(): void
{
    DB::table('attendances')->update(['member_id' => null]);
    DB::table('members')->truncate();
}
```

### 2. Public check-in flow (2 steps, no JS)

**Step 1 — email.** `GET /` (`attendance.show`, unchanged route) shows an
email-only form by default (new component `<x-attendance-email-form>`,
POSTing to a new `attendance.lookup` route).

**Lookup.** `POST /check-in` (`attendance.lookup`, new) — validates the
email, normalizes it, looks up the `Member`, and renders `attendance.show`
directly (no redirect — a lookup is read-only and idempotent, so
resubmission on refresh is harmless) with the full `<x-attendance-form>`
either pre-filled from the member or blank:

```php
public function lookup(LookupAttendanceEmailRequest $request): View
{
    $meetingSession = MeetingSession::active();

    abort_if($meetingSession === null, 404);

    $email = Member::normalizeEmail($request->string('email'));

    return view('attendance.show', [
        'meetingSession' => $meetingSession,
        'email' => $email,
        'member' => Member::firstWhere('email', $email),
    ]);
}
```

`LookupAttendanceEmailRequest` rules: `'email' => ['required', 'email', 'max:255']`.

**Step 2 — confirm & submit.** The email is shown read-only (a hidden input
carries it) with a "Changer d'adresse e-mail" link back to `attendance.show`.
`title/name/club/phone/classification` use `old($field, $member?->$field)`
for pre-fill, same `old()`-repopulation pattern the form already uses today.
`POST /attendances` (`attendance.store`, unchanged route) does the
find-or-create + write-through + duplicate guard:

```php
public function store(StoreAttendanceRequest $request): RedirectResponse
{
    $meetingSession = MeetingSession::active();

    abort_if($meetingSession === null, 404);

    $email = Member::normalizeEmail($request->string('email'));

    $member = Member::updateOrCreate(
        ['email' => $email],
        $request->safe()->only(['title', 'name', 'club', 'phone', 'classification']),
    );

    if (Attendance::where('member_id', $member->id)->where('meeting_session_id', $meetingSession->id)->exists()) {
        return redirect()->route('attendance.show')->with('attendanceAlreadyCheckedIn', true);
    }

    Attendance::create([
        ...$request->validated(),
        'email' => $email,
        'member_id' => $member->id,
        'meeting_session_id' => $meetingSession->id,
        'present' => true,
        'is_late' => ! $meetingSession->is_open,
    ]);

    return redirect()->route('attendance.show')
        ->with('attendanceSubmitted', true)
        ->with('attendanceWasLate', ! $meetingSession->is_open);
}
```

`StoreAttendanceRequest`'s `email` rule changes from `nullable` to
`['required', 'email', 'max:255']` — email is now mandatory, since it's the
entry point of the whole flow.

`Member::updateOrCreate` always writes the submitted values, whether the
user edited a pre-filled field or typed everything fresh — this is exactly
what keeps `Member` "current" per the design goal.

**Validation-failure edge case.** Because step 2 is rendered directly by
`lookup()` (not a distinct GET page), a failed `store()` validation redirects
back to `GET /` per Laravel's default. `show()` must tell whether that
redirect is "back to step 1" or "back to step 2 with errors" — it does this
by checking whether `name` (a step-2-only field; the step-1 lookup form
never submits it) is present in old input:

```php
public function show(): View
{
    $meetingSession = MeetingSession::active();

    // `name` only exists in old-input after a failed step-2 (store) submit,
    // never after a failed step-1 (lookup) submit — use it to tell the two apart.
    $email = session()->hasOldInput('name') ? old('email') : null;

    return view('attendance.show', [
        'meetingSession' => $meetingSession,
        'email' => $email,
        'member' => $email !== null ? Member::firstWhere('email', Member::normalizeEmail($email)) : null,
    ]);
}
```

This reuses Laravel's built-in old-input flash rather than inventing custom
session state, and the existing `old()` calls in `<x-attendance-form>`
continue to repopulate every field correctly with no further change.

**Duplicate check-in guard.** New `attendance.show.blade.php` branch,
alongside the existing `attendanceSubmitted` state:

```blade
@elseif (session('attendanceAlreadyCheckedIn'))
    <div class="flex flex-col items-center gap-3 px-6 py-10 text-center">
        <div class="flex h-14 w-14 items-center justify-center rounded-full bg-[#FDF3E2] text-2xl text-[#C77700]">!</div>
        <p class="font-display text-lg font-extrabold text-[#12213D]">Présence déjà enregistrée</p>
        <p class="text-sm text-[#8A8474]">Vous êtes déjà enregistré(e) pour cette séance.</p>
        <a href="{{ route('attendance.show') }}" class="text-sm font-semibold text-[#12213D] underline">Retour</a>
    </div>
```

### 3. Admin member management

New `Admin\MemberController`, routes alongside `sessions`/`users` in the
existing `auth`-protected `admin.` group:

```php
Route::get('members', [MemberController::class, 'index'])->name('members.index');
Route::get('members/{member}', [MemberController::class, 'show'])->name('members.show');
Route::get('members/{member}/edit', [MemberController::class, 'edit'])->name('members.edit');
Route::put('members/{member}', [MemberController::class, 'update'])->name('members.update');
```

- `index(Request $request)` — list ordered by name, filtered by an optional
  `search` query param matched against `name`/`email`/`club` (`like`).
- `show(Member $member)` — current info plus the member's attendances (with
  `meetingSession`), sorted most-recent-first — this is where classification/
  club changes over time become visible.
- `edit`/`update(StoreMemberRequest $request, Member $member)` — standard
  edit form, no create or delete (members only ever originate from
  check-in).

`StoreMemberRequest`:

```php
public function rules(): array
{
    return [
        'title' => ['nullable', Rule::enum(AttendanceTitle::class)],
        'name' => ['required', 'string', 'max:255'],
        'club' => ['required', 'string', 'max:255'],
        'phone' => ['required', 'string', 'max:50'],
        'classification' => ['nullable', 'string', 'max:255'],
        'email' => ['required', 'email', 'max:255', Rule::unique('members', 'email')->ignore($member)],
    ];
}
```

Views follow existing admin conventions: `admin/members/index.blade.php`
(table + search input, like `admin/users/index.blade.php`),
`admin/members/show.blade.php` (detail + attendance history table, like
`admin/sessions/show.blade.php`), `admin/members/edit.blade.php` (form, like
`admin/users/create.blade.php` but PUT). New sidebar entry ("Membres") in
`resources/views/components/layouts/admin.blade.php`, matching the existing
`Séances`/`Administrateurs`/`Paramètres mail` links.

### 4. Files touched

- `database/migrations/2026_07_15_xxxxxx_create_members_table.php` (new)
- `database/migrations/2026_07_15_xxxxxx_add_member_id_to_attendances_table.php` (new)
- `database/migrations/2026_07_15_xxxxxx_backfill_members_from_attendances.php` (new)
- `app/Models/Member.php` (new)
- `app/Models/Attendance.php` (`member_id` fillable + `member(): BelongsTo`)
- `database/factories/MemberFactory.php` (new)
- `app/Http/Controllers/AttendanceFormController.php` (`lookup()`, `show()`/`store()` changes)
- `app/Http/Requests/StoreAttendanceRequest.php` (`email` becomes required)
- `app/Http/Requests/LookupAttendanceEmailRequest.php` (new)
- `resources/views/attendance/show.blade.php` (stage branching, already-checked-in state)
- `resources/views/components/attendance-email-form.blade.php` (new)
- `resources/views/components/attendance-form.blade.php` (read-only email + member pre-fill)
- `app/Http/Controllers/Admin/MemberController.php` (new)
- `app/Http/Requests/StoreMemberRequest.php` (new)
- `resources/views/admin/members/index.blade.php` (new)
- `resources/views/admin/members/show.blade.php` (new)
- `resources/views/admin/members/edit.blade.php` (new)
- `resources/views/components/layouts/admin.blade.php` (sidebar entry)
- `routes/web.php` (`attendance.lookup`, four `admin.members.*` routes)

## Testing

Feature tests, new `tests/Feature/AttendanceMemberCheckInTest.php`:

- Step 1 with an unknown email renders step 2 blank (no member found).
- Step 1 with a known email renders step 2 pre-filled from that member.
- Submitting step 2 as a new email creates a `Member` and links `member_id`
  on the created `Attendance`.
- Submitting step 2 with an existing member's email and *changed* field
  values updates the `Member` row (write-through) while the newly created
  `Attendance` row still stores its own snapshot.
- Submitting a second check-in for the same member on the same active
  session is rejected (`attendanceAlreadyCheckedIn`) and does not create a
  second `Attendance` row.
- A failed step-2 submission (e.g. missing `phone`) redirects back to a
  still-prefilled step 2, not back to the step-1 email form.
- `email` is now required — omitting it at step 2 fails validation.

New `tests/Feature/Admin/MemberManagementTest.php`:

- Unauthenticated requests to all four `members.*` routes redirect to login.
- Search filters the index by name/email/club.
- `show` displays the member's attendance history.
- `update` persists changes and rejects an email that collides with another
  member's.

New test for the backfill migration (e.g.
`tests/Feature/BackfillMembersFromAttendancesTest.php` or inline in a
migration-specific test): seed `Attendance` rows sharing an email with
different `classification` values across sessions, run the migration, and
assert exactly one `Member` was created from the most recent row and all
matching attendances got that `member_id`.

## Out of scope

- Manual `Member` creation or deletion in the admin panel — members only
  ever originate from a check-in.
- Any de-duplication UI for near-duplicate emails (typos, `+alias@` tricks,
  etc.) — matching is exact after trim/lowercase only.
- Live/AJAX email lookup — the two-step flow is plain server-rendered Blade,
  consistent with the rest of the app.
- Changing how `admin/sessions/show.blade.php` or the PDF export
  (`admin/sessions/pdf.blade.php`) render attendance rows — they keep
  reading the existing snapshot columns unchanged.
