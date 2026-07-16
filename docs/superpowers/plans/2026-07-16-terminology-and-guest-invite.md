# Terminology Rename & Guest Invite Flow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rename the user-facing "Titre"/"Poste" labels to "Organisation"/"Titre-Qualité", add an optional "invited by" field shown when a guest picks "Invité" on the public check-in form, and give admins a toggle to show/hide the "Invité" option.

**Architecture:** Renames touch only Blade strings and validation messages — no schema/class/route changes. The guest flow adds one nullable column (`attendances.invited_by`), one new single-row settings table (`checkin_settings`, mirroring the existing `mail_settings` pattern), and excludes the seeded "Invité" `Title` row from the admin CRUD via a name-based guard so it can no longer be edited or deleted by mistake.

**Tech Stack:** Laravel 13, Pest 4, Blade + Alpine.js, Tailwind v4.

## Global Constraints

- Spec: `docs/superpowers/specs/2026-07-16-terminology-and-guest-invite-design.md`.
- Rename touches ONLY displayed strings — do not rename the `Title`/`Position` classes, tables, routes, or `title_id`/`position_id` columns.
- Do NOT rename `MeetingSession->title` (the session/meeting's own name) anywhere it appears — it is unrelated to the `Title` (organisation) model.
- The guest ("Invité") flow is public-check-in-form only — do not touch the admin member-edit form's title/position selects.
- `invited_by` lives on `attendances` only, never on `members`.
- After every task: run `vendor/bin/pint --dirty --format agent` before committing.
- Run tests with `php artisan test --compact` (optionally `--filter=<name>` while iterating).

---

### Task 1: Rename "Titre" → "Organisation" (UI strings)

**Files:**
- Modify: `resources/views/components/attendance-form.blade.php:41`
- Modify: `resources/views/components/layouts/admin.blade.php:71`
- Modify: `resources/views/admin/titles/index.blade.php:1,4,7,52`
- Modify: `resources/views/admin/titles/create.blade.php:1,3,38`
- Modify: `resources/views/admin/sessions/pdf.blade.php:26`
- Modify: `resources/views/admin/sessions/show.blade.php:135`
- Modify: `resources/views/admin/members/show.blade.php:25`
- Modify: `resources/views/admin/members/edit.blade.php:23`
- Modify: `app/Http/Controllers/Admin/TitleController.php:74`
- Modify: `tests/Feature/Admin/AttendanceDashboardTest.php:81`
- Create: `tests/Feature/Admin/OrganisationLabelTest.php`

**Interfaces:** None — pure string changes, no new functions/signatures.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Admin/OrganisationLabelTest.php`:

```php
<?php

use App\Models\MeetingSession;
use App\Models\Member;
use App\Models\Title;
use App\Models\User;

it('shows Organisation instead of Titre on the public check-in form', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);

    $this->get(route('attendance.show'))
        ->assertOk()
        ->assertSee('Organisation*', false)
        ->assertDontSee('Titre*', false);
});

it('shows Organisation instead of Titre in the admin sidebar', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.titles.index'))
        ->assertOk()
        ->assertSee('Organisations')
        ->assertDontSee('>Titres<', false);
});

it('shows Organisation instead of Titre on the titles admin pages', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.titles.index'))
        ->assertOk()
        ->assertSee('Ajouter une organisation');

    $this->actingAs(User::factory()->create())
        ->get(route('admin.titles.create'))
        ->assertOk()
        ->assertSee('Ajouter une organisation')
        ->assertSee('Créer l\'organisation');
});

it('shows Organisation instead of Titre on the session PDF export header', function () {
    $meetingSession = MeetingSession::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.export-pdf', $meetingSession))
        ->assertOk()
        ->assertSee('Organisation', false);
});

it('shows Organisation instead of Titre in the member detail/edit labels', function () {
    $member = Member::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('admin.members.show', $member))
        ->assertOk()
        ->assertSee('Organisation / Titre-Qualité');

    $this->actingAs(User::factory()->create())
        ->get(route('admin.members.edit', $member))
        ->assertOk()
        ->assertSee('>Organisation<', false);
});

it('shows a friendly message using Organisation wording when deleting a referenced title', function () {
    $title = Title::factory()->create();
    Member::factory()->create(['title_id' => $title->id]);

    $this->actingAs(User::factory()->create())
        ->delete(route('admin.titles.destroy', $title))
        ->assertRedirect(route('admin.titles.index'))
        ->assertSessionHas('error', 'Cette organisation est utilisée par des membres ou des présences existantes — désactivez-la plutôt que de la supprimer.');
});
```

Also update the now-stale assertion in `tests/Feature/Admin/AttendanceDashboardTest.php:81` (it currently asserts the old "Tous les titres" filter-dropdown text on the session dashboard, which is the same `Title`/organisation concept, just rendered inside the sessions view):

```php
        ->assertSee('x-model="activeTitle"', false)
        ->assertSee('Toutes les organisations')
        ->assertSee('Rotary')
