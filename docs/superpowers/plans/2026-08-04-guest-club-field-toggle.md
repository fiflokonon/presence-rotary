# Optional "Nom de club" Field For Guests Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an admin control, via a new checkin-settings toggle (default off), whether the public check-in form requires guests ("Invité") to fill in "Nom de votre club" — hidden and optional for guests by default, still required for everyone else.

**Architecture:** A second boolean column on the existing single-row `checkin_settings` table (mirroring `show_guest_option`) drives three things: an admin checkbox, a client-side Alpine `x-show`/`:required` toggle on the public form's club field, and a server-side `Rule::requiredIf` on `StoreAttendanceRequest`. This requires `club` to become nullable on both `attendances` and `members`.

**Tech Stack:** Laravel 13, PHP 8.4, Pest 4, Blade + Alpine.js, SQLite (per-tenant connections).

## Global Constraints

- Follow the existing `CheckinSetting::show_guest_option` pattern exactly for the new setting — one more boolean column on the same single-row `checkin_settings` table, not a new table or generic settings system.
- Do not touch `StoreMemberRequest` / `UpdateMemberRequest` (admin member CRUD) — `club` stays required there; this change is public check-in-form only.
- Do not add a per-title `requires_club` flag or any other generalization beyond the "Invité" title — out of scope (YAGNI).
- Run `vendor/bin/pint --dirty --format agent` after every task that touches `.php` files, before committing.
- Run `php artisan test --compact --filter=<Name>` for the affected test file after each task, before moving on.

---

### Task 1: `show_club_field_for_guests` setting on `CheckinSetting`

**Files:**
- Create: `database/migrations/2026_08_04_120000_add_show_club_field_for_guests_to_checkin_settings_table.php`
- Modify: `app/Models/CheckinSetting.php`
- Test: `tests/Feature/CheckinSettingTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks (foundation task).
- Produces: `CheckinSetting::clubFieldEnabledForGuests(): bool`, used by Task 2 (view), Task 4 (validation), and Task 5 (public form).

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/CheckinSettingTest.php`:

```php
it('defaults clubFieldEnabledForGuests to false when no row exists', function () {
    expect(CheckinSetting::current())->toBeNull()
        ->and(CheckinSetting::clubFieldEnabledForGuests())->toBeFalse();
});

it('reflects the stored club field value once a row exists', function () {
    CheckinSetting::create(['show_club_field_for_guests' => true]);

    expect(CheckinSetting::clubFieldEnabledForGuests())->toBeTrue();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=CheckinSettingTest`
Expected: FAIL — `Call to undefined method App\Models\CheckinSetting::clubFieldEnabledForGuests()`

- [ ] **Step 3: Create the migration**

`database/migrations/2026_08_04_120000_add_show_club_field_for_guests_to_checkin_settings_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkin_settings', function (Blueprint $table) {
            $table->boolean('show_club_field_for_guests')->default(false)->after('show_guest_option');
        });
    }

    public function down(): void
    {
        Schema::table('checkin_settings', function (Blueprint $table) {
            $table->dropColumn('show_club_field_for_guests');
        });
    }
};
```

- [ ] **Step 4: Update the model**

