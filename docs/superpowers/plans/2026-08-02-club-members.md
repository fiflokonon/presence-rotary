# Club Members Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let admins flag `Member` records as permanent "club members" (individually or via Excel bulk import), have club members check in with just their email (no details form, straight to the existing success screen), and hide club members by default from a session's attendance list and PDF export.

**Architecture:** Everything hangs off one new boolean column, `members.is_club_member`. Every downstream behavior (check-in short-circuit, list/PDF filtering, "existing email becomes a club member") reads that single column live — no caching, no denormalization, no backfill migration needed when the flag changes later.

**Tech Stack:** Laravel 13, Pest 4, Blade + Alpine.js (no Livewire in this app), DomPDF (`barryvdh/laravel-dompdf`), new dependency `maatwebsite/excel` (^3.1, confirmed compatible with `illuminate/support ^13.0`).

## Global Constraints

- PHP 8.4 — curly braces always, constructor property promotion, explicit return types and param type hints on every method (per `CLAUDE.md`).
- Follow existing code conventions in each file touched — check sibling files before writing new code (already done during planning; each task below cites the file it mirrors).
- Run `vendor/bin/pint --dirty --format agent` after every task that touches a `.php` file, before committing.
- Tests: Pest, `php artisan test --compact --filter=<Name>` to run a single file/test while iterating, full `php artisan test --compact` before the final commit of each task.
- Do not delete or rewrite existing tests except where a task explicitly says a specific existing assertion must change (Task 6 only — the `AttendanceDashboardTest` tile-count assertion still passes unmodified, since the tiles stay server-rendered).
- No new abstractions beyond what's specified — e.g. no service classes, no repository layer; controllers/requests/models follow the same shape as their siblings.
- Spec: `docs/superpowers/specs/2026-08-02-club-members-design.md` — consult it for full rationale; this plan is the authoritative task breakdown, but if anything here seems to contradict the spec, the spec's "Out of scope" section still governs what NOT to build.

---

### Task 1: `is_club_member` column + `Member` model

**Files:**
- Create: `database/migrations/2026_08_02_120000_add_is_club_member_to_members_table.php`
- Modify: `app/Models/Member.php`
- Test: `tests/Feature/Models/MemberModelTest.php`

**Interfaces:**
- Produces: `Member::$fillable` includes `'is_club_member'`; `Member` casts `is_club_member` to `bool`; every `Member` row defaults to `is_club_member = false`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Models/MemberModelTest.php`:

```php
it('defaults is_club_member to false', function () {
    $member = Member::factory()->create();

    expect($member->is_club_member)->toBeFalse();
});

it('casts is_club_member to a boolean and persists true', function () {
    $member = Member::factory()->create(['is_club_member' => true]);

    expect($member->fresh()->is_club_member)->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=MemberModelTest`
Expected: FAIL — `is_club_member` column doesn't exist yet (SQL error) or the attribute isn't set.

- [ ] **Step 3: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->boolean('is_club_member')->default(false)->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('is_club_member');
        });
    }
};
```

- [ ] **Step 4: Update the `Member` model**

In `app/Models/Member.php`, change:

```php
protected $fillable = ['title_id', 'position_id', 'name', 'club', 'phone', 'classification', 'email'];
```

to:

```php
protected $fillable = ['title_id', 'position_id', 'name', 'club', 'phone', 'classification', 'email', 'is_club_member'];

protected function casts(): array
{
    return ['is_club_member' => 'boolean'];
}
```

Add the `casts()` method after `$fillable`, before `title()`.

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact --filter=MemberModelTest`
Expected: PASS (all existing `MemberModelTest` assertions plus the two new ones).

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_08_02_120000_add_is_club_member_to_members_table.php app/Models/Member.php tests/Feature/Models/MemberModelTest.php
git commit -m "feat: add is_club_member flag to Member"
```

---

### Task 2: Admin member creation (`create`/`store`)

**Files:**
- Create: `app/Http/Requests/StoreMemberRequest.php`
- Create: `resources/views/admin/members/create.blade.php`
- Modify: `app/Http/Controllers/Admin/MemberController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Admin/MemberManagementTest.php`

**Interfaces:**
- Consumes: `Member::$fillable`/`casts()` from Task 1.
- Produces: routes `admin.members.create` (GET), `admin.members.store` (POST); `MemberController::create(): View`, `MemberController::store(StoreMemberRequest): RedirectResponse`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/Admin/MemberManagementTest.php`:

```php
it('redirects guests away from the create and store member routes', function () {
    $this->get(route('admin.members.create'))->assertRedirect(route('admin.login'));
    $this->post(route('admin.members.store'), [])->assertRedirect(route('admin.login'));
});

it('creates a member from the admin panel', function () {
    $response = $this->actingAs(User::factory()->create())
        ->post(route('admin.members.store'), [
            'title_id' => Title::where('name', 'Rotary')->sole()->id,
            'position_id' => Title::where('name', 'Rotary')->sole()->positions()->where('name', 'Membre')->sole()->id,
            'name' => 'Awa Bello',
            'club' => 'RC Cotonou Ife',
            'phone' => '+229 90 11 22 33',
            'email' => 'awa.bello@example.com',
            'is_club_member' => '1',
        ]);

    $member = Member::where('email', 'awa.bello@example.com')->sole();

    $response->assertRedirect(route('admin.members.show', $member));
    expect($member->name)->toBe('Awa Bello')
        ->and($member->is_club_member)->toBeTrue();
});

it('creating a member without checking the club member box leaves it false', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('admin.members.store'), [
            'title_id' => Title::where('name', 'Rotary')->sole()->id,
            'position_id' => Title::where('name', 'Rotary')->sole()->positions()->where('name', 'Membre')->sole()->id,
            'name' => 'Jean Dupont',
            'club' => 'RC Cotonou Ife',
            'phone' => '+229 90 00 00 00',
            'email' => 'jean.new@example.com',
        ]);

    expect(Member::where('email', 'jean.new@example.com')->sole()->is_club_member)->toBeFalse();
});

