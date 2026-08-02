# Club members ("Membres du club") — spec

Date: 2026-08-02

## Context

`App\Models\Member` (`app/Models/Member.php`) is the deduplicated, per-email
"current info" record for anyone who has ever checked in — it's created and
updated purely as a side effect of `AttendanceFormController::store()`
(`Member::updateOrCreate` keyed on normalized email). There's no persistent
"club roster" concept: `Member` rows are just "people we've seen before."
Admins can browse/search/edit members (`App\Http\Controllers\Admin\MemberController`,
`admin.members.index|show|edit|update`) but cannot create one directly.

The public check-in flow is two steps (`app/Http/Controllers/AttendanceFormController.php`):
step 1 (`GET /`, `POST /check-in` → `lookup()`) takes an email and looks up a
matching `Member`; step 2 (`POST /attendances` → `store()`) always shows/
requires the full details form (title/position/name/club/phone/classification),
pre-filled from the `Member` if one was found, before creating the
`Attendance` row.

The per-session attendance list (`admin.sessions.show`,
`Admin\MeetingSessionController::show`) and its PDF export (`exportPdf`) both
read `$meetingSession->attendances()->with(['title', 'position'])->get()` —
every attendee who ever checked in for that session, with no member-type
distinction. The on-screen list is a plain Blade page with an Alpine.js
component (`attendanceDashboard`, defined in `resources/js/app.js`) fed a
JSON blob of records for client-side search/group/misc filtering; the top
"Présents X/Y" and per-group count tiles are static Blade values computed
once from the full `$attendances` collection (they already ignore every
other Alpine filter today).

## Goal

Let admins designate certain members as permanent "club members"
(`is_club_member` flag) — either one at a time from the admin panel, or in
bulk via an Excel template import. A club member who checks in only needs to
type their email: their saved info is reused as-is and they immediately see
the existing "present enregistrée" success screen, skipping the details
form entirely. Club members are hidden by default from a session's
attendance list and its PDF export (an on-screen toggle can reveal them on
screen; the PDF stays filtered), while the dashboard's global stats are
unaffected. Toggling the flag on an existing member (created via check-in or
otherwise) must "just work" with no backfill needed, since every read is
driven by the current value of `Member.is_club_member`.

## Design

### 1. Data model

New migration, tenant DB:

```php
Schema::table('members', function (Blueprint $table) {
    $table->boolean('is_club_member')->default(false)->after('email');
});
```

`Member`:

```php
protected $fillable = ['title_id', 'position_id', 'name', 'club', 'phone', 'classification', 'email', 'is_club_member'];

protected function casts(): array
{
    return ['is_club_member' => 'boolean'];
}
```

### 2. Admin member creation

New routes alongside the existing four, still inside the `auth:web,super_admin`
group:

```php
Route::get('members/create', [MemberController::class, 'create'])->name('members.create');
Route::post('members', [MemberController::class, 'store'])->name('members.store');
```

New `StoreMemberRequest`, mirroring `UpdateMemberRequest`'s rules (including
its `positionBelongsToTitle` implicit rule) plus the new flag, with a plain
(non-`ignore`d) unique check on `email` — creating a member with an email
that already exists is a validation error, not an upsert:

```php
public function rules(): array
{
    return [
        'title_id' => ['required', 'integer', 'exists:titles,id'],
        'position_id' => ['nullable', 'integer', 'exists:positions,id', $this->positionBelongsToTitle()],
        'name' => ['required', 'string', 'max:255'],
        'club' => ['required', 'string', 'max:255'],
        'phone' => ['required', 'string', 'max:50'],
        'classification' => ['nullable', 'string', 'max:255'],
        'email' => ['required', 'email', 'max:255', Rule::unique('members', 'email')],
        'is_club_member' => ['sometimes', 'boolean'],
    ];
}
```

`MemberController`:

```php
public function create(): View
{
    return view('admin.members.create', [
        'titles' => Title::activeOrId(null)->with(['positions' => fn ($q) => $q->activeOrId(null)])->orderBy('name')->get(),
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

public function update(UpdateMemberRequest $request, Member $member): RedirectResponse
{
    $member->update([
        ...$request->safe()->except('is_club_member'),
        'is_club_member' => $request->boolean('is_club_member'),
    ]);

    return redirect()->route('admin.members.show', $member);
}
```