```

(replacing the old `->assertSee('Tous les titres')` line)

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=OrganisationLabelTest`
Expected: FAIL — old text ("Titre*", "Titres", "Ajouter un titre", etc.) still present, new text absent.

Run: `php artisan test --compact --filter=AttendanceDashboardTest`
Expected: FAIL on the updated assertion — page still shows "Tous les titres".

- [ ] **Step 3: Apply the renames**

`resources/views/components/attendance-form.blade.php:41`:
```blade
            <label for="title_id" class="text-sm font-semibold text-[#12213D]">Organisation*</label>
```

`resources/views/components/layouts/admin.blade.php:69-72`:
```blade
                <a href="{{ route('admin.titles.index') }}" @click="close()"
                    class="cursor-pointer rounded-lg px-3 py-2 text-sm font-semibold {{ request()->routeIs('admin.titles.*') ? 'bg-navy text-white' : 'text-navy hover:bg-cream' }}">
                    Organisations
                </a>
```

`resources/views/admin/titles/index.blade.php` — replace lines 1, 4, 7, 52:
```blade
<x-layouts.admin title="Organisations — Administration">
    <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)] md:p-8">
        <div class="flex items-center justify-between gap-3">
            <h1 class="font-display text-xl font-extrabold text-navy">Organisations</h1>
            <a href="{{ route('admin.titles.create') }}"
                class="cursor-pointer rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                Ajouter une organisation
            </a>
        </div>
```
and (confirm dialog, line 52):
```blade
                                        onsubmit="return confirm('Supprimer définitivement cette organisation ?');">
```

`resources/views/admin/titles/create.blade.php` — replace lines 1, 3, 38:
```blade
<x-layouts.admin title="Ajouter une organisation — Administration">
    <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)] md:p-8">
        <h1 class="font-display text-xl font-extrabold text-navy">Ajouter une organisation</h1>
```
and (submit button, line 38):
```blade
                Créer l'organisation
```

`resources/views/admin/sessions/pdf.blade.php:26`:
```blade
                        <th>Organisation</th>
```

`resources/views/admin/sessions/show.blade.php:135`:
```blade
                <option value="all">Toutes les organisations</option>
```

`resources/views/admin/members/show.blade.php:25`:
```blade
                <dt class="font-semibold text-muted-strong">Organisation / Titre-Qualité</dt>
```

`resources/views/admin/members/edit.blade.php:23`:
```blade
                    <label for="title_id" class="text-sm font-semibold">Organisation</label>
```

`app/Http/Controllers/Admin/TitleController.php:73-74`:
```php
            return redirect()->route('admin.titles.index')
                ->with('error', 'Cette organisation est utilisée par des membres ou des présences existantes — désactivez-la plutôt que de la supprimer.');
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=OrganisationLabelTest`
Expected: PASS

Run: `php artisan test --compact --filter=AttendanceDashboardTest`
Expected: PASS

Run: `php artisan test --compact`
Expected: full suite PASS (no other test asserted the old "Titre"/"titre" label strings — confirmed by prior grep).

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/components/attendance-form.blade.php \
    resources/views/components/layouts/admin.blade.php \
    resources/views/admin/titles/index.blade.php \
    resources/views/admin/titles/create.blade.php \
    resources/views/admin/sessions/pdf.blade.php \
    resources/views/admin/sessions/show.blade.php \
    resources/views/admin/members/show.blade.php \
    resources/views/admin/members/edit.blade.php \
    app/Http/Controllers/Admin/TitleController.php \
    tests/Feature/Admin/AttendanceDashboardTest.php \
    tests/Feature/Admin/OrganisationLabelTest.php
git commit -m "Rename Titre label to Organisation across the UI"
```

---

### Task 2: Rename "Poste" → "Titre/Qualité" (UI strings)

**Files:**
- Modify: `resources/views/components/attendance-form.blade.php:52`
- Modify: `resources/views/components/layouts/admin.blade.php:75`
- Modify: `resources/views/admin/titles/create.blade.php:25`
- Modify: `resources/views/admin/titles/index.blade.php:23`
- Modify: `resources/views/admin/titles/edit.blade.php:25`
- Modify: `resources/views/admin/positions/index.blade.php:1,4,7,48`
- Modify: `resources/views/admin/positions/create.blade.php:1,3,14`
- Modify: `resources/views/admin/members/edit.blade.php:34`
- Modify: `app/Http/Requests/StoreAttendanceRequest.php:58,65`
- Modify: `app/Http/Requests/UpdateMemberRequest.php:58,65` (message strings identical to StoreAttendanceRequest)
- Modify: `app/Http/Controllers/Admin/PositionController.php:59`
- Create: `tests/Feature/Admin/TitreQualiteLabelTest.php`

**Interfaces:** None — pure string changes.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Admin/TitreQualiteLabelTest.php`:

```php
<?php

use App\Models\MeetingSession;
use App\Models\Member;
use App\Models\Position;
use App\Models\Title;
use App\Models\User;

it('shows Titre/Qualité instead of Poste on the public check-in form', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);

    $this->get(route('attendance.show'))
        ->assertOk()
        ->assertSee('Titre/Qualité*', false);
});

it('shows Titres/Qualités instead of Postes in the admin sidebar and pages', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.positions.index'))
        ->assertOk()
        ->assertSee('Titres/Qualités')
        ->assertSee('Ajouter un titre/qualité');

    $this->actingAs(User::factory()->create())
        ->get(route('admin.positions.create'))
        ->assertOk()
        ->assertSee('Ajouter un titre/qualité')
        ->assertSee('Créer le titre/qualité');
});

it('shows Titres/Qualités liés on the organisation admin forms', function () {
    $title = Title::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('admin.titles.create'))
        ->assertOk()
        ->assertSee('Titres/Qualités liés');

    $this->actingAs(User::factory()->create())
        ->get(route('admin.titles.edit', $title))
        ->assertOk()
        ->assertSee('Titres/Qualités liés');

    $this->actingAs(User::factory()->create())
        ->get(route('admin.titles.index'))
        ->assertOk()
        ->assertSee('Titres/Qualités liés');
});

it('shows Titre/Qualité instead of Poste in the member edit label', function () {
    $member = Member::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('admin.members.edit', $member))
        ->assertOk()
        ->assertSee('>Titre/Qualité<', false);
});

it('uses Titre/Qualité wording in the position-required validation message', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);
    $rotary = Title::where('name', 'Rotary')->sole();

    $this->post(route('attendance.store'), [
        'title_id' => $rotary->id,
        'name' => 'Jean Dupont',
        'club' => 'RC Cotonou Ife',
        'phone' => '+229 90 00 00 00',
        'email' => 'jean.dupont@example.com',
    ])->assertSessionHasErrors(['position_id' => 'Le titre/qualité est obligatoire pour cette organisation.']);
});

it('uses Titre/Qualité wording in the position-mismatch validation message', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);
    $jci = Title::where('name', 'JCI')->sole();
    $rotaryOnlyPosition = Title::where('name', 'Rotary')->sole()->positions()->where('name', 'PDG')->sole();

    $this->post(route('attendance.store'), [
        'title_id' => $jci->id,
        'position_id' => $rotaryOnlyPosition->id,
        'name' => 'Jean Dupont',
        'club' => 'RC Cotonou Ife',
        'phone' => '+229 90 00 00 00',
        'email' => 'jean.dupont@example.com',
    ])->assertSessionHasErrors(['position_id' => 'Le titre/qualité sélectionné ne correspond pas à l\'organisation choisie.']);
});

it('shows a friendly message using Titre/Qualité wording when deleting a referenced position', function () {
    $position = Position::factory()->create();
    Member::factory()->create(['position_id' => $position->id]);

    $this->actingAs(User::factory()->create())
        ->delete(route('admin.positions.destroy', $position))
        ->assertRedirect(route('admin.positions.index'))
        ->assertSessionHas('error', 'Ce titre/qualité est utilisé par des membres ou des présences existantes — désactivez-le plutôt que de le supprimer.');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=TitreQualiteLabelTest`
Expected: FAIL — old "Poste" wording still present.

- [ ] **Step 3: Apply the renames**

`resources/views/components/attendance-form.blade.php:52`:
```blade
            <label for="position_id" class="text-sm font-semibold text-[#12213D]">Titre/Qualité*</label>
```

`resources/views/components/layouts/admin.blade.php:73-76`:
```blade
                <a href="{{ route('admin.positions.index') }}" @click="close()"
                    class="cursor-pointer rounded-lg px-3 py-2 text-sm font-semibold {{ request()->routeIs('admin.positions.*') ? 'bg-navy text-white' : 'text-navy hover:bg-cream' }}">
                    Titres/Qualités
                </a>
```

`resources/views/admin/titles/create.blade.php:25`:
```blade
                <span class="text-sm font-semibold">Titres/Qualités liés</span>
```

`resources/views/admin/titles/index.blade.php:23`:
```blade
                        <th class="py-2 pr-4 font-semibold">Titres/Qualités liés</th>
```

`resources/views/admin/titles/edit.blade.php:25`:
```blade
                <span class="text-sm font-semibold">Titres/Qualités liés</span>
```

`resources/views/admin/positions/index.blade.php` — replace lines 1, 4, 7, 48:
```blade
<x-layouts.admin title="Titres/Qualités — Administration">
    <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)] md:p-8">
        <div class="flex items-center justify-between gap-3">
            <h1 class="font-display text-xl font-extrabold text-navy">Titres/Qualités</h1>
            <a href="{{ route('admin.positions.create') }}"
                class="cursor-pointer rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                Ajouter un titre/qualité
            </a>
        </div>
```
and (confirm dialog, line 48):
```blade
                                        onsubmit="return confirm('Supprimer définitivement ce titre/qualité ?');">
```