it('rejects creating a member with an email that already exists', function () {
    Member::factory()->create(['email' => 'existing@example.com']);

    $this->actingAs(User::factory()->create())
        ->post(route('admin.members.store'), [
            'title_id' => Title::where('name', 'Rotary')->sole()->id,
            'position_id' => Title::where('name', 'Rotary')->sole()->positions()->where('name', 'Membre')->sole()->id,
            'name' => 'Duplicate',
            'club' => 'RC Cotonou Ife',
            'phone' => '+229 90 00 00 00',
            'email' => 'existing@example.com',
        ])->assertSessionHasErrors(['email']);

    expect(Member::where('email', 'existing@example.com')->count())->toBe(1);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=MemberManagementTest`
Expected: FAIL — routes `admin.members.create`/`admin.members.store` don't exist (`RouteNotFoundException`).

- [ ] **Step 3: Create `StoreMemberRequest`**

```php
<?php

namespace App\Http\Requests;

use App\Models\Title;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMemberRequest extends FormRequest
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

    /**
     * Marked implicit so it still runs when `position_id` is missing/empty —
     * a plain Closure rule is never "implicit" in Laravel, so it would
     * otherwise be silently skipped by the validator's nullable/presence
     * checks whenever no position is submitted, and the "position required
     * for this title" case below would never fire.
     */
    private function positionBelongsToTitle(): ValidationRule
    {
        return new class($this) implements ValidationRule
        {
            public bool $implicit = true;

            public function __construct(private readonly FormRequest $request) {}

            public function validate(string $attribute, mixed $value, Closure $fail): void
            {
                $title = Title::find($this->request->input('title_id'));

                if ($title === null) {
                    return;
                }

                if ($value === null || $value === '') {
                    if ($title->positions()->where('is_active', true)->exists()) {
                        $fail('Le titre/qualité est obligatoire pour cette organisation.');
                    }

                    return;
                }

                if (! $title->positions()->whereKey($value)->exists()) {
                    $fail('Le titre/qualité sélectionné ne correspond pas à l\'organisation choisie.');
                }
            }
        };
    }
}
```

This is a direct copy of `UpdateMemberRequest`'s rules (`app/Http/Requests/UpdateMemberRequest.php`), minus the `->ignore($this->route('member'))` on the email uniqueness rule (there's no existing member to ignore — this is a create), plus the new `is_club_member` rule.

- [ ] **Step 4: Add routes**

In `routes/web.php`, right after the existing `Route::get('members', ...)` line (before `members/{member}`, so `/members/create` doesn't get swallowed by the `{member}` route model binding):

```php
Route::get('members', [MemberController::class, 'index'])->name('members.index');
Route::get('members/create', [MemberController::class, 'create'])->name('members.create');
Route::post('members', [MemberController::class, 'store'])->name('members.store');
Route::get('members/{member}', [MemberController::class, 'show'])->name('members.show');
Route::get('members/{member}/edit', [MemberController::class, 'edit'])->name('members.edit');
Route::put('members/{member}', [MemberController::class, 'update'])->name('members.update');
```

- [ ] **Step 5: Add controller actions**

In `app/Http/Controllers/Admin/MemberController.php`, add `use App\Http\Requests\StoreMemberRequest;` to the imports, and add these two methods (right before `edit()`):

```php
public function create(): View
{
    return view('admin.members.create', [
        'titles' => Title::activeOrId(null)
            ->with(['positions' => fn ($query) => $query->activeOrId(null)])
            ->orderBy('name')
            ->get(),
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
```

Also update `update()` to persist the flag the same way:

```php
public function update(UpdateMemberRequest $request, Member $member): RedirectResponse
{
    $member->update([
        ...$request->safe()->except('is_club_member'),
        'is_club_member' => $request->boolean('is_club_member'),
    ]);

    return redirect()->route('admin.members.show', $member);
}
```

(This second change is here because `store()` and `update()` should behave identically for this field — Task 3 adds the actual checkbox to the edit form and the rule to `UpdateMemberRequest`; without this change here, submitting the edit form today would silently ignore `is_club_member` even after Task 3 adds the field, since `$request->safe()` on `UpdateMemberRequest` won't yet include it until Task 3. Making this change now is safe: `$request->boolean('is_club_member')` returns `false` for any request that doesn't send the field, which is exactly today's behavior.)

- [ ] **Step 6: Create the view**

`resources/views/admin/members/create.blade.php` — copy of `resources/views/admin/members/edit.blade.php` with these changes: title "Ajouter un membre", form posts to `admin.members.store` (no `@method('PUT')`), `$member` references replaced with empty defaults (`old('field')` with no second argument), and a new checkbox before the submit button:

```blade
<x-layouts.admin title="Ajouter un membre — Administration">
    <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)] md:p-8">
        <h1 class="font-display text-xl font-extrabold text-navy">Ajouter un membre</h1>

        <form method="POST" action="{{ route('admin.members.store') }}" class="mt-4 flex max-w-md flex-col gap-3">
            @csrf

            <div x-data="{
                    titleId: '{{ old('title_id') }}',
                    positionId: '{{ old('position_id') }}',
                    positionsByTitle: {{ Illuminate\Support\Js::from($titles->mapWithKeys(fn ($t) => [
                        $t->id => $t->positions->map(fn ($p) => [
                            'id' => $p->id,
                            'name' => $p->is_active ? $p->name : $p->name.' (inactif)',
                        ])->values(),
                    ])) }},
                    get availablePositions() { return this.positionsByTitle[this.titleId] ?? [] },
                }"
                class="contents"
            >
                <div class="flex flex-col gap-1.5">
                    <label for="title_id" class="text-sm font-semibold">Organisation</label>
                    <select x-model="titleId" id="title_id" name="title_id" required
                        class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
                        <option value="">Sélectionnez…</option>
                        @foreach ($titles as $titleOption)
                            <option value="{{ $titleOption->id }}">{{ $titleOption->is_active ? $titleOption->name : $titleOption->name.' (inactif)' }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="flex flex-col gap-1.5" x-show="availablePositions.length > 0">
                    <label for="position_id" class="text-sm font-semibold">Titre/Qualité</label>
                    <select x-model="positionId" id="position_id" name="position_id" :required="availablePositions.length > 0"
                        class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
                        <option value="">Sélectionnez…</option>
                        <template x-for="position in availablePositions" :key="position.id">
                            <option :value="position.id" x-text="position.name"></option>
                        </template>
                    </select>
                </div>
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="name" class="text-sm font-semibold">Nom</label>
                <input type="text" id="name" name="name" value="{{ old('name') }}" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="club" class="text-sm font-semibold">Club</label>
                <input type="text" id="club" name="club" value="{{ old('club') }}" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="phone" class="text-sm font-semibold">Téléphone</label>
                <input type="tel" id="phone" name="phone" value="{{ old('phone') }}" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="classification" class="text-sm font-semibold">Classification</label>
                <input type="text" id="classification" name="classification" value="{{ old('classification') }}"
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="email" class="text-sm font-semibold">Email</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>

            <label class="flex items-center gap-2 text-sm font-semibold">
                <input type="checkbox" id="is_club_member" name="is_club_member" value="1" {{ old('is_club_member') ? 'checked' : '' }}>
                Membre du club
            </label>

            <button type="submit"
                class="mt-2 cursor-pointer self-start rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                Enregistrer
            </button>
        </form>

        @if ($errors->any())
            <div class="mt-4 rounded-lg bg-error-bg px-4 py-3 text-sm text-error">
                {{ $errors->first() }}
            </div>
        @endif
    </div>
</x-layouts.admin>
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --compact --filter=MemberManagementTest`
Expected: PASS.

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Requests/StoreMemberRequest.php app/Http/Controllers/Admin/MemberController.php resources/views/admin/members/create.blade.php routes/web.php tests/Feature/Admin/MemberManagementTest.php
git commit -m "feat: let admins create members directly"
```

---

### Task 3: "Membre du club" on the edit form + member list badge

**Files:**
- Modify: `app/Http/Requests/UpdateMemberRequest.php`
- Modify: `resources/views/admin/members/edit.blade.php`
- Modify: `resources/views/admin/members/index.blade.php`
- Test: `tests/Feature/Admin/MemberManagementTest.php`

**Interfaces:**
- Consumes: `MemberController::update()` from Task 2 (already writes `is_club_member` via `$request->boolean(...)`).
- Produces: nothing new consumed by later tasks — this is a leaf UI task.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/Admin/MemberManagementTest.php`:

```php
it('flags an existing member as a club member from the edit form', function () {
    $member = Member::factory()->create(['is_club_member' => false]);

    $this->actingAs(User::factory()->create())
        ->put(route('admin.members.update', $member), [
            'title_id' => Title::where('name', 'Rotary')->sole()->id,
            'position_id' => Title::where('name', 'Rotary')->sole()->positions()->where('name', 'Membre')->sole()->id,
            'name' => $member->name,
            'club' => $member->club,
            'phone' => $member->phone,
            'classification' => $member->classification,
            'email' => $member->email,
            'is_club_member' => '1',
        ]);

    expect($member->fresh()->is_club_member)->toBeTrue();
});

it('unflags a club member from the edit form by omitting the checkbox', function () {
    $member = Member::factory()->create(['is_club_member' => true]);

    $this->actingAs(User::factory()->create())
        ->put(route('admin.members.update', $member), [
            'title_id' => Title::where('name', 'Rotary')->sole()->id,
            'position_id' => Title::where('name', 'Rotary')->sole()->positions()->where('name', 'Membre')->sole()->id,
            'name' => $member->name,
            'club' => $member->club,
            'phone' => $member->phone,
            'classification' => $member->classification,
            'email' => $member->email,
        ]);

    expect($member->fresh()->is_club_member)->toBeFalse();
});

it('shows a club member badge on the member index', function () {
    Member::factory()->create(['name' => 'Awa Bello', 'is_club_member' => true]);
    Member::factory()->create(['name' => 'Jean Dupont', 'is_club_member' => false]);

    $response = $this->actingAs(User::factory()->create())
        ->get(route('admin.members.index'));

    $response->assertOk()->assertSee('Membre du club');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=MemberManagementTest`
Expected: FAIL — `is_club_member` isn't in `UpdateMemberRequest`'s validated data (extra input is silently dropped by `$request->safe()`, so this actually already no-ops rather than erroring; the badge test fails because "Membre du club" text isn't on the page yet).

- [ ] **Step 3: Add the rule to `UpdateMemberRequest`**

In `app/Http/Requests/UpdateMemberRequest.php`, add to the `rules()` array, after `'email'`:

```php
'is_club_member' => ['sometimes', 'boolean'],
```

- [ ] **Step 4: Add the checkbox to the edit view**

In `resources/views/admin/members/edit.blade.php`, right before the closing `<button type="submit">`, add:

```blade
<label class="flex items-center gap-2 text-sm font-semibold">
    <input type="checkbox" id="is_club_member" name="is_club_member" value="1" {{ old('is_club_member', $member->is_club_member) ? 'checked' : '' }}>
    Membre du club
</label>
```

- [ ] **Step 5: Add the badge to the index view**

In `resources/views/admin/members/index.blade.php`, change the name cell to show a badge next to the name when the member is a club member:

```blade
<td class="py-3 pr-4 font-semibold text-navy">
    {{ $member->name }}
    @if ($member->is_club_member)
        <span class="ml-2 rounded-full bg-gold/20 px-2 py-0.5 text-[11px] font-semibold text-gold">Membre du club</span>
    @endif
</td>
```

Also add an "Ajouter un membre" link near the search form (after the closing `</form>` of the search form, before the table):

```blade
<a href="{{ route('admin.members.create') }}"
    class="mt-4 inline-flex items-center gap-2 rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
    <i class="fa-solid fa-plus" aria-hidden="true"></i> Ajouter un membre
</a>
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --compact --filter=MemberManagementTest`
Expected: PASS — full file, including Task 2's tests.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Requests/UpdateMemberRequest.php resources/views/admin/members/edit.blade.php resources/views/admin/members/index.blade.php tests/Feature/Admin/MemberManagementTest.php
git commit -m "feat: let admins flag existing members as club members"
```

---

### Task 4: Club-member check-in short-circuit

**Files:**
- Modify: `app/Http/Controllers/AttendanceFormController.php`
- Test: `tests/Feature/AttendanceMemberCheckInTest.php`

**Interfaces:**
- Consumes: `Member::is_club_member` (Task 1).
- Produces: `AttendanceFormController::lookup()` now returns `View|RedirectResponse`; new private `checkInClubMember(Member, MeetingSession): RedirectResponse` and `alreadyCheckedIn(Member, MeetingSession): bool` methods (the latter also used by `store()`).

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/AttendanceMemberCheckInTest.php`:

```php
it('immediately checks in a club member from step 1 with no details form', function () {
    $meetingSession = MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);
    $rotary = Title::where('name', 'Rotary')->sole();
    $member = Member::factory()->create([
        'email' => 'club.member@example.com',
        'name' => 'Club Membre',
        'club' => 'RC Cotonou Ife',
        'phone' => '+229 90 00 00 01',
        'title_id' => $rotary->id,
        'is_club_member' => true,
    ]);

    $this->post(route('attendance.lookup'), ['email' => 'club.member@example.com'])
        ->assertRedirect(route('attendance.show'))
        ->assertSessionHas('attendanceSubmitted', true);

    $attendance = Attendance::where('member_id', $member->id)->sole();

    expect($attendance->meeting_session_id)->toBe($meetingSession->id)
        ->and($attendance->present)->toBeTrue()
        ->and($attendance->name)->toBe('Club Membre')
        ->and($attendance->club)->toBe('RC Cotonou Ife')
        ->and($attendance->title_id)->toBe($rotary->id);

    $this->get(route('attendance.show'))
        ->assertOk()
        ->assertSee('Merci, votre présence a bien été enregistrée.')
        ->assertDontSee('Nom et prénoms');
});

it('rejects a second check-in from the same club member on the same session', function () {
    $meetingSession = MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);
    $member = Member::factory()->create(['email' => 'club.member@example.com', 'is_club_member' => true]);
    Attendance::factory()->create([
        'meeting_session_id' => $meetingSession->id,
        'member_id' => $member->id,
        'email' => 'club.member@example.com',
    ]);

    $this->post(route('attendance.lookup'), ['email' => 'club.member@example.com'])
        ->assertRedirect(route('attendance.show'))
        ->assertSessionHas('attendanceAlreadyCheckedIn', true);

    expect(Attendance::where('member_id', $member->id)->count())->toBe(1);
});

it('still shows the details form for a non-club-member lookup', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);
    Member::factory()->create(['email' => 'regular@example.com', 'is_club_member' => false]);

    $this->post(route('attendance.lookup'), ['email' => 'regular@example.com'])
        ->assertOk()
        ->assertSee('Nom et prénoms');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=AttendanceMemberCheckInTest`
Expected: FAIL — a club member currently gets the same step-2 form as anyone else, so `assertDontSee('Nom et prénoms')` fails and no `Attendance` is created from `lookup()` alone.

- [ ] **Step 3: Implement the short-circuit**

In `app/Http/Controllers/AttendanceFormController.php`, change the `lookup()` signature and body, and add two private methods. Full replacement of `lookup()` through the end of `store()`:

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

public function store(StoreAttendanceRequest $request): RedirectResponse
{
    $meetingSession = MeetingSession::active();

    abort_if($meetingSession === null, 404);

    $email = Member::normalizeEmail($request->validated('email'));

    $existingMember = Member::firstWhere('email', $email);

    if ($existingMember !== null && $this->alreadyCheckedIn($existingMember, $meetingSession)) {
        return redirect()
            ->route('attendance.show')
            ->with('attendanceAlreadyCheckedIn', true);
    }

    $member = Member::updateOrCreate(
        ['email' => $email],
        $request->safe()->only(['title_id', 'position_id', 'name', 'club', 'phone', 'classification']),
    );

    Attendance::create([
        ...$request->validated(),
        'email' => $email,
        'member_id' => $member->id,
        'meeting_session_id' => $meetingSession->id,
        'present' => true,
        'is_late' => ! $meetingSession->is_open,
    ]);

    return redirect()
        ->route('attendance.show')
        ->with('attendanceSubmitted', true)
        ->with('attendanceWasLate', ! $meetingSession->is_open);
}

private function checkInClubMember(Member $member, MeetingSession $meetingSession): RedirectResponse
{
    if ($this->alreadyCheckedIn($member, $meetingSession)) {
        return redirect()
            ->route('attendance.show')
            ->with('attendanceAlreadyCheckedIn', true);
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

Add `use Illuminate\Http\RedirectResponse;` is already imported; add `use Illuminate\View\View;`'s sibling — the return type `View|RedirectResponse` needs both already-imported classes, no new imports required.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=AttendanceMemberCheckInTest`
Expected: PASS — full file (existing tests plus the three new ones); the existing "rejects a second check-in" and "creates a member" tests for `store()` must still pass since `store()`'s external behavior is unchanged, only its internal duplicate-check call site moved.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/AttendanceFormController.php tests/Feature/AttendanceMemberCheckInTest.php
git commit -m "feat: skip the details form for club members checking in"
```

---

### Task 5: Session attendance list — hide club members by default

**Files:**
- Modify: `app/Http/Controllers/Admin/MeetingSessionController.php`
- Modify: `resources/views/admin/sessions/show.blade.php`
- Modify: `resources/js/app.js`
- Test: `tests/Feature/Admin/AttendanceDashboardTest.php`

**Interfaces:**
- Consumes: `Member::is_club_member` (Task 1).
- Produces: `MeetingSessionController::show()` passes both `attendances` (full) and `visibleAttendances` (club members excluded) to the view — `exportPdf()` (Task 6) copies the same `reject()` expression.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/Admin/AttendanceDashboardTest.php`:

```php
it('excludes club members from the summary tile counts by default', function () {
    $meetingSession = MeetingSession::factory()->create();
    $clubMember = Member::factory()->create(['is_club_member' => true]);

    Attendance::factory()->for($meetingSession)->create([
        'title_id' => Title::where('name', 'Rotary')->sole()->id,
        'present' => true,
    ]);
    Attendance::factory()->for($meetingSession)->create([
        'title_id' => Title::where('name', 'Rotary')->sole()->id,
        'member_id' => $clubMember->id,
        'present' => true,
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.show', $meetingSession))
        ->assertOk()
        ->assertSee('1/1');
});

it('flags club member attendances in the JSON payload and exposes the toggle', function () {
    $meetingSession = MeetingSession::factory()->create();
    $clubMember = Member::factory()->create(['is_club_member' => true]);
    $regular = Member::factory()->create(['is_club_member' => false]);

    Attendance::factory()->for($meetingSession)->create([
        'title_id' => Title::where('name', 'Rotary')->sole()->id,
        'member_id' => $clubMember->id,
    ]);
    Attendance::factory()->for($meetingSession)->create([
        'title_id' => Title::where('name', 'Rotary')->sole()->id,
        'member_id' => $regular->id,
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.show', $meetingSession))
        ->assertOk()
        ->assertSee('"isClubMember":true', false)
        ->assertSee('"isClubMember":false', false)
        ->assertSee('x-model="showClubMembers"', false)
        ->assertSee('Afficher les membres du club');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=AttendanceDashboardTest`
Expected: FAIL — `assertSee('1/1')` currently sees `2/2` (both attendances counted), and `isClubMember`/`showClubMembers` don't exist in the payload/markup yet.

- [ ] **Step 3: Update the controller**

In `app/Http/Controllers/Admin/MeetingSessionController.php`, replace the `show()` method body:

```php
public function show(MeetingSession $meetingSession): View
{
    $attendances = $meetingSession->attendances()->with(['title', 'position', 'member'])->get();

    return view('admin.sessions.show', [
        'meetingSession' => $meetingSession,
        'attendances' => $attendances,
        'visibleAttendances' => $attendances->reject(fn (Attendance $attendance) => $attendance->member?->is_club_member === true),
        'upcomingSessions' => MeetingSession::where('id', '!=', $meetingSession->id)
            ->where('date', '>=', now()->toDateString())
            ->orderBy('date')
            ->get(),
        'groups' => $this->buildGroups($this->principalTitles()),
    ]);
}
```

- [ ] **Step 4: Update the view**

In `resources/views/admin/sessions/show.blade.php`:

1. Add `'isClubMember' => $attendance->member?->is_club_member ?? false,` to the `@js(...)` array (right after `'hasMisc' => $attendance->has_misc,`).
2. Replace both uses of `$attendances` in the summary tiles section (the "Présents X/Y" tile and the per-group `$groupCount` computation) with `$visibleAttendances`:

```blade
<div class="rounded-lg bg-navy p-3 text-white">
    <p class="text-lg font-extrabold">{{ $visibleAttendances->where('present', true)->count() }}/{{ $visibleAttendances->count() }}</p>
    <p class="text-xs">Présents ({{ $visibleAttendances->count() > 0 ? round($visibleAttendances->where('present', true)->count() / $visibleAttendances->count() * 100) : 0 }}%)</p>
</div>
@foreach ($groups as $group)
    @php $groupCount = $visibleAttendances->filter(fn ($attendance) => $attendance->groupLabel === $group['label'])->count(); @endphp
    <div class="rounded-lg p-3" style="background-color: {{ $group['colors']['bg'] }}; color: {{ $group['colors']['accent'] }}">
        <p class="text-lg font-extrabold">{{ $groupCount }}</p>
        <p class="text-xs">{{ $group['label'] }}</p>
    </div>
@endforeach
```

3. Add the toggle next to the existing sort-mode button, in the filter bar (after the last `<span class="h-6 w-px bg-divider md:mx-1"></span>` / sort button block):

```blade
<label class="flex cursor-pointer items-center gap-2 text-xs font-semibold text-navy">
    <input type="checkbox" x-model="showClubMembers">
    Afficher les membres du club
</label>
```

- [ ] **Step 5: Update the Alpine component**

In `resources/js/app.js`, inside `Alpine.data('attendanceDashboard', ...)`, add `showClubMembers: false,` after `activeMiscFilter: 'all',`, add a `visibleRecords` getter, and route `filtered` through it:

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
    get titleOptions() {
        return [...new Set(this.records.map((record) => record.title))].sort();
    },
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
    // ...groups/flatSorted/sortByPosition/initials unchanged
}));
```

(Only `records` → `this.visibleRecords` changes inside `filtered`; every other method in this `Alpine.data` block — `get groups()`, `get flatSorted()`, `sortByPosition()`, `initials()` — is untouched.)

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --compact --filter=AttendanceDashboardTest`
Expected: PASS — including the pre-existing `assertSee('1/2')` test, which is unaffected since it has no club members in its fixture.