`admin/members/create.blade.php` mirrors `edit.blade.php` (same
title/position Alpine cascade, same fields), plus a "Membre du club"
checkbox. That same checkbox is added to `edit.blade.php` and
`UpdateMemberRequest` gets the same `is_club_member` rule — **this is the
mechanism for "an existing email becomes a club member"**: an admin opens
that member's existing edit page (regardless of whether the member was
created via check-in, manual creation, or import) and checks the box.

`admin/members/index.blade.php` gets a "Membre du club" badge per row and an
"Ajouter un membre" link to `admin.members.create`.

### 3. Public check-in short-circuit for club members

`lookup()`'s return type widens to `View|RedirectResponse`. When the looked-up
`Member` is a club member, it skips straight to creating the `Attendance`
and redirecting to the existing success screen — reusing the
`attendanceSubmitted` flash branch already in `attendance/show.blade.php`
("Merci, votre présence a bien été enregistrée."), so **no new UI is
needed** for the success message:

```php
public function lookup(LookupAttendanceEmailRequest $request): View|RedirectResponse
{
    $meetingSession = MeetingSession::active();

    abort_if($meetingSession === null, 404);

    $email = Member::normalizeEmail($request->validated('email'));
    $member = Member::firstWhere('email', $email);

    if ($member?->is_club_member) {
        return $this->checkInClubMember($member, $meetingSession);
    }

    return view('attendance.show', [
        'meetingSession' => $meetingSession,
        'email' => $email,
        'member' => $member,
        ...$this->attendanceFormData($member),
    ]);
}

private function checkInClubMember(Member $member, MeetingSession $meetingSession): RedirectResponse
{
    if ($this->alreadyCheckedIn($member, $meetingSession)) {
        return redirect()->route('attendance.show')->with('attendanceAlreadyCheckedIn', true);
    }

    Attendance::create([
        'meeting_session_id' => $meetingSession->id,
        'member_id' => $member->id,
        'title_id' => $member->title_id,
        'position_id' => $member->position_id,
        'name' => $member->name,
        'club' => $member->club,
        'phone' => $member->phone,
        'classification' => $member->classification,
        'email' => $member->email,
        'present' => true,
        'is_late' => ! $meetingSession->is_open,
    ]);

    return redirect()
        ->route('attendance.show')
        ->with('attendanceSubmitted', true)
        ->with('attendanceWasLate', ! $meetingSession->is_open);
}

private function alreadyCheckedIn(Member $member, MeetingSession $meetingSession): bool
{
    return Attendance::where('member_id', $member->id)
        ->where('meeting_session_id', $meetingSession->id)
        ->exists();
}
```

`store()`'s existing duplicate-check block is replaced with a call to the
same `alreadyCheckedIn()` helper, removing the duplicated query.

Because a club member's `Attendance` always copies `title_id` straight from
their `Member` row, and `title_id` is `required` on both `StoreMemberRequest`
and `UpdateMemberRequest`, every club member always has a title by the time
they can check in — avoiding a null-title crash in `Attendance::groupLabel`
(`$this->title->is_principal`) and the PDF (`$attendance->title->name`),
both of which assume a title is always present, matching today's invariant.

### 4. Session attendance list & PDF filtering

`MeetingSessionController::show()` and `exportPdf()` both eager-load `member`
alongside `title`/`position`. `show()` keeps passing the **full** attendance
set to the Alpine payload (club members included, flagged) so the on-screen
toggle can reveal them without a round trip; `exportPdf()` filters club
members out server-side, since the PDF has no interactivity:

```php
// show()
'attendances' => $meetingSession->attendances()->with(['title', 'position', 'member'])->get(),

// exportPdf()
'attendances' => $meetingSession->attendances()->with(['title', 'position', 'member'])
    ->get()
    ->reject(fn (Attendance $attendance) => $attendance->member?->is_club_member === true)
    ->values(),
```

`admin/sessions/show.blade.php`'s `@js(...)` payload gains `'isClubMember' =>
$attendance->member?->is_club_member ?? false` per record, and a new filter
control next to the existing search/group/misc pills:

```blade
<label class="flex cursor-pointer items-center gap-2 text-xs font-semibold text-navy">
    <input type="checkbox" x-model="showClubMembers">
    Afficher les membres du club