`app/Models/CheckinSetting.php` — full new contents:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CheckinSetting extends Model
{
    protected $fillable = ['show_guest_option', 'show_club_field_for_guests'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'show_guest_option' => 'boolean',
            'show_club_field_for_guests' => 'boolean',
        ];
    }

    public static function current(): ?self
    {
        return static::query()->first();
    }

    public static function guestOptionEnabled(): bool
    {
        return static::current()?->show_guest_option ?? true;
    }

    public static function clubFieldEnabledForGuests(): bool
    {
        return static::current()?->show_club_field_for_guests ?? false;
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact --filter=CheckinSettingTest`
Expected: PASS (all tests in the file)

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_08_04_120000_add_show_club_field_for_guests_to_checkin_settings_table.php app/Models/CheckinSetting.php tests/Feature/CheckinSettingTest.php
git commit -m "feat: add show_club_field_for_guests setting to CheckinSetting"
```

---

### Task 2: Admin checkbox for the new setting

**Files:**
- Modify: `app/Http/Requests/UpdateCheckinSettingRequest.php`
- Modify: `resources/views/admin/checkin-settings/edit.blade.php`
- Test: `tests/Feature/Admin/CheckinSettingManagementTest.php`

**Interfaces:**
- Consumes: `CheckinSetting::clubFieldEnabledForGuests()` from Task 1 (used by the view's `@checked`).
- Produces: nothing new consumed by later tasks — this is the admin-facing leaf of the setting. `Admin\CheckinSettingController::update()` needs no change, since it already does `CheckinSetting::current()->update($request->validated())` / `CheckinSetting::create($request->validated())` generically over whatever `UpdateCheckinSettingRequest::rules()` allows through.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Admin/CheckinSettingManagementTest.php`:

```php
it('shows the club field toggle unchecked by default when no settings are saved yet', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.checkin-settings.edit'))
        ->assertOk()
        ->assertSee('Afficher le champ « Nom de club » pour les invités');
});

it('creates the checkin settings row with the club field disabled when the checkbox is left unchecked', function () {
    $this->actingAs(User::factory()->create())
        ->put(route('admin.checkin-settings.update'), [])
        ->assertRedirect(route('admin.checkin-settings.edit'));

    expect(CheckinSetting::current()->show_club_field_for_guests)->toBeFalse();
});

it('enables the club field for guests when the checkbox is submitted checked', function () {
    CheckinSetting::create(['show_club_field_for_guests' => false]);

    $this->actingAs(User::factory()->create())
        ->put(route('admin.checkin-settings.update'), ['show_club_field_for_guests' => '1'])
        ->assertRedirect(route('admin.checkin-settings.edit'));

    expect(CheckinSetting::current()->show_club_field_for_guests)->toBeTrue();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=CheckinSettingManagementTest`
Expected: FAIL — the two "checkbox submitted" tests fail because `show_club_field_for_guests` is stripped by validation (not in `rules()`); the "shows the toggle" test fails because the checkbox text isn't in the view yet.

- [ ] **Step 3: Update the form request**

`app/Http/Requests/UpdateCheckinSettingRequest.php` — full new contents:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCheckinSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'show_guest_option' => $this->boolean('show_guest_option'),
            'show_club_field_for_guests' => $this->boolean('show_club_field_for_guests'),
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'show_guest_option' => ['boolean'],
            'show_club_field_for_guests' => ['boolean'],
        ];
    }
}
```

- [ ] **Step 4: Add the checkbox to the view**

In `resources/views/admin/checkin-settings/edit.blade.php`, add a second checkbox right after the existing `show_guest_option` one, inside the same `<form>`:

```blade
            <label class="flex items-center gap-2 text-sm font-semibold">
                <input type="checkbox" name="show_guest_option" value="1"
                    @checked(old('show_guest_option', $checkinSetting?->show_guest_option ?? true))>
                Afficher l'option « Invité » sur le formulaire de présence
            </label>
            <label class="flex items-center gap-2 text-sm font-semibold">
                <input type="checkbox" name="show_club_field_for_guests" value="1"
                    @checked(old('show_club_field_for_guests', $checkinSetting?->show_club_field_for_guests ?? false))>
                Afficher le champ « Nom de club » pour les invités
            </label>
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact --filter=CheckinSettingManagementTest`
Expected: PASS (all tests in the file)

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Requests/UpdateCheckinSettingRequest.php resources/views/admin/checkin-settings/edit.blade.php tests/Feature/Admin/CheckinSettingManagementTest.php
git commit -m "feat: add admin toggle for the guest club field setting"
```

---

### Task 3: Make `club` nullable on `attendances` and `members`

**Files:**
- Create: `database/migrations/2026_08_04_130000_make_club_nullable_on_attendances_table.php`
- Create: `database/migrations/2026_08_04_130100_make_club_nullable_on_members_table.php`
- Test: `tests/Feature/Models/AttendanceTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: DB schema change that Task 4 (validation) and Task 5 (public form submission) both rely on — without this, a `null` `club` submitted through the guest path would still fail at the DB layer with a NOT NULL constraint violation regardless of validation passing.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Models/AttendanceTest.php` (the file already exists with the needed `Attendance`, `MeetingSession`, `Title` imports):

```php
it('allows a null club', function () {
    $meetingSession = MeetingSession::factory()->create();
    $title = Title::where('name', 'Invité')->sole();

    $attendance = Attendance::create([
        'meeting_session_id' => $meetingSession->id,
        'title_id' => $title->id,
        'name' => 'Awa Bello',
        'club' => null,
        'phone' => '+229 91 00 00 00',
        'email' => 'awa.bello@example.com',
        'present' => true,
        'is_late' => false,
    ]);

    expect($attendance->fresh()->club)->toBeNull();
});
```

Add the needed `use` statements at the top of the file (`App\Models\Attendance`, `App\Models\MeetingSession`, `App\Models\Title`) if not already present.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=AttendanceTest`
Expected: FAIL — `SQLSTATE[23000]: ... NOT NULL constraint failed: attendances.club`

- [ ] **Step 3: Create the attendances migration**

`database/migrations/2026_08_04_130000_make_club_nullable_on_attendances_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->string('club')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->string('club')->nullable(false)->change();
        });
    }
};
```

- [ ] **Step 4: Create the members migration**

`database/migrations/2026_08_04_130100_make_club_nullable_on_members_table.php`:

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
            $table->string('club')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->string('club')->nullable(false)->change();
        });
    }
};
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact --filter=AttendanceTest`
Expected: PASS

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_08_04_130000_make_club_nullable_on_attendances_table.php database/migrations/2026_08_04_130100_make_club_nullable_on_members_table.php tests/Feature/Models/AttendanceTest.php
git commit -m "feat: make club nullable on attendances and members"
```