`resources/views/admin/positions/create.blade.php` — replace lines 1, 3, 14:
```blade
<x-layouts.admin title="Ajouter un titre/qualité — Administration">
    <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)] md:p-8">
        <h1 class="font-display text-xl font-extrabold text-navy">Ajouter un titre/qualité</h1>
```
and (submit button, line 14):
```blade
                Créer le titre/qualité
```

`resources/views/admin/members/edit.blade.php:34`:
```blade
                    <label for="position_id" class="text-sm font-semibold">Titre/Qualité</label>
```

`app/Http/Requests/StoreAttendanceRequest.php:57-65`:
```php
                if ($title->positions()->where('is_active', true)->exists()) {
                        $fail('Le titre/qualité est obligatoire pour cette organisation.');
                    }

                    return;
                }

                if (! $title->positions()->whereKey($value)->exists()) {
                    $fail('Le titre/qualité sélectionné ne correspond pas à l\'organisation choisie.');
                }
```

`app/Http/Requests/UpdateMemberRequest.php` — apply the identical message replacement (same two lines, same text) as `StoreAttendanceRequest.php` above.

`app/Http/Controllers/Admin/PositionController.php:58-59`:
```php
            return redirect()->route('admin.positions.index')
                ->with('error', 'Ce titre/qualité est utilisé par des membres ou des présences existantes — désactivez-le plutôt que de le supprimer.');
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=TitreQualiteLabelTest`
Expected: PASS

Run: `php artisan test --compact`
Expected: full suite PASS.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/components/attendance-form.blade.php \
    resources/views/components/layouts/admin.blade.php \
    resources/views/admin/titles/create.blade.php \
    resources/views/admin/titles/index.blade.php \
    resources/views/admin/titles/edit.blade.php \
    resources/views/admin/positions/index.blade.php \
    resources/views/admin/positions/create.blade.php \
    resources/views/admin/members/edit.blade.php \
    app/Http/Requests/StoreAttendanceRequest.php \
    app/Http/Requests/UpdateMemberRequest.php \
    app/Http/Controllers/Admin/PositionController.php \
    tests/Feature/Admin/TitreQualiteLabelTest.php
git commit -m "Rename Poste label to Titre/Qualité across the UI"
```

---

### Task 3: Add the `invited_by` field to attendances

**Files:**
- Create: `database/migrations/2026_07_16_120000_add_invited_by_to_attendances_table.php`
- Modify: `app/Models/Attendance.php:17-20` (fillable)
- Modify: `app/Http/Requests/StoreAttendanceRequest.php:22-30` (rules)
- Test: `tests/Feature/AttendanceFormTest.php` (append new tests)

**Interfaces:**
- Produces: `attendances.invited_by` (nullable string column), validated request field `invited_by`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/AttendanceFormTest.php`:

```php
it('stores the invited_by name when submitted for a guest check-in', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);
    $invite = Title::where('name', 'Invité')->sole();

    $this->post(route('attendance.store'), [
        'title_id' => $invite->id,
        'invited_by' => 'Jean Membre',
        'name' => 'Awa Bello',
        'club' => 'RC Porto-Novo',
        'phone' => '+229 91 00 00 00',
        'email' => 'awa.bello@example.com',
    ])->assertRedirect(route('attendance.show'));

    expect(Attendance::first()->invited_by)->toBe('Jean Membre');
});

it('allows omitting invited_by', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);
    $invite = Title::where('name', 'Invité')->sole();

    $this->post(route('attendance.store'), [
        'title_id' => $invite->id,
        'name' => 'Awa Bello',
        'club' => 'RC Porto-Novo',
        'phone' => '+229 91 00 00 00',
        'email' => 'awa.bello@example.com',
    ])->assertRedirect(route('attendance.show'))
        ->assertSessionDoesntHaveErrors();

    expect(Attendance::first()->invited_by)->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter="stores the invited_by name"`
Expected: FAIL — `invited_by` column does not exist yet (query exception) or the value is silently dropped (not in `$fillable`).

- [ ] **Step 3: Create the migration**

`database/migrations/2026_07_16_120000_add_invited_by_to_attendances_table.php`:
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
            $table->string('invited_by')->nullable()->after('position_id');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn('invited_by');
        });
    }
};
```

- [ ] **Step 4: Add `invited_by` to the model and validation rules**

`app/Models/Attendance.php:17-20`:
```php
    protected $fillable = [
        'meeting_session_id', 'member_id', 'title_id', 'position_id', 'invited_by', 'name', 'club', 'phone',
        'classification', 'email', 'present', 'is_late',
    ];