- [ ] **Step 7: Rebuild frontend assets**

Run: `npm run build`

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Admin/MeetingSessionController.php resources/views/admin/sessions/show.blade.php resources/js/app.js tests/Feature/Admin/AttendanceDashboardTest.php
git commit -m "feat: hide club members from the session attendance list by default"
```

---

### Task 6: PDF export — exclude club members + add Email column

**Files:**
- Modify: `app/Http/Controllers/Admin/MeetingSessionController.php`
- Modify: `resources/views/admin/sessions/pdf.blade.php`
- Test: `tests/Feature/Admin/AttendancePdfExportTest.php`

**Interfaces:**
- Consumes: `Member::is_club_member` (Task 1), same `reject()` expression pattern as Task 5's `visibleAttendances`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/Admin/AttendancePdfExportTest.php`, adding `use App\Models\Member;`, `use Barryvdh\DomPDF\Facade\Pdf;`, and `use Mockery;` to the file's existing imports:

```php
it('excludes club members from the PDF export by default', function () {
    $meetingSession = MeetingSession::factory()->create();
    $rotary = Title::where('name', 'Rotary')->sole();
    $clubMember = Member::factory()->create(['is_club_member' => true]);
    $regularMember = Member::factory()->create(['is_club_member' => false]);

    Attendance::factory()->for($meetingSession)->create([
        'title_id' => $rotary->id,
        'member_id' => $clubMember->id,
        'name' => 'Club Membre',
    ]);
    Attendance::factory()->for($meetingSession)->create([
        'title_id' => $rotary->id,
        'member_id' => $regularMember->id,
        'name' => 'Personne Reguliere',
    ]);

    Pdf::shouldReceive('loadView')
        ->once()
        ->with('admin.sessions.pdf', Mockery::on(function (array $data) {
            $names = collect($data['attendances'])->pluck('name');

            return $names->contains('Personne Reguliere') && ! $names->contains('Club Membre');
        }))
        ->andReturnSelf();

    Pdf::shouldReceive('download')->once()->andReturn(response('fake-pdf', 200, ['Content-Type' => 'application/pdf']));

    $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.export-pdf', $meetingSession))
        ->assertOk();
});

it('includes the attendee email in the PDF table', function () {
    $meetingSession = MeetingSession::factory()->create();
    Attendance::factory()->for($meetingSession)->create([
        'title_id' => Title::where('name', 'Rotary')->sole()->id,
        'email' => 'jean.dupont@example.com',
    ]);

    $html = view('admin.sessions.pdf', [
        'meetingSession' => $meetingSession,
        'attendances' => $meetingSession->attendances()->with(['title', 'position'])->get(),
        'groupLabels' => ['Rotary', Title::OTHER_ORGANIZATIONS_LABEL],
    ])->render();

    expect($html)->toContain('<td>jean.dupont@example.com</td>');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=AttendancePdfExportTest`