---

### Task 4: Conditional `club` validation on `StoreAttendanceRequest`

**Files:**
- Modify: `app/Http/Requests/StoreAttendanceRequest.php`
- Test: `tests/Feature/AttendanceFormTest.php`

**Interfaces:**
- Consumes: `CheckinSetting::clubFieldEnabledForGuests()` (Task 1), `club` nullable on `attendances`/`members` (Task 3), `Title::GUEST_NAME` (existing constant).
- Produces: nothing new consumed by later tasks — `AttendanceFormController::store()` already forwards `$request->validated()` / `$request->safe()->only([...])` unchanged, so no controller edit is needed here.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/AttendanceFormTest.php`:

```php
it('allows a guest submission without a club name by default', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);
    $invite = Title::where('name', 'Invité')->sole();

    $this->post(route('attendance.store'), [
        'title_id' => $invite->id,
        'name' => 'Awa Bello',
        'phone' => '+229 91 00 00 00',
        'email' => 'awa.bello@example.com',
    ])->assertRedirect(route('attendance.show'))
        ->assertSessionDoesntHaveErrors();

    expect(Attendance::first()->club)->toBeNull();
});

it('requires a club name for a guest submission when the setting is enabled', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);
    CheckinSetting::create(['show_club_field_for_guests' => true]);
    $invite = Title::where('name', 'Invité')->sole();

    $this->post(route('attendance.store'), [
        'title_id' => $invite->id,
        'name' => 'Awa Bello',
        'phone' => '+229 91 00 00 00',
        'email' => 'awa.bello@example.com',
    ])->assertSessionHasErrors(['club']);

    expect(Attendance::count())->toBe(0);
});