```

`app/Http/Requests/StoreAttendanceRequest.php:22-30`:
```php
        return [
            'title_id' => ['required', 'integer', 'exists:titles,id'],
            'position_id' => ['nullable', 'integer', 'exists:positions,id', $this->positionBelongsToTitle()],
            'invited_by' => ['nullable', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'club' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'classification' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
        ];
```

- [ ] **Step 5: Run migrations and tests to verify they pass**

Run: `php artisan test --compact --filter=AttendanceFormTest`
Expected: PASS (Pest's `RefreshDatabase` re-runs all migrations, including the new one, automatically).

Run: `php artisan test --compact`
Expected: full suite PASS.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_07_16_120000_add_invited_by_to_attendances_table.php \
    app/Models/Attendance.php \
    app/Http/Requests/StoreAttendanceRequest.php \
    tests/Feature/AttendanceFormTest.php
git commit -m "Add nullable invited_by column to attendances"
```

---

### Task 4: Exclude "Invité" from the admin Organisation CRUD

**Files:**
- Modify: `app/Models/Title.php:12-17` (add `GUEST_NAME` constant)
- Modify: `app/Http/Controllers/Admin/TitleController.php` (index query + guards on edit/update/toggleActive/destroy)
- Test: `tests/Feature/Admin/TitleManagementTest.php` (append new tests)

**Interfaces:**
- Produces: `Title::GUEST_NAME` (string constant, value `'Invité'`) — consumed by Task 6's `AttendanceFormController`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Admin/TitleManagementTest.php`:

```php
it('excludes the Invité title from the admin listing', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.titles.index'))
        ->assertOk()
        ->assertDontSee('Invité');
});

it('returns 404 when trying to view the edit form for the Invité title', function () {
    $invite = Title::where('name', 'Invité')->sole();

    $this->actingAs(User::factory()->create())
        ->get(route('admin.titles.edit', $invite))
        ->assertNotFound();
});

it('returns 404 when trying to update the Invité title', function () {
    $invite = Title::where('name', 'Invité')->sole();

    $this->actingAs(User::factory()->create())
        ->put(route('admin.titles.update', $invite), [
            'name' => 'Invité',
            'category' => AttendanceCategory::Guests->value,
        ])->assertNotFound();
});

it('returns 404 when trying to toggle the Invité titles active state', function () {
    $invite = Title::where('name', 'Invité')->sole();

    $this->actingAs(User::factory()->create())
        ->patch(route('admin.titles.toggle-active', $invite))
        ->assertNotFound();
});

it('returns 404 when trying to delete the Invité title', function () {
    $invite = Title::where('name', 'Invité')->sole();

    $this->actingAs(User::factory()->create())
        ->delete(route('admin.titles.destroy', $invite))
        ->assertNotFound();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=TitleManagementTest`
Expected: FAIL — "Invité" still appears in the listing, and edit/update/toggle/destroy return redirects instead of 404.

- [ ] **Step 3: Add the constant and the controller guards**

`app/Models/Title.php:12-17`:
```php
class Title extends Model
{
    /** @use HasFactory<TitleFactory> */
    use HasFactory;

    public const GUEST_NAME = 'Invité';

    protected $fillable = ['name', 'category', 'is_active'];
```

`app/Http/Controllers/Admin/TitleController.php` — update `index()` and add guards to `edit()`, `update()`, `toggleActive()`, `destroy()`:
```php
    public function index(): View
    {
        return view('admin.titles.index', [
            'titles' => Title::withCount('positions')
                ->where('name', '!=', Title::GUEST_NAME)
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.titles.create', [
            'positions' => Position::active()->orderBy('name')->get(),
        ]);
    }

    public function store(StoreTitleRequest $request): RedirectResponse
    {
        $title = Title::create($request->safe()->only(['name', 'category']));
        $title->positions()->sync($request->input('position_ids', []));

        return redirect()->route('admin.titles.index');
    }

    public function edit(Title $title): View
    {
        abort_if($title->name === Title::GUEST_NAME, 404);

        $linkedPositionIds = $title->positions()->pluck('positions.id')->all();

        return view('admin.titles.edit', [
            'title' => $title,
            'positions' => Position::query()
                ->where('is_active', true)
                ->orWhereIn('id', $linkedPositionIds)
                ->orderBy('name')
                ->get(),
            'linkedPositionIds' => $linkedPositionIds,
        ]);
    }

    public function update(UpdateTitleRequest $request, Title $title): RedirectResponse
    {
        abort_if($title->name === Title::GUEST_NAME, 404);

        $title->update($request->safe()->only(['name', 'category']));
        $title->positions()->sync($request->input('position_ids', []));

        return redirect()->route('admin.titles.index');
    }

    public function toggleActive(Title $title): RedirectResponse
    {
        abort_if($title->name === Title::GUEST_NAME, 404);

        $title->update(['is_active' => ! $title->is_active]);

        return redirect()->route('admin.titles.index');
    }

    public function destroy(Title $title): RedirectResponse
    {
        abort_if($title->name === Title::GUEST_NAME, 404);

        try {
            $title->delete();
        } catch (QueryException) {
            return redirect()->route('admin.titles.index')
                ->with('error', 'Cette organisation est utilisée par des membres ou des présences existantes — désactivez-la plutôt que de la supprimer.');
        }

        return redirect()->route('admin.titles.index');
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=TitleManagementTest`
Expected: PASS

Run: `php artisan test --compact`
Expected: full suite PASS.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/Title.php app/Http/Controllers/Admin/TitleController.php tests/Feature/Admin/TitleManagementTest.php
git commit -m "Hide the seeded Invité title from the admin Organisation CRUD"
```

---

### Task 5: Add the `CheckinSetting` global toggle

**Files:**
- Create: `database/migrations/2026_07_16_130000_create_checkin_settings_table.php`
- Create: `app/Models/CheckinSetting.php`
- Create: `app/Http/Requests/UpdateCheckinSettingRequest.php`
- Create: `app/Http/Controllers/Admin/CheckinSettingController.php`
- Create: `resources/views/admin/checkin-settings/edit.blade.php`
- Modify: `routes/web.php` (add routes + import)
- Modify: `resources/views/components/layouts/admin.blade.php` (add nav link)
- Test: `tests/Feature/CheckinSettingTest.php` (new, model-level)
- Test: `tests/Feature/Admin/CheckinSettingManagementTest.php` (new, controller-level)

**Interfaces:**
- Produces: `CheckinSetting::current(): ?CheckinSetting`, `CheckinSetting::guestOptionEnabled(): bool` — consumed by Task 6's `AttendanceFormController`.
- Produces: routes `admin.checkin-settings.edit` (GET), `admin.checkin-settings.update` (PUT).

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/CheckinSettingTest.php`:
```php
<?php

use App\Models\CheckinSetting;

it('defaults guestOptionEnabled to true when no row exists', function () {
    expect(CheckinSetting::current())->toBeNull()
        ->and(CheckinSetting::guestOptionEnabled())->toBeTrue();
});

it('reflects the stored value once a row exists', function () {
    CheckinSetting::create(['show_guest_option' => false]);

    expect(CheckinSetting::guestOptionEnabled())->toBeFalse();
});
```

Create `tests/Feature/Admin/CheckinSettingManagementTest.php`:
```php
<?php

use App\Models\CheckinSetting;
use App\Models\User;

it('redirects guests to login on edit', function () {
    $this->get(route('admin.checkin-settings.edit'))->assertRedirect(route('admin.login'));
});

it('redirects guests to login on update', function () {
    $this->put(route('admin.checkin-settings.update'), [])->assertRedirect(route('admin.login'));
});

it('shows the guest option checked by default when no settings are saved yet', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.checkin-settings.edit'))
        ->assertOk()
        ->assertSee('checked', false);
});

it('creates the checkin settings row disabled when the checkbox is left unchecked', function () {
    $this->actingAs(User::factory()->create())
        ->put(route('admin.checkin-settings.update'), [])
        ->assertRedirect(route('admin.checkin-settings.edit'));

    expect(CheckinSetting::current()->show_guest_option)->toBeFalse();
});

it('enables the guest option when the checkbox is submitted checked', function () {
    CheckinSetting::create(['show_guest_option' => false]);

    $this->actingAs(User::factory()->create())
        ->put(route('admin.checkin-settings.update'), ['show_guest_option' => '1'])
        ->assertRedirect(route('admin.checkin-settings.edit'));

    expect(CheckinSetting::current()->show_guest_option)->toBeTrue();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=CheckinSetting`
Expected: FAIL — `App\Models\CheckinSetting` and the `admin.checkin-settings.*` routes don't exist yet.

- [ ] **Step 3: Create the migration**

`database/migrations/2026_07_16_130000_create_checkin_settings_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkin_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('show_guest_option')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkin_settings');
    }
};
```

- [ ] **Step 4: Create the model**

`app/Models/CheckinSetting.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CheckinSetting extends Model
{
    protected $fillable = ['show_guest_option'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['show_guest_option' => 'boolean'];
    }

    public static function current(): ?self
    {
        return static::query()->first();
    }

    public static function guestOptionEnabled(): bool
    {
        return static::current()?->show_guest_option ?? true;
    }
}
```

- [ ] **Step 5: Create the form request**

`app/Http/Requests/UpdateCheckinSettingRequest.php`:
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
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'show_guest_option' => ['boolean'],
        ];
    }
}
```

- [ ] **Step 6: Create the controller**

`app/Http/Controllers/Admin/CheckinSettingController.php`:
```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateCheckinSettingRequest;
use App\Models\CheckinSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class CheckinSettingController extends Controller
{
    public function edit(): View
    {
        return view('admin.checkin-settings.edit', [
            'checkinSetting' => CheckinSetting::current(),
        ]);
    }

    public function update(UpdateCheckinSettingRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $checkinSetting = CheckinSetting::current();

        if ($checkinSetting !== null) {
            $checkinSetting->update($data);
        } else {
            CheckinSetting::create($data);
        }

        return redirect()->route('admin.checkin-settings.edit')->with('status', 'Paramètres enregistrés.');
    }
}
```

- [ ] **Step 7: Create the view**

`resources/views/admin/checkin-settings/edit.blade.php`:
```blade
<x-layouts.admin title="Paramètres du formulaire — Administration">
    <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)] md:p-8">
        <h1 class="font-display text-xl font-extrabold text-navy">Paramètres du formulaire</h1>
        <p class="mt-1 text-sm text-muted">
            Contrôlez les options proposées sur le formulaire public de présence.
        </p>

        @if (session('status'))
            <div class="mt-4 rounded-lg bg-success-bg px-4 py-3 text-sm text-success">
                {{ session('status') }}
            </div>
        @endif

        <form method="POST" action="{{ route('admin.checkin-settings.update') }}" class="mt-4 flex max-w-md flex-col gap-3">
            @csrf
            @method('PUT')
            <label class="flex items-center gap-2 text-sm font-semibold">
                <input type="checkbox" name="show_guest_option" value="1"
                    @checked(old('show_guest_option', $checkinSetting?->show_guest_option ?? true))>
                Afficher l'option « Invité » sur le formulaire de présence
            </label>
            <button type="submit"
                class="mt-2 cursor-pointer self-start rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                Enregistrer
            </button>
        </form>
    </div>