</label>
```

`resources/js/app.js`'s `attendanceDashboard` gets `showClubMembers: false`
state, and both the row-list `filtered` getter and the top summary tiles
respect it:

```js
Alpine.data('attendanceDashboard', (records, groupOrder) => ({
    records,
    groupOrder,
    search: '',
    activeGroup: 'all',
    activeTitle: 'all',
    activeMiscFilter: 'all',
    showClubMembers: false,
    sortMode: 'grouped',
    get visibleRecords() {
        return this.records.filter((record) => this.showClubMembers || !record.isClubMember);
    },
    get filtered() {
        const search = this.search.toLowerCase();

        return this.visibleRecords.filter((record) => {
            const matchesGroup = this.activeGroup === 'all' || record.groupLabel === this.activeGroup;
            const matchesTitle = this.activeTitle === 'all' || record.title === this.activeTitle;
            const matchesSearch = record.name.toLowerCase().includes(search);
            const matchesMisc = this.activeMiscFilter === 'all' ||
                (this.activeMiscFilter === 'yes' && record.hasMisc) ||
                (this.activeMiscFilter === 'no' && !record.hasMisc);

            return matchesGroup && matchesTitle && matchesSearch && matchesMisc;
        });
    },
    get presentCount() { return this.visibleRecords.filter((r) => r.present).length; },
    get totalCount() { return this.visibleRecords.length; },
    groupCount(label) { return this.visibleRecords.filter((r) => r.groupLabel === label).length; },
    get groups() {
        return this.groupOrder
            .map((label) => ({
                category: label,
                records: this.sortByPosition(this.filtered.filter((record) => record.groupLabel === label)),
            }))
            .filter((group) => group.records.length > 0);
    },
    get flatSorted() {
        return this.sortByPosition(this.filtered);
    },
    // sortByPosition/initials unchanged
}));
```

The top summary tiles in `show.blade.php` switch from static Blade values
(`{{ $attendances->where(...)->count() }}`) to Alpine bindings
(`x-text="presentCount + '/' + totalCount"`, `x-text="groupCount('...')"`),
so they react to `showClubMembers` exactly like the row list — but, matching
today's behavior, stay unaffected by search/title/misc, since those never
touched the tiles either.

Dashboard stats (`Admin\DashboardController`) are unchanged — they keep
including club members, per the earlier decision.

### 5. Email column in the PDF export

`admin/sessions/pdf.blade.php`'s table gets an `Email` column between
`Téléphone` and `Présent`:

```blade
<th>Téléphone</th>
<th>Email</th>
<th>Présent</th>
...
<td>{{ $attendance->phone }}</td>
<td>{{ $attendance->email }}</td>
<td>{{ $attendance->present ? 'Oui' : 'Non' }}{{ $attendance->is_late ? ' (retard)' : '' }}</td>
```

### 6. Excel import of club members

New dependency: `maatwebsite/excel` (approved), added to `composer.json`.

**Template download** — "Télécharger le gabarit" button on
`admin.members.index` → `GET admin/members/import-template`
(`admin.members.import-template`) → `MemberController::importTemplate()`
streams a generated `.xlsx` via a new `App\Exports\MembersImportTemplateExport`
(`FromArray` + `WithHeadings` + `WithEvents`). Headings: `email`, `name`,
`phone`, `club`, `classification`, `titre`, `poste`. The `titre` and `poste`
columns get real Excel data-validation dropdowns (`AfterSheet` event, one
`DataValidation::TYPE_LIST` per cell down to row 500), built from
`Title::where('is_active', true)->pluck('name')` and
`Position::where('is_active', true)->pluck('name')` respectively.
**Simplification**: the `poste` dropdown lists every active position, not
just those belonging to the selected `titre` — cascading (dependent)
dropdowns in Excel need per-title named ranges and `INDIRECT()` formulas,
which is disproportionate complexity for this feature; `Position` is
already many-to-many with `Title` in this schema (`position_title` pivot),
so "the poste valid for this titre" isn't even a strict 1:1 notion to begin
with.

**Import** — "Importer des membres du club" file input + submit button on
the same page → `POST admin/members/import` (`admin.members.import`),
validated by new `ImportMembersRequest` (`'file' => ['required', 'file',
'mimes:xlsx']`), handled by `MemberController::import()`:

```php
public function import(ImportMembersRequest $request): RedirectResponse
{
    $import = new MembersImport;

    Excel::import($import, $request->file('file'));

    return redirect()
        ->route('admin.members.index')
        ->with('membersImported', $import->imported)
        ->with('membersImportErrors', $import->errors);
}
```

New `App\Imports\MembersImport` (`ToCollection`, `WithHeadingRow`). Per row:

- A fully blank row is skipped silently.
- `email`, `name`, `club`, `phone`, `titre` are **required** — same
  requiredness as manual admin creation (§2), so every imported member ends
  up with a `title_id`, preserving the no-null-title invariant from §3. A
  missing/invalid `email`, or a `titre` that doesn't match any `Title.name`,
  is a row-level error; row is skipped.
- `classification` and `poste` are optional. If `poste` is filled but
  doesn't match any `Position.name`, that's a row-level error (row
  skipped); if left blank, it's simply not set.
- Valid rows call `Member::updateOrCreate(['email' => normalized], [...])`
  — **but only required fields and any non-blank optional field are
  written**; a blank `classification`/`poste` cell on a row that matches an
  *existing* member leaves that member's current value untouched rather
  than blanking it out. `is_club_member` is always set to `true`,
  regardless of the member's prior state — satisfying "an existing email
  becomes a club member" for the bulk path too.

```php
class MembersImport implements ToCollection, WithHeadingRow
{
    /** @var array<int, array{row: int, message: string}> */
    public array $errors = [];