it('still requires a club name for a non-guest submission regardless of the setting', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);
    $rotary = Title::where('name', 'Rotary')->sole();
    $president = $rotary->positions()->where('name', 'Président')->sole();

    $this->post(route('attendance.store'), [
        'title_id' => $rotary->id,
        'position_id' => $president->id,
        'name' => 'Jean Dupont',
        'phone' => '+229 90 00 00 00',
        'email' => 'jean.dupont@example.com',
    ])->assertSessionHasErrors(['club']);

    expect(Attendance::count())->toBe(0);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=AttendanceFormTest`
Expected: FAIL — the first new test fails because `club` is still unconditionally `required`; the other two currently pass already (unconditional `required` covers them) but must keep passing after Step 3.

- [ ] **Step 3: Update the form request**

`app/Http/Requests/StoreAttendanceRequest.php` — full new contents:

```php
<?php

namespace App\Http\Requests;

use App\Models\CheckinSetting;
use App\Models\Title;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAttendanceRequest extends FormRequest
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
            'invited_by' => ['nullable', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'club' => ['nullable', 'string', 'max:255', Rule::requiredIf(fn () => $this->clubRequiredForSubmission())],
            'phone' => ['required', 'string', 'max:50'],
            'classification' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'has_misc' => ['nullable', 'boolean'],
        ];
    }

    /**
     * `club` is only optional when the submitted title is "Invité" and the
     * admin has left the guest club field disabled — every other title
     * always requires it, matching the pre-existing behavior.
     */
    private function clubRequiredForSubmission(): bool
    {
        if (CheckinSetting::clubFieldEnabledForGuests()) {
            return true;
        }

        $title = Title::find($this->input('title_id'));

        return $title?->name !== Title::GUEST_NAME;
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

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=AttendanceFormTest`
Expected: PASS (all tests in the file, including the pre-existing `rejects a submission missing required fields` test — it posts no `title_id` at all, so `clubRequiredForSubmission()` resolves `Title::find(null)` to `null`, which is `!== Title::GUEST_NAME`, keeping `club` required as before)

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Requests/StoreAttendanceRequest.php tests/Feature/AttendanceFormTest.php
git commit -m "feat: make club optional for guest check-ins unless the admin setting is enabled"
```

---

### Task 5: Wire the toggle into the public check-in form

**Files:**
- Modify: `app/Http/Controllers/AttendanceFormController.php:130-152`
- Modify: `resources/views/components/attendance-form.blade.php`
- Test: `tests/Feature/AttendanceFormTest.php`

**Interfaces:**
- Consumes: `CheckinSetting::clubFieldEnabledForGuests()` (Task 1).
- Produces: nothing consumed by later tasks — this is the form's leaf UI behavior.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/AttendanceFormTest.php`:

```php
it('marks the club field as conditionally required for guests by default', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);

    $this->post(route('attendance.lookup'), ['email' => 'nouveau@example.com'])
        ->assertOk()
        ->assertSee('x-show="clubRequired"', false)
        ->assertSee(':required="clubRequired"', false)
        ->assertSee('clubFieldEnabledForGuests: false', false);
});

it('passes the club field as enabled for guests when the setting is on', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);
    CheckinSetting::create(['show_club_field_for_guests' => true]);

    $this->post(route('attendance.lookup'), ['email' => 'nouveau@example.com'])
        ->assertOk()
        ->assertSee('clubFieldEnabledForGuests: true', false);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=AttendanceFormTest`
Expected: FAIL — `clubRequired`/`clubFieldEnabledForGuests` don't exist in the rendered form yet.

- [ ] **Step 3: Pass the flag from the controller**

In `app/Http/Controllers/AttendanceFormController.php`, update the `attendanceFormData()` method (currently at lines 130-152):

```php
    /**
     * @return array{titles: Collection<int, Title>, guestTitleId: ?int, clubFieldEnabledForGuests: bool}
     */
    private function attendanceFormData(?Member $member): array
    {
        $titles = Title::activeOrId($member?->title_id)
            ->where(function ($query) use ($member) {
                $query->where('name', '!=', Title::GUEST_NAME)
                    ->when(
                        $member?->title_id !== null,
                        fn ($q) => $q->orWhere('id', $member->title_id),
                    );
            })
            ->with(['positions' => fn ($query) => $query->activeOrId($member?->position_id)])
            ->orderBy('order')
            ->orderBy('name')
            ->get();

        $guestTitle = Title::with('positions')->firstWhere('name', Title::GUEST_NAME);

        if ($guestTitle !== null && $guestTitle->id !== $member?->title_id && CheckinSetting::guestOptionEnabled()) {
            $titles->push($guestTitle);
        }

        return [
            'titles' => $titles,
            'guestTitleId' => $guestTitle?->id,
            'clubFieldEnabledForGuests' => CheckinSetting::clubFieldEnabledForGuests(),
        ];
    }
```

(Only the final `return` block and the docblock change — the rest of the method is unchanged.)

- [ ] **Step 4: Update the form component**

In `resources/views/components/attendance-form.blade.php`:

1. Update the `@props` line (currently line 1):

```blade
@props(['late' => false, 'email', 'member' => null, 'titles', 'guestTitleId' => null, 'clubFieldEnabledForGuests' => false])
```

2. Replace the `x-data` block through the end of the "Nom de votre club" field (currently lines 27-82) with:

```blade
    <div x-data="{
            titleId: '{{ old('title_id', $member?->title_id) }}',
            positionId: '{{ old('position_id', $member?->position_id) }}',
            positionsByTitle: {{ Illuminate\Support\Js::from($titles->mapWithKeys(fn ($t) => [
                $t->id => $t->positions->map(fn ($p) => [
                    'id' => $p->id,
                    'name' => $p->is_active ? $p->name : $p->name.' (inactif)',
                ])->values(),
            ])) }},
            clubFieldEnabledForGuests: {{ Illuminate\Support\Js::from($clubFieldEnabledForGuests) }},
            get availablePositions() { return this.positionsByTitle[this.titleId] ?? [] },
            get isGuest() { return this.titleId !== '' && this.titleId == '{{ $guestTitleId }}' },
            get clubRequired() { return !this.isGuest || this.clubFieldEnabledForGuests },
        }"
        class="contents"
    >
        <div class="flex flex-col gap-1.5">
            <label for="title_id" class="text-sm font-semibold text-[#12213D]">Organisation*</label>
            <select x-model="titleId" id="title_id" name="title_id" required
                class="rounded-lg border border-[#DEDAD0] px-3 py-2 text-sm">
                <option value="">Sélectionnez…</option>
                @foreach ($titles as $titleOption)
                    <option value="{{ $titleOption->id }}">{{ $titleOption->is_active ? $titleOption->name : $titleOption->name.' (inactif)' }}</option>
                @endforeach
            </select>
        </div>

        <div class="flex flex-col gap-1.5" x-show="availablePositions.length > 0">
            <label for="position_id" class="text-sm font-semibold text-[#12213D]">Titre/Qualité*</label>
            <select x-model="positionId" id="position_id" name="position_id" :required="availablePositions.length > 0"
                class="rounded-lg border border-[#DEDAD0] px-3 py-2 text-sm">
                <option value="">Sélectionnez…</option>
                <template x-for="position in availablePositions" :key="position.id">
                    <option :value="position.id" x-text="position.name"></option>
                </template>
            </select>
        </div>

        @if ($guestTitleId !== null && $titles->contains('id', $guestTitleId))
            <div class="flex flex-col gap-1.5" x-show="isGuest" x-cloak>
                <label for="invited_by" class="text-sm font-semibold text-[#12213D]">Invité par</label>
                <input type="text" id="invited_by" name="invited_by" value="{{ old('invited_by') }}"
                    class="rounded-lg border border-[#DEDAD0] px-3 py-2 text-sm">
            </div>
        @endif

        <div class="flex flex-col gap-1.5">
            <label for="name" class="text-sm font-semibold text-[#12213D]">Nom et prénoms*</label>
            <input type="text" id="name" name="name" value="{{ old('name', $member?->name) }}" required
                class="rounded-lg border border-[#DEDAD0] px-3 py-2 text-sm">
        </div>

        <div class="flex flex-col gap-1.5" x-show="clubRequired">
            <label for="club" class="text-sm font-semibold text-[#12213D]">Nom de votre club*</label>
            <input type="text" id="club" name="club" value="{{ old('club', $member?->club) }}" :required="clubRequired"
                class="rounded-lg border border-[#DEDAD0] px-3 py-2 text-sm">
        </div>
    </div>
```

This moves the "Nom et prénoms" and "Nom de votre club" fields inside the existing `x-data` wrapper (kept as `class="contents"` so the flex layout of the parent `<form>` is unaffected) so the club field can reference `clubRequired`. Visual field order is unchanged. The `phone` field and everything after it (currently starting at line 84, "Numéro de téléphone") stays outside this block, untouched.

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact --filter=AttendanceFormTest`
Expected: PASS (all tests in the file)

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/AttendanceFormController.php resources/views/components/attendance-form.blade.php tests/Feature/AttendanceFormTest.php
git commit -m "feat: hide the club field for guests on the public check-in form by default"
```

---

### Task 6: Fix "null" literal rendering in the admin attendance list

**Files:**
- Modify: `resources/views/components/attendance-row.blade.php:7`
- Test: `tests/Feature/Admin/AttendanceDashboardTest.php`

**Interfaces:**
- Consumes: `club` now nullable on `attendances` (Task 3).
- Produces: nothing consumed elsewhere — this is a standalone display fix.

**Context:** `resources/views/admin/sessions/show.blade.php` passes `'club' => $attendance->club` into an `attendanceDashboard(...)` Alpine component via `@js(...)`. `attendance-row.blade.php:7` then builds a summary line by string-concatenating `record.club` directly in JS: `record.title + (...) + ' · ' + record.club`. When `record.club` is `null` (now possible for guests, per Task 4), JavaScript's `+` operator coerces it to the literal text `"null"`, so the admin list would show `· null` instead of nothing.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Admin/AttendanceDashboardTest.php`:

```php
it('renders a null club as an empty string instead of the literal "null"', function () {
    $meetingSession = MeetingSession::factory()->create();
    Attendance::factory()->create([
        'meeting_session_id' => $meetingSession->id,
        'club' => null,
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.show', $meetingSession))
        ->assertOk()
        ->assertSee("(record.club ?? '')", false);
});
```

The file already imports `Attendance`, `MeetingSession`, and `User` — no new `use` statements needed.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=AttendanceDashboardTest`
Expected: FAIL — the raw HTML source still reads `record.club` without the null-coalescing guard.

- [ ] **Step 3: Fix the template**

In `resources/views/components/attendance-row.blade.php`, line 7, change:

```blade
                <span x-text="record.title + (record.position ? ' — ' + record.position : '') + ' · ' + record.club"></span>
```

to:

```blade
                <span x-text="record.title + (record.position ? ' — ' + record.position : '') + ' · ' + (record.club ?? '')"></span>
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=AttendanceDashboardTest`
Expected: PASS (all tests in the file)

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/components/attendance-row.blade.php tests/Feature/Admin/AttendanceDashboardTest.php
git commit -m "fix: render a null club as blank instead of the literal 'null' in the attendance list"
```

---

## Final Verification

- [ ] Run the full suite: `php artisan test --compact`
- [ ] Manually exercise the golden path in a browser (per project convention for UI changes): open the public check-in form, submit as "Invité" with no club filled in (succeeds), then toggle "Afficher le champ « Nom de club » pour les invités" on in `/admin/checkin-settings`, retry the same guest submission with no club (now blocked), and confirm a non-guest submission without a club is still blocked regardless of the toggle.