Expected: FAIL — `exportPdf()` doesn't filter by `is_club_member` yet, and the PDF table has no email column.

- [ ] **Step 3: Update the controller**

In `app/Http/Controllers/Admin/MeetingSessionController.php`, replace the `exportPdf()` method's `$pdf = Pdf::loadView(...)` call:

```php
public function exportPdf(MeetingSession $meetingSession): Response
{
    $attendances = $meetingSession->attendances()->with(['title', 'position', 'member'])
        ->get()
        ->reject(fn (Attendance $attendance) => $attendance->member?->is_club_member === true)
        ->values();

    $pdf = Pdf::loadView('admin.sessions.pdf', [
        'meetingSession' => $meetingSession,
        'attendances' => $attendances,
        'groupLabels' => [...$this->principalTitles()->pluck('name')->all(), Title::OTHER_ORGANIZATIONS_LABEL],
    ]);

    $filename = $meetingSession->date->translatedFormat('Y-m-d').' - '.$meetingSession->title.'.pdf';
    $filename = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '', $filename);

    return $pdf->download($filename);
}
```

- [ ] **Step 4: Add the Email column to the PDF view**

In `resources/views/admin/sessions/pdf.blade.php`, add a `<th>Email</th>` between `<th>Téléphone</th>` and `<th>Présent</th>`, and a matching `<td>{{ $attendance->email }}</td>` between the phone and present cells:

```blade
<thead>
    <tr>
        <th>Nom</th>
        <th>Organisation</th>
        <th>Club</th>
        <th>Téléphone</th>
        <th>Email</th>
        <th>Présent</th>
    </tr>
</thead>
<tbody>
    @foreach ($groupAttendances as $attendance)
        <tr>
            <td>{{ $attendance->name }}</td>
            <td>{{ $attendance->title->name }}{{ $attendance->position ? ' — '.$attendance->position->name : '' }}</td>
            <td>{{ $attendance->club }}</td>
            <td>{{ $attendance->phone }}</td>
            <td>{{ $attendance->email }}</td>
            <td>{{ $attendance->present ? 'Oui' : 'Non' }}{{ $attendance->is_late ? ' (retard)' : '' }}</td>
        </tr>
    @endforeach
</tbody>
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact --filter=AttendancePdfExportTest`
Expected: PASS — full file, including the pre-existing "downloads a PDF" and "groups the PDF export" tests (the latter renders the view directly with hand-built data and is unaffected by the controller change).

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Admin/MeetingSessionController.php resources/views/admin/sessions/pdf.blade.php tests/Feature/Admin/AttendancePdfExportTest.php
git commit -m "feat: exclude club members from the PDF export and add an email column"
```

---

### Task 7: Add `maatwebsite/excel` + downloadable import template

**Files:**
- Modify: `composer.json` / `composer.lock` (via `composer require`)
- Create: `app/Exports/MembersImportTemplateExport.php`
- Modify: `app/Http/Controllers/Admin/MemberController.php`
- Modify: `routes/web.php`
- Modify: `resources/views/admin/members/index.blade.php`
- Test: `tests/Feature/Admin/MembersImportTest.php`

**Interfaces:**
- Produces: route `admin.members.import-template` (GET); `MemberController::importTemplate(): BinaryFileResponse`.

- [ ] **Step 1: Install the dependency**

```bash
composer require maatwebsite/excel
```

Confirmed compatible: `maatwebsite/excel` 3.1.69 requires `illuminate/support: ^13.0` among others, so this resolves without extra flags.

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Admin/MembersImportTest.php`:

```php
<?php

use App\Models\Title;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

it('requires authentication to download the import template', function () {
    $this->get(route('admin.members.import-template'))->assertRedirect(route('admin.login'));
});

it('downloads an xlsx template with the expected headings', function () {
    Storage::fake('local');

    $response = $this->actingAs(User::factory()->create())
        ->get(route('admin.members.import-template'));

    $response->assertOk();
    expect($response->headers->get('content-type'))
        ->toContain('spreadsheetml');
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test --compact --filter=MembersImportTest`
Expected: FAIL — route `admin.members.import-template` doesn't exist.

- [ ] **Step 4: Create the export class**

```php
<?php

namespace App\Exports;

use App\Models\Position;
use App\Models\Title;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class MembersImportTemplateExport implements FromArray, WithEvents, WithHeadings
{
    private const MAX_ROWS = 500;

    /**
     * @return array<int, array<int, string>>
     */
    public function array(): array
    {
        return [];
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['email', 'name', 'phone', 'club', 'classification', 'titre', 'poste'];
    }

    /**
     * @return array<class-string, callable>
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();

                $this->applyDropdown($sheet, 'F', Title::where('is_active', true)->orderBy('name')->pluck('name')->all());
                $this->applyDropdown($sheet, 'G', Position::where('is_active', true)->orderBy('name')->pluck('name')->all());
            },
        ];
    }

    /**
     * @param  array<int, string>  $options
     */
    private function applyDropdown(Worksheet $sheet, string $column, array $options): void
    {
        if ($options === []) {
            return;
        }

        $list = '"'.implode(',', $options).'"';

        for ($row = 2; $row <= self::MAX_ROWS; $row++) {
            $validation = $sheet->getCell("{$column}{$row}")->getDataValidation();
            $validation->setType(DataValidation::TYPE_LIST);
            $validation->setErrorStyle(DataValidation::STYLE_STOP);
            $validation->setAllowBlank(true);
            $validation->setShowDropDown(true);
            $validation->setFormula1($list);
        }
    }
}
```

- [ ] **Step 5: Add the route**

In `routes/web.php`, right after the `members.update` line:

```php
Route::get('members/import-template', [MemberController::class, 'importTemplate'])->name('members.import-template');
```

- [ ] **Step 6: Add the controller action**

In `app/Http/Controllers/Admin/MemberController.php`, add `use App\Exports\MembersImportTemplateExport;` and `use Maatwebsite\Excel\Facades\Excel;` to the imports, and add:

```php
public function importTemplate(): BinaryFileResponse
{
    return Excel::download(new MembersImportTemplateExport, 'gabarit-membres-du-club.xlsx');
}
```

Add `use Symfony\Component\HttpFoundation\BinaryFileResponse;` to the imports.

- [ ] **Step 7: Add the button to the index view**

In `resources/views/admin/members/index.blade.php`, next to the "Ajouter un membre" link added in Task 3:

```blade
<a href="{{ route('admin.members.import-template') }}"
    class="mt-4 ml-2 inline-flex items-center gap-2 rounded-lg border border-border px-4 py-2.5 text-sm font-bold text-navy hover:bg-cream">
    <i class="fa-solid fa-file-arrow-down" aria-hidden="true"></i> Télécharger le gabarit
</a>
```

- [ ] **Step 8: Run tests to verify they pass**

Run: `php artisan test --compact --filter=MembersImportTest`
Expected: PASS.

- [ ] **Step 9: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add composer.json composer.lock app/Exports/MembersImportTemplateExport.php app/Http/Controllers/Admin/MemberController.php routes/web.php resources/views/admin/members/index.blade.php tests/Feature/Admin/MembersImportTest.php
git commit -m "feat: add downloadable club members import template"
```

---

### Task 8: Excel import — process rows into club members

**Files:**
- Create: `app/Imports/MembersImport.php`
- Create: `app/Http/Requests/ImportMembersRequest.php`
- Modify: `app/Http/Controllers/Admin/MemberController.php`
- Modify: `routes/web.php`
- Modify: `resources/views/admin/members/index.blade.php`
- Test: `tests/Feature/Admin/MembersImportTest.php`

**Interfaces:**
- Consumes: `MembersImportTemplateExport` column order (Task 7): `email, name, phone, club, classification, titre, poste`.
- Produces: route `admin.members.import` (POST); `MemberController::import(ImportMembersRequest): RedirectResponse`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/Admin/MembersImportTest.php` (add `use App\Models\Member;`, `use App\Models\Position;`, `use Illuminate\Http\UploadedFile;`, `use Maatwebsite\Excel\Facades\Excel;`, `use App\Exports\MembersImportTemplateExport;` — reuse the template export to build valid test files instead of hand-crafting XLSX byte content):

```php
it('requires authentication to import members', function () {
    $this->post(route('admin.members.import'), [])->assertRedirect(route('admin.login'));
});

it('imports valid rows as club members', function () {
    $rotary = Title::where('name', 'Rotary')->sole();
    $president = $rotary->positions()->where('name', 'Président')->sole();

    $rows = [
        ['email', 'name', 'phone', 'club', 'classification', 'titre', 'poste'],
        ['nouvelle.recrue@example.com', 'Nouvelle Recrue', '+229 90 00 00 09', 'RC Cotonou Ife', 'Ingénieur', $rotary->name, $president->name],
    ];

    Excel::store(new class($rows) implements \Maatwebsite\Excel\Concerns\FromArray
    {
        public function __construct(private array $rows) {}

        public function array(): array
        {
            return $this->rows;
        }
    }, 'import-test.xlsx', 'local');

    $file = new UploadedFile(Storage::disk('local')->path('import-test.xlsx'), 'import-test.xlsx', null, null, true);

    $this->actingAs(User::factory()->create())
        ->post(route('admin.members.import'), ['file' => $file])
        ->assertRedirect(route('admin.members.index'))
        ->assertSessionHas('membersImported', 1);

    $member = Member::where('email', 'nouvelle.recrue@example.com')->sole();

    expect($member->name)->toBe('Nouvelle Recrue')
        ->and($member->is_club_member)->toBeTrue()
        ->and($member->title_id)->toBe($rotary->id)
        ->and($member->position_id)->toBe($president->id);
});

it('reports row errors for missing or invalid data without aborting the batch', function () {
    $rotary = Title::where('name', 'Rotary')->sole();

    $rows = [
        ['email', 'name', 'phone', 'club', 'classification', 'titre', 'poste'],
        ['', 'Sans Email', '+229 90 00 00 10', 'RC Cotonou Ife', '', $rotary->name, ''],
        ['organisation.inconnue@example.com', 'Organisation Inconnue', '+229 90 00 00 11', 'RC Cotonou Ife', '', 'Ne Existe Pas', ''],
        ['valide@example.com', 'Personne Valide', '+229 90 00 00 12', 'RC Cotonou Ife', '', $rotary->name, ''],
    ];

    Excel::store(new class($rows) implements \Maatwebsite\Excel\Concerns\FromArray
    {
        public function __construct(private array $rows) {}

        public function array(): array
        {
            return $this->rows;
        }
    }, 'import-errors-test.xlsx', 'local');

    $file = new UploadedFile(Storage::disk('local')->path('import-errors-test.xlsx'), 'import-errors-test.xlsx', null, null, true);

    $this->actingAs(User::factory()->create())
        ->post(route('admin.members.import'), ['file' => $file])
        ->assertRedirect(route('admin.members.index'))
        ->assertSessionHas('membersImported', 1);

    expect(Member::where('email', 'valide@example.com')->exists())->toBeTrue()
        ->and(Member::where('name', 'Sans Email')->exists())->toBeFalse()
        ->and(Member::where('email', 'organisation.inconnue@example.com')->exists())->toBeFalse();

    $errors = session('membersImportErrors');
    expect($errors)->toHaveCount(2);
});

it('updates an existing member on import, preserving blank optional fields', function () {
    $rotary = Title::where('name', 'Rotary')->sole();
    $existing = Member::factory()->create([
        'email' => 'existant@example.com',
        'classification' => 'Classification Existante',
        'is_club_member' => false,
    ]);

    $rows = [
        ['email', 'name', 'phone', 'club', 'classification', 'titre', 'poste'],
        ['existant@example.com', 'Nom Mis A Jour', '+229 90 00 00 13', 'RC Porto-Novo', '', $rotary->name, ''],
    ];

    Excel::store(new class($rows) implements \Maatwebsite\Excel\Concerns\FromArray
    {
        public function __construct(private array $rows) {}

        public function array(): array
        {
            return $this->rows;
        }
    }, 'import-update-test.xlsx', 'local');

    $file = new UploadedFile(Storage::disk('local')->path('import-update-test.xlsx'), 'import-update-test.xlsx', null, null, true);

    $this->actingAs(User::factory()->create())
        ->post(route('admin.members.import'), ['file' => $file]);

    $existing->refresh();

    expect($existing->name)->toBe('Nom Mis A Jour')
        ->and($existing->club)->toBe('RC Porto-Novo')
        ->and($existing->classification)->toBe('Classification Existante')
        ->and($existing->is_club_member)->toBeTrue();
});

it('ignores fully blank rows', function () {
    $rotary = Title::where('name', 'Rotary')->sole();

    $rows = [
        ['email', 'name', 'phone', 'club', 'classification', 'titre', 'poste'],
        ['', '', '', '', '', '', ''],
        ['valide2@example.com', 'Autre Personne', '+229 90 00 00 14', 'RC Cotonou Ife', '', $rotary->name, ''],
    ];

    Excel::store(new class($rows) implements \Maatwebsite\Excel\Concerns\FromArray
    {
        public function __construct(private array $rows) {}

        public function array(): array
        {
            return $this->rows;
        }
    }, 'import-blank-test.xlsx', 'local');

    $file = new UploadedFile(Storage::disk('local')->path('import-blank-test.xlsx'), 'import-blank-test.xlsx', null, null, true);

    $this->actingAs(User::factory()->create())
        ->post(route('admin.members.import'), ['file' => $file])
        ->assertSessionHas('membersImported', 1);

    expect(session('membersImportErrors'))->toBeEmpty();
});
```