</x-layouts.admin>
```

- [ ] **Step 8: Add routes and nav link**

`routes/web.php` — add the import next to the other `Admin\*Controller` imports:
```php
use App\Http\Controllers\Admin\CheckinSettingController;
```
and add the routes right after the `mail-settings` routes (inside the `auth` middleware group):
```php
        Route::get('checkin-settings', [CheckinSettingController::class, 'edit'])->name('checkin-settings.edit');
        Route::put('checkin-settings', [CheckinSettingController::class, 'update'])->name('checkin-settings.update');
```

`resources/views/components/layouts/admin.blade.php` — add a nav link right after the "Paramètres mail" link (after line 64):
```blade
                <a href="{{ route('admin.checkin-settings.edit') }}" @click="close()"
                    class="cursor-pointer rounded-lg px-3 py-2 text-sm font-semibold {{ request()->routeIs('admin.checkin-settings.*') ? 'bg-navy text-white' : 'text-navy hover:bg-cream' }}">
                    Paramètres formulaire
                </a>
```

- [ ] **Step 9: Run tests to verify they pass**

Run: `php artisan test --compact --filter=CheckinSetting`
Expected: PASS

Run: `php artisan test --compact`
Expected: full suite PASS.

- [ ] **Step 10: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_07_16_130000_create_checkin_settings_table.php \
    app/Models/CheckinSetting.php \
    app/Http/Requests/UpdateCheckinSettingRequest.php \
    app/Http/Controllers/Admin/CheckinSettingController.php \
    resources/views/admin/checkin-settings/edit.blade.php \
    routes/web.php \
    resources/views/components/layouts/admin.blade.php \
    tests/Feature/CheckinSettingTest.php \
    tests/Feature/Admin/CheckinSettingManagementTest.php
git commit -m "Add admin toggle for showing the Invité option on check-in"
```