    public int $imported = 0;

    public function collection(BaseCollection $rows): void
    {
        $titlesByName = Title::pluck('id', 'name');
        $positionsByName = Position::pluck('id', 'name');

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // heading row + 1-based index

            if (collect($row)->filter(fn ($v) => trim((string) $v) !== '')->isEmpty()) {
                continue;
            }

            $email = trim((string) ($row['email'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            $club = trim((string) ($row['club'] ?? ''));
            $phone = trim((string) ($row['phone'] ?? ''));
            $titre = trim((string) ($row['titre'] ?? ''));
            $poste = trim((string) ($row['poste'] ?? ''));
            $classification = trim((string) ($row['classification'] ?? ''));

            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->errors[] = ['row' => $rowNumber, 'message' => 'Email invalide ou manquant.'];
                continue;
            }

            if ($name === '' || $club === '' || $phone === '') {
                $this->errors[] = ['row' => $rowNumber, 'message' => 'Nom, club et téléphone sont obligatoires.'];
                continue;
            }

            if (! $titlesByName->has($titre)) {
                $this->errors[] = ['row' => $rowNumber, 'message' => "Organisation inconnue : \"{$titre}\"."];
                continue;
            }

            if ($poste !== '' && ! $positionsByName->has($poste)) {
                $this->errors[] = ['row' => $rowNumber, 'message' => "Titre/qualité inconnu : \"{$poste}\"."];
                continue;
            }

            Member::updateOrCreate(
                ['email' => Member::normalizeEmail($email)],
                array_filter([
                    'name' => $name,
                    'club' => $club,
                    'phone' => $phone,
                    'title_id' => $titlesByName[$titre],
                    'classification' => $classification !== '' ? $classification : null,
                    'position_id' => $poste !== '' ? $positionsByName[$poste] : null,
                    'is_club_member' => true,
                ], fn ($v, $k) => ! in_array($k, ['classification', 'position_id']) || $v !== null),
            );

            $this->imported++;
        }
    }
}
```

`admin/members/index.blade.php` shows a post-import summary from the
flashed session data ("X membres importés", plus a list of row errors when
`membersImportErrors` is non-empty).

## Files touched

- `database/migrations/2026_08_02_120000_add_is_club_member_to_members_table.php` (new)
- `app/Models/Member.php` (`is_club_member` fillable + cast)
- `app/Http/Requests/StoreMemberRequest.php` (new)
- `app/Http/Requests/UpdateMemberRequest.php` (`is_club_member` rule)
- `app/Http/Requests/ImportMembersRequest.php` (new)
- `app/Http/Controllers/Admin/MemberController.php` (`create`, `store`,
  `importTemplate`, `import`; `update` writes `is_club_member`)
- `app/Exports/MembersImportTemplateExport.php` (new)
- `app/Imports/MembersImport.php` (new)
- `resources/views/admin/members/create.blade.php` (new)
- `resources/views/admin/members/edit.blade.php` ("Membre du club" checkbox)
- `resources/views/admin/members/index.blade.php` (badge, create link,
  template/import buttons, import summary)
- `routes/web.php` (`admin.members.create/store/import-template/import`)
- `app/Http/Controllers/AttendanceFormController.php` (club-member
  short-circuit in `lookup()`, `checkInClubMember()`, shared
  `alreadyCheckedIn()` helper used by `store()` too)
- `app/Http/Controllers/Admin/MeetingSessionController.php` (eager-load
  `member`; `exportPdf()` excludes club members)
- `resources/views/admin/sessions/show.blade.php` (`isClubMember` in JS
  payload, "Afficher les membres du club" toggle, Alpine-bound summary
  tiles)
- `resources/js/app.js` (`attendanceDashboard`: `showClubMembers`,
  `visibleRecords`, `presentCount`/`totalCount`/`groupCount()`)
- `resources/views/admin/sessions/pdf.blade.php` (Email column)
- `composer.json` (`maatwebsite/excel` dependency)

## Testing

New `tests/Feature/Admin/ClubMemberManagementTest.php`:

- Admin can create a member with `is_club_member` checked; it persists.
- Creating a member with an email that already exists fails validation
  (no upsert).
- Editing an existing member (originally created via check-in) to check
  "Membre du club" persists `is_club_member = true`.

Extend `tests/Feature/AttendanceMemberCheckInTest.php` (or a new
`ClubMemberCheckInTest.php`):

- A club member submitting step 1 (email only) is redirected straight to
  the `attendanceSubmitted` success state — no step-2 form is rendered —
  and an `Attendance` row is created copying the member's stored
  title/position/name/club/phone/classification.
- A club member checking in twice for the same session is rejected via
  `attendanceAlreadyCheckedIn` on the second attempt, same as a regular
  member.
- A non-club member (or unknown email) still gets the step-2 form
  (unchanged behavior).

Extend `tests/Feature/Admin/MeetingSessionTest.php` (or equivalent):

- `exportPdf()` excludes attendances belonging to club members from the
  rendered attendances (assert via the controller/view data, not by
  parsing the PDF binary).
- The PDF table includes the attendee's email.

New Pest browser test (per this project's `pest-testing` conventions) for
the on-screen toggle, since it's pure Alpine-side reactivity that a
non-browser feature test can't exercise: visit a session's `admin.sessions.show`
page seeded with one regular and one club-member attendance, assert the
club member's row is hidden and the summary tile count excludes them,
check "Afficher les membres du club", assert the row appears and the tile
count updates.

New `tests/Feature/Admin/MembersImportTest.php`:

- Downloading the template returns an `.xlsx` with the expected headings.
- Importing a file with valid rows creates new members with
  `is_club_member = true` and the resolved `title_id`/`position_id`.
- A row with a missing/invalid email, missing name/club/phone, or an
  unknown `titre`/`poste` is skipped and reported in `membersImportErrors`,
  without aborting the rest of the batch.
- Importing a row whose email matches an existing member updates that
  member's fields from the row and flips `is_club_member` to `true`;
  leaving `classification`/`poste` blank on that row preserves the
  member's existing values for those two fields.
- A fully blank row is silently ignored (not counted as an error).

## Out of scope

- Cascading/dependent Excel dropdowns that filter `poste` options by the
  row's selected `titre` — the import template's `poste` dropdown lists all
  active positions regardless of `titre`; a mismatched pairing is not
  flagged at import time.
- Any UI for de-duplicating near-identical emails (typos, `+alias@`
  tricks) — matching stays exact after trim/lowercase, as it already does
  everywhere else in the app.
- Deleting members or bulk-unflagging club members — both remain
  one-at-a-time via the existing edit screen.
- Changing `Admin\DashboardController`'s stats to exclude club members —
  confirmed out of scope; they keep including everyone.
- A dedicated "import" page — the template download and upload live
  directly on `admin.members.index`, per the chosen option.