Add `beforeEach(fn () => Storage::fake('local'));` at the top of the file (after the `use` statements) so every test in this file writes to a fake local disk instead of the real filesystem.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=MembersImportTest`
Expected: FAIL — route `admin.members.import` doesn't exist yet.

- [ ] **Step 3: Create `ImportMembersRequest`**

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportMembersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:xlsx'],
        ];
    }
}
```

- [ ] **Step 4: Create `MembersImport`**

```php
<?php

namespace App\Imports;

use App\Models\Member;
use App\Models\Position;
use App\Models\Title;
use Illuminate\Support\Collection as BaseCollection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

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
            $rowNumber = $index + 2;

            $email = trim((string) ($row['email'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            $club = trim((string) ($row['club'] ?? ''));
            $phone = trim((string) ($row['phone'] ?? ''));
            $titre = trim((string) ($row['titre'] ?? ''));
            $poste = trim((string) ($row['poste'] ?? ''));
            $classification = trim((string) ($row['classification'] ?? ''));

            if ($email === '' && $name === '' && $club === '' && $phone === '' && $titre === '' && $poste === '' && $classification === '') {
                continue;
            }

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

            $attributes = [
                'name' => $name,
                'club' => $club,
                'phone' => $phone,
                'title_id' => $titlesByName[$titre],
                'is_club_member' => true,
            ];

            if ($classification !== '') {
                $attributes['classification'] = $classification;
            }

            if ($poste !== '') {
                $attributes['position_id'] = $positionsByName[$poste];
            }

            Member::updateOrCreate(['email' => Member::normalizeEmail($email)], $attributes);

            $this->imported++;
        }
    }
}
```

- [ ] **Step 5: Add the route**

In `routes/web.php`, right after `members.import-template`:

```php
Route::post('members/import', [MemberController::class, 'import'])->name('members.import');
```

- [ ] **Step 6: Add the controller action**

In `app/Http/Controllers/Admin/MemberController.php`, add `use App\Http\Requests\ImportMembersRequest;` and `use App\Imports\MembersImport;` to the imports, and add:

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

- [ ] **Step 7: Add the upload form and summary to the index view**

In `resources/views/admin/members/index.blade.php`, add near the template-download link:

```blade
<form method="POST" action="{{ route('admin.members.import') }}" enctype="multipart/form-data" class="mt-4 ml-2 inline-flex items-center gap-2">
    @csrf
    <input type="file" name="file" accept=".xlsx" required class="text-sm">
    <button type="submit"
        class="cursor-pointer rounded-lg border border-border px-4 py-2.5 text-sm font-bold text-navy hover:bg-cream">
        <i class="fa-solid fa-file-arrow-up" aria-hidden="true"></i> Importer des membres du club
    </button>
</form>

@if (session('membersImported') !== null)
    <div class="mt-4 rounded-lg bg-success-bg px-4 py-3 text-sm text-success">
        {{ session('membersImported') }} membre(s) importé(s).
    </div>
@endif

@if (session('membersImportErrors') && count(session('membersImportErrors')) > 0)
    <div class="mt-4 rounded-lg bg-error-bg px-4 py-3 text-sm text-error">
        <p class="font-semibold">{{ count(session('membersImportErrors')) }} ligne(s) en erreur :</p>
        <ul class="mt-1 list-disc pl-5">
            @foreach (session('membersImportErrors') as $error)
                <li>Ligne {{ $error['row'] }} : {{ $error['message'] }}</li>
            @endforeach
        </ul>
    </div>
@endif
```

- [ ] **Step 8: Run tests to verify they pass**

Run: `php artisan test --compact --filter=MembersImportTest`
Expected: PASS — full file.

- [ ] **Step 9: Run the full test suite**

Run: `php artisan test --compact`
Expected: PASS — no regressions in any other test file.

- [ ] **Step 10: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Imports/MembersImport.php app/Http/Requests/ImportMembersRequest.php app/Http/Controllers/Admin/MemberController.php routes/web.php resources/views/admin/members/index.blade.php tests/Feature/Admin/MembersImportTest.php
git commit -m "feat: import club members in bulk from an xlsx file"
```

---

## Self-Review Notes

**Spec coverage:**
- §1 Data model → Task 1.
- §2 Admin member creation (create form + edit checkbox + index badge) → Tasks 2–3.
- §3 Check-in short-circuit → Task 4.
- §4 Session list filtering (corrected: tiles stay server-rendered) → Task 5.
- §5 PDF email column + club-member exclusion → Task 6.
- §6 Excel import (template + import) → Tasks 7–8.
- All "Out of scope" items from the spec are correctly absent from every task (no cascading dropdowns, no dedup UI, no member deletion, no dashboard-stat changes, no separate import page).

**Type/signature consistency check:** `checkInClubMember`/`alreadyCheckedIn` (Task 4), `visibleAttendances` (Task 5, reused verbatim in Task 6's `exportPdf()`), `MembersImportTemplateExport` column order (`email, name, phone, club, classification, titre, poste` — Task 7) matches the column keys `MembersImport` reads in Task 8 (`$row['email']`, `$row['name']`, etc., since `WithHeadingRow` lower-cases/slugifies headers to match array keys 1:1 with the template's plain lowercase headings). `is_club_member` naming is identical across the migration, model cast, both form requests, and both `MembersImport` and the check-in controller.

**No placeholders:** every step has runnable code; no "add validation" or "similar to Task N" hand-waves remain.