---

### Task 6: Public check-in form — conditional "Invité" option and "Invité par" field

**Files:**
- Modify: `app/Http/Controllers/AttendanceFormController.php` (extract shared `attendanceFormData()`, exclude/append Invité)
- Modify: `resources/views/components/attendance-form.blade.php` (new prop, Alpine `isGuest` getter, "Invité par" field)
- Test: `tests/Feature/AttendanceFormTest.php` / `tests/Feature/AttendanceMemberCheckInTest.php` (append new tests)

**Interfaces:**
- Consumes: `Title::GUEST_NAME` (Task 4), `CheckinSetting::guestOptionEnabled()` (Task 5).
- Produces: `attendance-form` component prop `guestTitleId` (nullable int) — no other task depends on this.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/AttendanceFormTest.php`:
```php
it('offers the Invité option when the guest option is enabled', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);

    $this->get(route('attendance.show'))
        ->assertOk()
        ->assertSee('Invité')
        ->assertSee('Invité par')
        ->assertSee('name="invited_by"', false);
});

it('does not offer the Invité option when the guest option is disabled', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);
    \App\Models\CheckinSetting::create(['show_guest_option' => false]);

    $this->get(route('attendance.show'))
        ->assertOk()
        ->assertDontSee('Invité');
});

it('still offers a returning guest members Invité title even when the guest option is disabled', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);
    \App\Models\CheckinSetting::create(['show_guest_option' => false]);
    $invite = Title::where('name', 'Invité')->sole();

    Member::factory()->create(['email' => 'ancien.invite@example.com', 'title_id' => $invite->id]);

    $this->post(route('attendance.lookup'), ['email' => 'ancien.invite@example.com'])
        ->assertOk()
        ->assertSee('Invité');
});
```

(Add `use App\Models\Member;` to the top of `tests/Feature/AttendanceFormTest.php` if not already imported — check the existing `use` block first.)

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter="Invité option"`
Expected: FAIL — "Invité" is currently listed regardless of any setting (no exclusion logic yet), and the "Invité par" field doesn't exist yet, so the first test fails on the missing field while the disable-path tests aren't wired up at all.

- [ ] **Step 3: Refactor the controller**

Replace the body of `app/Http/Controllers/AttendanceFormController.php` (`show()`, `lookup()`) to use a shared private method:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\LookupAttendanceEmailRequest;
use App\Http\Requests\StoreAttendanceRequest;
use App\Models\Attendance;
use App\Models\CheckinSetting;
use App\Models\MeetingSession;
use App\Models\Member;
use App\Models\Title;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AttendanceFormController extends Controller
{
    public function show(): View
    {
        // `name` only exists in old-input after a failed step-2 (store) submit,
        // never after a failed step-1 (lookup) submit — use it to tell the two apart.
        $email = session()->hasOldInput('name') ? old('email') : null;
        $member = $email !== null ? Member::firstWhere('email', Member::normalizeEmail($email)) : null;

        return view('attendance.show', [
            'meetingSession' => MeetingSession::active(),
            'email' => $email,
            'member' => $member,
            ...$this->attendanceFormData($member),
        ]);
    }

    public function lookup(LookupAttendanceEmailRequest $request): View
    {
        $meetingSession = MeetingSession::active();

        abort_if($meetingSession === null, 404);

        $email = Member::normalizeEmail($request->validated('email'));
        $member = Member::firstWhere('email', $email);

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

        if ($existingMember !== null) {
            $alreadyCheckedIn = Attendance::where('member_id', $existingMember->id)
                ->where('meeting_session_id', $meetingSession->id)
                ->exists();

            if ($alreadyCheckedIn) {
                return redirect()
                    ->route('attendance.show')
                    ->with('attendanceAlreadyCheckedIn', true);
            }
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

    /**
     * @return array{titles: \Illuminate\Support\Collection<int, Title>, guestTitleId: ?int}
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
            ->orderBy('name')
            ->get();

        $guestTitle = Title::with('positions')->firstWhere('name', Title::GUEST_NAME);

        if ($guestTitle !== null && $guestTitle->id !== $member?->title_id && CheckinSetting::guestOptionEnabled()) {
            $titles->push($guestTitle);
        }

        return [
            'titles' => $titles,
            'guestTitleId' => $guestTitle?->id,
        ];
    }
}
```

- [ ] **Step 4: Update the Blade component**

`resources/views/components/attendance-form.blade.php` — update the `@props` line and the `x-data`/markup block:

```blade
@props(['late' => false, 'email', 'member' => null, 'titles', 'guestTitleId' => null])
```

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
            get availablePositions() { return this.positionsByTitle[this.titleId] ?? [] },
            get isGuest() { return this.titleId !== '' && this.titleId == '{{ $guestTitleId }}' },
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

        <div class="flex flex-col gap-1.5" x-show="isGuest" x-cloak>
            <label for="invited_by" class="text-sm font-semibold text-[#12213D]">Invité par</label>
            <input type="text" id="invited_by" name="invited_by" value="{{ old('invited_by') }}"
                class="rounded-lg border border-[#DEDAD0] px-3 py-2 text-sm">
        </div>
    </div>
```

(Only the `@props` line, the `title_id` label, the `position_id` label, the new `get isGuest()` getter, and the new `invited_by` block are changes — the label renames were already applied in Tasks 1–2, keep them as-is here.)

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact --filter="Invité option"`
Expected: PASS

Run: `php artisan test --compact --filter=AttendanceFormTest`
Expected: PASS

Run: `php artisan test --compact --filter=AttendanceMemberCheckInTest`
Expected: PASS (no regressions from the controller refactor — the existing "does not offer an inactive title" / "still shows a returning members inactive title" tests must still pass unchanged).

Run: `php artisan test --compact`
Expected: full suite PASS.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/AttendanceFormController.php \
    resources/views/components/attendance-form.blade.php \
    tests/Feature/AttendanceFormTest.php
git commit -m "Show an Invité option and invited-by field on the check-in form"
```

---

## Self-Review Notes

- **Spec coverage:** Part 1 (Titre→Organisation, Poste→Titre/Qualité) → Tasks 1–2. Part 2 (guest flow: remove Invité from admin CRUD, global toggle, conditional field) → Tasks 3–6. Part 3 (inactive poste/titre hidden from client form) → already implemented, no task needed, confirmed via existing `AttendanceMemberCheckInTest` cases that remain green throughout.
- **Placeholder scan:** no TBD/TODO; every step has literal file content.
- **Type consistency:** `Title::GUEST_NAME` (Task 4) is referenced identically in Task 6's `AttendanceFormController`. `CheckinSetting::guestOptionEnabled()` (Task 5) is referenced identically in Task 6. `attendance-form.blade.php`'s new `guestTitleId` prop matches the array key returned by `attendanceFormData()`.
- **Task ordering:** Tasks 1–2 (pure renames) are independent and could run in either order; Task 3 (invited_by column) is independent of 4–5; Task 4 (`GUEST_NAME` constant) must land before Task 6 (consumes it); Task 5 (`CheckinSetting`) must land before Task 6 (consumes it). Recommended order: 1, 2, 3, 4, 5, 6.
