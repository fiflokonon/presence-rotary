# Member model + email-based check-in Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a person entering attendance type their email first, get pre-filled with their known info if they've checked in before, and confirm/submit — backed by a new `Member` model that's the canonical "current info" record for each person, with admin visibility into members and their attendance history.

**Architecture:** A new `Member` model/table stores each person's latest known info, keyed by a unique, normalized email. `Attendance` gains a nullable `member_id` FK but keeps every existing column as-is — each `Attendance` row stays a frozen snapshot of that person's info *at that meeting*, so history is never lost even as `Member` gets overwritten on later check-ins. The public check-in form becomes a two-step, no-JS Blade flow (email → confirm/prefill). A one-off data migration backfills `Member` rows from existing `Attendance` history. An admin CRUD (list/search/detail/edit, no create/delete) exposes members and lets an admin see a person's classification/club change over time.

**Tech Stack:** PHP 8.4, Laravel 13, Pest v4, Blade + Tailwind v4 + Alpine.js (no Livewire/Inertia), SQLite in-memory for tests.

## Global Constraints

- Follow existing code conventions in this app (check sibling files before writing anything new) — see `CLAUDE.md`.
- Use `php artisan make:` commands with `--no-interaction` to scaffold new files, then edit the generated files.
- Do not add or change Composer/NPM dependencies.
- Every controller method needs explicit return types; every FormRequest needs `authorize(): bool` and a typed `rules(): array`.
- After any PHP file change, run `vendor/bin/pint --dirty --format agent` before committing.
- Run tests with `php artisan test --compact` or `php artisan test --compact --filter=<name>`. All `tests/Feature/**` tests automatically get `RefreshDatabase` (configured once in `tests/Pest.php`) — never add the trait manually.
- Do not delete or weaken any existing test without approval; when existing behavior changes, update the existing test to match, don't just delete it.
- All user-facing copy is French, matching the existing wording style (e.g. "Nom et prénoms", "Votre club").
- Do not create documentation files beyond what this plan specifies.

---

### Task 1: `Member` model, schema, and its link to `Attendance`

**Files:**
- Create: `database/migrations/<timestamp>_create_members_table.php`
- Create: `app/Models/Member.php`
- Create: `database/factories/MemberFactory.php`
- Create: `database/migrations/<timestamp>_add_member_id_to_attendances_table.php`
- Modify: `app/Models/Attendance.php`
- Test: `tests/Feature/MemberModelTest.php`

**Interfaces:**
- Produces: `App\Models\Member` — fillable `title, name, club, phone, classification, email`; `title` cast to `App\Enums\AttendanceTitle`; `Member::normalizeEmail(string $email): string` (trim + lowercase); `Member::attendances(): HasMany`.
- Produces: `App\Models\Attendance::member(): BelongsTo` and `member_id` added to `Attendance::$fillable`.
- Produces: `Database\Factories\MemberFactory` — default state matching `AttendanceFactory`'s conventions (`club` defaults to `'RC Cotonou Ife'`, unique fake email).

- [ ] **Step 1: Scaffold the model, migration, and factory together**

Run: `php artisan make:model Member -m -f --no-interaction`

Expected output confirms creation of `app/Models/Member.php`, a `database/migrations/..._create_members_table.php` file, and `database/factories/MemberFactory.php`.

- [ ] **Step 2: Write the members table migration**

Edit the generated `database/migrations/..._create_members_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('name');
            $table->string('club');
            $table->string('phone');
            $table->string('classification')->nullable();
            $table->string('email')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};
```

- [ ] **Step 3: Write the `Member` model**

Replace the contents of `app/Models/Member.php`:

```php
<?php

namespace App\Models;

use App\Enums\AttendanceTitle;
use Database\Factories\MemberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Member extends Model
{
    /** @use HasFactory<MemberFactory> */
    use HasFactory;

    protected $fillable = ['title', 'name', 'club', 'phone', 'classification', 'email'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'title' => AttendanceTitle::class,
        ];
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

- [ ] **Step 4: Write the `MemberFactory`**

Replace the contents of `database/factories/MemberFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\AttendanceTitle;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Member>
 */
class MemberFactory extends Factory
{
    protected $model = Member::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->randomElement(AttendanceTitle::cases()),
            'name' => fake()->name(),
            'club' => 'RC Cotonou Ife',
            'phone' => fake()->phoneNumber(),
            'classification' => null,
            'email' => fake()->unique()->safeEmail(),
        ];
    }
}
```

- [ ] **Step 5: Scaffold the `member_id` column migration**

Run: `php artisan make:migration add_member_id_to_attendances_table --no-interaction`

- [ ] **Step 6: Write the `member_id` column migration**

Edit the generated `database/migrations/..._add_member_id_to_attendances_table.php`:

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
            $table->foreignId('member_id')->nullable()->after('meeting_session_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('member_id');
        });
    }
};
```

- [ ] **Step 7: Update the `Attendance` model**

In `app/Models/Attendance.php`, add `member_id` to `$fillable` and add the `member()` relation:

```php
protected $fillable = [
    'meeting_session_id', 'member_id', 'title', 'name', 'club', 'phone',
    'classification', 'email', 'present', 'is_late',
];
```

```php
public function member(): BelongsTo
{
    return $this->belongsTo(Member::class);
}
```

(`BelongsTo` is already imported in this file for `meetingSession()`.)

- [ ] **Step 8: Write the failing test**

Create `tests/Feature/MemberModelTest.php`:

```php
<?php

use App\Models\Attendance;
use App\Models\Member;
use Illuminate\Database\QueryException;

it('creates a member with a unique email', function () {
    Member::factory()->create(['email' => 'jean@example.com']);

    expect(Member::where('email', 'jean@example.com')->count())->toBe(1);
});

it('rejects a duplicate member email at the database level', function () {
    Member::factory()->create(['email' => 'jean@example.com']);

    expect(fn () => Member::factory()->create(['email' => 'jean@example.com']))
        ->toThrow(QueryException::class);
});

it('links an attendance to a member', function () {
    $member = Member::factory()->create();
    $attendance = Attendance::factory()->create(['member_id' => $member->id]);

    expect($attendance->member->is($member))->toBeTrue();
});

it('normalizes an email by trimming and lowercasing it', function () {
    expect(Member::normalizeEmail('  JEAN@Example.com  '))->toBe('jean@example.com');
});
```

- [ ] **Step 9: Run the migrations and the test**

Run: `php artisan test --compact --filter=MemberModelTest`

Expected: all 4 tests PASS (migrations run automatically via `RefreshDatabase`).

- [ ] **Step 10: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/Member.php app/Models/Attendance.php database/factories/MemberFactory.php database/migrations tests/Feature/MemberModelTest.php
git commit -m "Add Member model linked to Attendance via member_id"
```

---

### Task 2: Backfill `Member` rows from existing `Attendance` history

**Files:**
- Create: `database/migrations/<timestamp>_backfill_members_from_attendances.php`
- Test: `tests/Feature/BackfillMembersFromAttendancesTest.php`

**Interfaces:**
- Consumes: `members` table and `attendances.member_id` from Task 1.
- Produces: no new PHP interface — this migration only backfills data. Later tasks don't depend on anything from this one.

- [ ] **Step 1: Scaffold the migration**

Run: `php artisan make:migration backfill_members_from_attendances --no-interaction`

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/BackfillMembersFromAttendancesTest.php`. It locates the migration file by name (its timestamp is generated at scaffold time, so don't hardcode it) and invokes `up()` directly against seeded data:

```php
<?php

use App\Models\Attendance;
use App\Models\MeetingSession;
use App\Models\Member;

it('backfills members from existing attendance emails, using the most recent row per email', function () {
    $migrationPath = glob(database_path('migrations/*_backfill_members_from_attendances.php'))[0];
    $migration = include $migrationPath;

    $olderSession = MeetingSession::factory()->create(['date' => '2026-01-10']);
    $recentSession = MeetingSession::factory()->create(['date' => '2026-02-10']);

    $olderAttendance = Attendance::factory()->create([
        'meeting_session_id' => $olderSession->id,
        'email' => 'jean@example.com',
        'classification' => 'Ancienne classification',
    ]);

    $recentAttendance = Attendance::factory()->create([
        'meeting_session_id' => $recentSession->id,
        'email' => 'JEAN@example.com',
        'classification' => 'Classification actuelle',
    ]);

    $blankEmailAttendance = Attendance::factory()->create(['email' => null]);

    $migration->up();

    $member = Member::where('email', 'jean@example.com')->sole();

    expect($member->classification)->toBe('Classification actuelle')
        ->and($olderAttendance->fresh()->member_id)->toBe($member->id)
        ->and($recentAttendance->fresh()->member_id)->toBe($member->id)
        ->and($blankEmailAttendance->fresh()->member_id)->toBeNull()
        ->and(Member::count())->toBe(1);
});
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `php artisan test --compact --filter=BackfillMembersFromAttendancesTest`

Expected: FAIL — `$migration->up()` does nothing yet (empty migration body), so `Member::where('email', 'jean@example.com')->sole()` throws (no matching row).

- [ ] **Step 4: Write the migration**

Edit the generated `database/migrations/..._backfill_members_from_attendances.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('attendances')
            ->join('meeting_sessions', 'attendances.meeting_session_id', '=', 'meeting_sessions.id')
            ->whereNotNull('attendances.email')
            ->where('attendances.email', '!=', '')
            ->orderBy('meeting_sessions.date')
            ->orderBy('meeting_sessions.time')
            ->select(
                'attendances.id',
                'attendances.title',
                'attendances.name',
                'attendances.club',
                'attendances.phone',
                'attendances.classification',
                'attendances.email',
            )
            ->get()
            ->groupBy(fn ($row) => Str::lower(trim($row->email)));

        foreach ($rows as $normalizedEmail => $group) {
            // Rows are ordered oldest-to-newest per session date, so the
            // last one in each group is that email's most recent attendance.
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
};
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --compact --filter=BackfillMembersFromAttendancesTest`

Expected: PASS.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations tests/Feature/BackfillMembersFromAttendancesTest.php
git commit -m "Backfill Member rows from existing Attendance email history"
```

---

### Task 3: Two-step public check-in flow (email lookup, pre-fill, duplicate guard)

**Files:**
- Create: `app/Http/Requests/LookupAttendanceEmailRequest.php`
- Modify: `app/Http/Requests/StoreAttendanceRequest.php`
- Modify: `app/Http/Controllers/AttendanceFormController.php`
- Modify: `routes/web.php`
- Create: `resources/views/components/attendance-email-form.blade.php`
- Modify: `resources/views/components/attendance-form.blade.php`
- Modify: `resources/views/attendance/show.blade.php`
- Modify: `tests/Feature/AttendanceFormTest.php`
- Create: `tests/Feature/AttendanceMemberCheckInTest.php`

**Interfaces:**
- Consumes: `Member::normalizeEmail()`, `Member` model, `Attendance::member_id` from Task 1.
- Produces: `attendance.lookup` route (`POST /check-in`); `<x-attendance-email-form>` component (no props); `<x-attendance-form>` component now requires `email` prop and accepts optional `member` prop.

- [ ] **Step 1: Update the existing form test for the new default (step-1) view**

In `tests/Feature/AttendanceFormTest.php`, replace the `'shows the form when the active session is open'` test:

```php
it('shows the email step by default when the active session is open', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);

    $this->get(route('attendance.show'))
        ->assertOk()
        ->assertSee('Adresse e-mail')
        ->assertSee('Continuer')
        ->assertDontSee('Numéro de téléphone')
        ->assertDontSee('Aucune séance en cours')
        ->assertDontSee('La séance est clôturée');
});
```

Also update the two `attendance.store` tests to include `'email'` in their payloads, and the validation test to expect `'email'` as a required error too. The full updated file:

```php
<?php

use App\Enums\AttendanceTitle;
use App\Models\Attendance;
use App\Models\MeetingSession;

it('shows an informational screen when no session is active', function () {
    $this->get(route('attendance.show'))
        ->assertOk()
        ->assertSee('Aucune séance en cours');
});

it('shows the email step by default when the active session is open', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);

    $this->get(route('attendance.show'))
        ->assertOk()
        ->assertSee('Adresse e-mail')
        ->assertSee('Continuer')
        ->assertDontSee('Numéro de téléphone')
        ->assertDontSee('Aucune séance en cours')
        ->assertDontSee('La séance est clôturée');
});

it('shows the closed-door screen when the active session is closed', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => false]);

    $this->get(route('attendance.show'))
        ->assertOk()
        ->assertSee('La séance est clôturée');
});

it('records an on-time attendance when the session is open', function () {
    $meetingSession = MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);

    $this->post(route('attendance.store'), [
        'title' => AttendanceTitle::Rotarien->value,
        'name' => 'Jean Dupont',
        'club' => 'RC Cotonou Ife',
        'phone' => '+229 90 00 00 00',
        'email' => 'jean.dupont@example.com',
    ])->assertRedirect(route('attendance.show'));

    expect(Attendance::first())
        ->meeting_session_id->toBe($meetingSession->id)
        ->present->toBeTrue()
        ->is_late->toBeFalse();
});

it('records a late attendance when the session is closed', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => false]);

    $this->post(route('attendance.store'), [
        'title' => AttendanceTitle::Invite->value,
        'name' => 'Awa Bello',
        'club' => 'RC Porto-Novo',
        'phone' => '+229 91 00 00 00',
        'email' => 'awa.bello@example.com',
    ])->assertRedirect(route('attendance.show'));

    expect(Attendance::first())
        ->present->toBeTrue()
        ->is_late->toBeTrue();
});

it('rejects a submission missing required fields', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);

    $this->post(route('attendance.store'), ['name' => 'Jean Dupont'])
        ->assertSessionHasErrors(['title', 'club', 'phone', 'email']);

    expect(Attendance::count())->toBe(0);
});

it('shows the club logo on the attendance form page', function () {
    $this->get(route('attendance.show'))
        ->assertOk()
        ->assertSee('ife-logo.png', false);
});
```

- [ ] **Step 2: Write the new member-check-in tests**

Create `tests/Feature/AttendanceMemberCheckInTest.php`:

```php
<?php

use App\Enums\AttendanceTitle;
use App\Models\Attendance;
use App\Models\MeetingSession;
use App\Models\Member;

it('shows a blank confirmation form when the email is unknown', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);

    $this->post(route('attendance.lookup'), ['email' => 'inconnu@example.com'])
        ->assertOk()
        ->assertSee('Nom et prénoms')
        ->assertSee('inconnu@example.com');
});

it('shows a pre-filled confirmation form when the email matches a member', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);

    Member::factory()->create([
        'email' => 'jean@example.com',
        'name' => 'Jean Dupont',
        'club' => 'RC Cotonou Ife',
        'phone' => '+229 90 00 00 00',
    ]);

    $this->post(route('attendance.lookup'), ['email' => 'JEAN@example.com'])
        ->assertOk()
        ->assertSee('Jean Dupont')
        ->assertSee('+229 90 00 00 00');
});

it('rejects an invalid email at the lookup step', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);

    $this->post(route('attendance.lookup'), ['email' => 'not-an-email'])
        ->assertSessionHasErrors(['email']);
});

it('creates a member on first check-in and links the attendance to it', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);

    $this->post(route('attendance.store'), [
        'title' => AttendanceTitle::Rotarien->value,
        'name' => 'Jean Dupont',
        'club' => 'RC Cotonou Ife',
        'phone' => '+229 90 00 00 00',
        'email' => 'jean.dupont@example.com',
    ])->assertRedirect(route('attendance.show'));

    $member = Member::where('email', 'jean.dupont@example.com')->sole();

    expect(Attendance::first()->member_id)->toBe($member->id);
});

it('updates the existing member with newly submitted info on a later check-in', function () {
    $member = Member::factory()->create([
        'email' => 'jean.dupont@example.com',
        'classification' => 'Ancienne classification',
        'club' => 'RC Cotonou Ife',
    ]);

    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);

    $this->post(route('attendance.store'), [
        'title' => AttendanceTitle::Rotarien->value,
        'name' => $member->name,
        'club' => 'RC Porto-Novo',
        'phone' => $member->phone,
        'classification' => 'Nouvelle classification',
        'email' => 'jean.dupont@example.com',
    ])->assertRedirect(route('attendance.show'));

    expect($member->fresh())
        ->club->toBe('RC Porto-Novo')
        ->classification->toBe('Nouvelle classification');
});

it('rejects a second check-in for the same member on the same session', function () {
    $meetingSession = MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);
    $member = Member::factory()->create(['email' => 'jean.dupont@example.com']);

    Attendance::factory()->create([
        'meeting_session_id' => $meetingSession->id,
        'member_id' => $member->id,
        'email' => 'jean.dupont@example.com',
    ]);

    $this->post(route('attendance.store'), [
        'title' => AttendanceTitle::Rotarien->value,
        'name' => $member->name,
        'club' => $member->club,
        'phone' => $member->phone,
        'email' => 'jean.dupont@example.com',
    ])->assertRedirect(route('attendance.show'))
        ->assertSessionHas('attendanceAlreadyCheckedIn', true);

    expect(Attendance::count())->toBe(1);
});

it('re-shows the pre-filled confirmation form after a failed submission', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);

    Member::factory()->create([
        'email' => 'jean.dupont@example.com',
        'name' => 'Jean Dupont',
    ]);

    // 'title', 'club' and 'phone' omitted on purpose to trigger validation errors.
    $this->post(route('attendance.store'), [
        'name' => 'Jean Dupont',
        'email' => 'jean.dupont@example.com',
    ])->assertSessionHasErrors(['title', 'club', 'phone']);

    $this->get(route('attendance.show'))
        ->assertOk()
        ->assertSee('Jean Dupont')
        ->assertSee('jean.dupont@example.com')
        ->assertDontSee('Adresse e-mail*');
});
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=AttendanceFormTest`
Run: `php artisan test --compact --filter=AttendanceMemberCheckInTest`

Expected: both FAIL — `attendance.lookup` route doesn't exist yet, `show()` doesn't know about `$email`/`$member`, `store()` doesn't create `Member` rows or guard duplicates, `StoreAttendanceRequest` still allows blank email.

- [ ] **Step 4: Add the `LookupAttendanceEmailRequest`**

Run: `php artisan make:request LookupAttendanceEmailRequest --no-interaction`

Replace its contents:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LookupAttendanceEmailRequest extends FormRequest
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
            'email' => ['required', 'email', 'max:255'],
        ];
    }
}
```

- [ ] **Step 5: Make `email` required in `StoreAttendanceRequest`**

In `app/Http/Requests/StoreAttendanceRequest.php`, change:

```php
'email' => ['nullable', 'email', 'max:255'],
```

to:

```php
'email' => ['required', 'email', 'max:255'],
```

- [ ] **Step 6: Add the `attendance.lookup` route**

In `routes/web.php`, change:

```php
Route::get('/', [AttendanceFormController::class, 'show'])->name('attendance.show');
Route::post('/attendances', [AttendanceFormController::class, 'store'])->name('attendance.store');
```

to:

```php
Route::get('/', [AttendanceFormController::class, 'show'])->name('attendance.show');
Route::post('/check-in', [AttendanceFormController::class, 'lookup'])->name('attendance.lookup');
Route::post('/attendances', [AttendanceFormController::class, 'store'])->name('attendance.store');
```

- [ ] **Step 7: Rewrite `AttendanceFormController`**

Replace the full contents of `app/Http/Controllers/AttendanceFormController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\LookupAttendanceEmailRequest;
use App\Http\Requests\StoreAttendanceRequest;
use App\Models\Attendance;
use App\Models\MeetingSession;
use App\Models\Member;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AttendanceFormController extends Controller
{
    public function show(): View
    {
        // `name` only exists in old-input after a failed step-2 (store) submit,
        // never after a failed step-1 (lookup) submit — use it to tell the two apart.
        $email = session()->hasOldInput('name') ? old('email') : null;

        return view('attendance.show', [
            'meetingSession' => MeetingSession::active(),
            'email' => $email,
            'member' => $email !== null ? Member::firstWhere('email', Member::normalizeEmail($email)) : null,
        ]);
    }

    public function lookup(LookupAttendanceEmailRequest $request): View
    {
        $meetingSession = MeetingSession::active();

        abort_if($meetingSession === null, 404);

        $email = Member::normalizeEmail($request->validated('email'));

        return view('attendance.show', [
            'meetingSession' => $meetingSession,
            'email' => $email,
            'member' => Member::firstWhere('email', $email),
        ]);
    }

    public function store(StoreAttendanceRequest $request): RedirectResponse
    {
        $meetingSession = MeetingSession::active();

        abort_if($meetingSession === null, 404);

        $email = Member::normalizeEmail($request->validated('email'));

        $member = Member::updateOrCreate(
            ['email' => $email],
            $request->safe()->only(['title', 'name', 'club', 'phone', 'classification']),
        );

        $alreadyCheckedIn = Attendance::where('member_id', $member->id)
            ->where('meeting_session_id', $meetingSession->id)
            ->exists();

        if ($alreadyCheckedIn) {
            return redirect()
                ->route('attendance.show')
                ->with('attendanceAlreadyCheckedIn', true);
        }

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
}
```

- [ ] **Step 8: Add the step-1 email form component**

Create `resources/views/components/attendance-email-form.blade.php`:

```blade
<form method="POST" action="{{ route('attendance.lookup') }}" class="flex flex-col gap-4 px-6 pb-6 pt-4">
    @csrf

    @if ($errors->any())
        <div class="rounded-lg bg-[#FBEAEA] px-4 py-3 text-sm text-[#B23B3B]">
            Merci de saisir une adresse e-mail valide.
        </div>
    @endif

    <div class="flex flex-col gap-1.5">
        <label for="email" class="text-sm font-semibold text-[#12213D]">Adresse e-mail*</label>
        <input type="email" id="email" name="email" value="{{ old('email') }}" required
            class="rounded-lg border border-[#DEDAD0] px-3 py-2 text-sm">
    </div>

    <button type="submit"
        class="rounded-lg bg-[#12213D] px-4 py-2.5 text-sm font-bold text-white hover:bg-[#1c3559]">
        Continuer
    </button>
</form>
```

- [ ] **Step 9: Update the step-2 form component to accept `email`/`member` and lock the email field**

Replace the full contents of `resources/views/components/attendance-form.blade.php`:

```blade
@props(['late' => false, 'email', 'member' => null])

<form method="POST" action="{{ route('attendance.store') }}" class="flex flex-col gap-4 px-6 pb-6 pt-4">
    @csrf
    <input type="hidden" name="email" value="{{ $email }}">

    @if ($late)
        <div class="rounded-lg bg-[#FDF3E2] px-4 py-3 text-sm font-semibold text-[#C77700]">
            ⏱ Séance clôturée — cette réponse sera enregistrée comme présence en retard.
        </div>
    @endif

    @if ($errors->any())
        <div class="rounded-lg bg-[#FBEAEA] px-4 py-3 text-sm text-[#B23B3B]">
            * Merci de remplir les champs obligatoires.
        </div>
    @endif

    <div class="flex flex-col gap-1.5">
        <span class="text-sm font-semibold text-[#12213D]">Adresse e-mail</span>
        <p class="rounded-lg border border-[#DEDAD0] bg-[#F1EFEA] px-3 py-2 text-sm text-[#8A8474]">{{ $email }}</p>
        <a href="{{ route('attendance.show') }}" class="text-xs font-semibold text-[#12213D] underline">
            Changer d'adresse e-mail
        </a>
    </div>

    <div class="flex flex-col gap-1.5">
        <label for="title" class="text-sm font-semibold text-[#12213D]">Titre / Qualité*</label>
        <select id="title" name="title" required
            class="rounded-lg border border-[#DEDAD0] px-3 py-2 text-sm">
            <option value="">Sélectionnez…</option>
            @foreach (\App\Enums\AttendanceTitle::cases() as $titleOption)
                <option value="{{ $titleOption->value }}" @selected(old('title', $member?->title?->value) === $titleOption->value)>
                    {{ $titleOption->value }}
                </option>
            @endforeach
        </select>
    </div>

    <div class="flex flex-col gap-1.5">
        <label for="name" class="text-sm font-semibold text-[#12213D]">Nom et prénoms*</label>
        <input type="text" id="name" name="name" value="{{ old('name', $member?->name) }}" required
            class="rounded-lg border border-[#DEDAD0] px-3 py-2 text-sm">
    </div>

    <div class="flex flex-col gap-1.5">
        <label for="club" class="text-sm font-semibold text-[#12213D]">Votre club*</label>
        <input type="text" id="club" name="club" value="{{ old('club', $member?->club) }}" required
            class="rounded-lg border border-[#DEDAD0] px-3 py-2 text-sm">
    </div>

    <div class="flex flex-col gap-1.5">
        <label for="phone" class="text-sm font-semibold text-[#12213D]">Numéro de téléphone*</label>
        <input type="tel" id="phone" name="phone" value="{{ old('phone', $member?->phone) }}" required
            class="rounded-lg border border-[#DEDAD0] px-3 py-2 text-sm">
    </div>

    <div class="flex flex-col gap-1.5">
        <label for="classification" class="text-sm font-semibold text-[#12213D]">Classification</label>
        <input type="text" id="classification" name="classification" value="{{ old('classification', $member?->classification) }}"
            class="rounded-lg border border-[#DEDAD0] px-3 py-2 text-sm">
    </div>

    <button type="submit"
        class="rounded-lg bg-[#12213D] px-4 py-2.5 text-sm font-bold text-white hover:bg-[#1c3559]">
        Envoyer
    </button>
    <button type="reset" class="text-sm font-semibold text-[#C77700]">
        Effacer le formulaire
    </button>
</form>
```

- [ ] **Step 10: Branch `attendance/show.blade.php` between step 1, step 2, and the duplicate-check-in state**

Replace the full contents of `resources/views/attendance/show.blade.php`:

```blade
<x-layouts.app :title="'Liste de présence' . ($meetingSession ? ' — ' . $meetingSession->title : '')">
    <div class="mx-auto flex min-h-screen max-w-[420px] flex-col items-center justify-center px-4 py-10">
        <div class="w-full overflow-hidden rounded-xl bg-white shadow-[0_2px_10px_rgba(20,30,50,.06)]">
            <div class="flex flex-col items-center bg-[#12213D] px-6 pb-[18px] pt-[22px] text-center">
                <div class="inline-flex items-center justify-center rounded-xl bg-[linear-gradient(135deg,#17A8E5_0%,#0B73C5_55%,#0A5CA6_100%)] px-4 py-2 shadow-[0_8px_20px_rgba(10,92,166,.35)]">
                    <img src="{{ asset('assets/ife-logo.png') }}" alt="RC Cotonou Ife" class="h-12 w-auto object-contain">
                </div>
                <p class="mt-3 font-display text-lg font-extrabold text-white">RC Cotonou Ife</p>
                <p class="mt-2 text-[10px] font-semibold uppercase tracking-wide text-[#F2B94D]">District 9103</p>
                <p class="font-display text-[15px] font-bold text-white">RC Cotonou Ife</p>
            </div>

            @if (session('attendanceSubmitted'))
                <div class="flex flex-col items-center gap-3 px-6 py-10 text-center">
                    <div class="flex h-14 w-14 items-center justify-center rounded-full bg-[#E7F5F1] text-2xl text-[#0E7C66]">✓</div>
                    <p class="font-display text-lg font-extrabold text-[#12213D]">Présence enregistrée</p>
                    <p class="text-sm text-[#8A8474]">
                        @if (session('attendanceWasLate'))
                            Votre présence en retard a bien été enregistrée.
                        @else
                            Merci, votre présence a bien été enregistrée.
                        @endif
                    </p>
                    <a href="{{ route('attendance.show') }}" class="text-sm font-semibold text-[#12213D] underline">
                        Envoyer une autre réponse
                    </a>
                </div>
            @elseif (session('attendanceAlreadyCheckedIn'))
                <div class="flex flex-col items-center gap-3 px-6 py-10 text-center">
                    <div class="flex h-14 w-14 items-center justify-center rounded-full bg-[#FDF3E2] text-2xl text-[#C77700]">!</div>
                    <p class="font-display text-lg font-extrabold text-[#12213D]">Présence déjà enregistrée</p>
                    <p class="text-sm text-[#8A8474]">Vous êtes déjà enregistré(e) pour cette séance.</p>
                    <a href="{{ route('attendance.show') }}" class="text-sm font-semibold text-[#12213D] underline">
                        Retour
                    </a>
                </div>
            @elseif (! $meetingSession)
                <div class="flex flex-col items-center gap-3 px-6 py-10 text-center">
                    <p class="font-display text-lg font-extrabold text-[#12213D]">Aucune séance en cours</p>
                    <p class="text-sm text-[#8A8474]">Revenez lors de la prochaine réunion du club.</p>
                </div>
            @else
                <div class="px-6 pb-2 pt-[18px]">
                    <p class="font-display text-xl font-extrabold text-[#12213D]">Liste de présence</p>
                    <p class="text-[13.5px] text-[#8A8474]">{{ $meetingSession->title }} — {{ $meetingSession->date->translatedFormat('d F Y') }}</p>
                </div>

                @if ($meetingSession->is_open)
                    @if ($email === null)
                        <x-attendance-email-form />
                    @else
                        <x-attendance-form :late="false" :email="$email" :member="$member" />
                    @endif
                @else
                    <div x-data="{ lateMode: false }">
                        <div x-show="! lateMode" class="flex flex-col items-center gap-3 px-6 py-10 text-center">
                            <div class="flex h-14 w-14 items-center justify-center rounded-full bg-[#F1EFEA] text-2xl">⏱</div>
                            <p class="font-display text-lg font-extrabold text-[#12213D]">La séance est clôturée</p>
                            <p class="text-sm text-[#8A8474]">Le pointage a été clôturé par l'administrateur.</p>
                            <button type="button" @click="lateMode = true"
                                class="rounded-lg bg-[#12213D] px-4 py-2.5 text-sm font-bold text-white">
                                Marquer ma présence en retard
                            </button>
                        </div>
                        <div x-show="lateMode" x-cloak>
                            @if ($email === null)
                                <x-attendance-email-form />
                            @else
                                <x-attendance-form :late="true" :email="$email" :member="$member" />
                            @endif
                        </div>
                    </div>
                @endif
            @endif
        </div>
    </div>
</x-layouts.app>
```

- [ ] **Step 11: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=AttendanceFormTest`
Run: `php artisan test --compact --filter=AttendanceMemberCheckInTest`

Expected: all PASS.

- [ ] **Step 12: Run the full suite to check for regressions**

Run: `php artisan test --compact`

Expected: PASS (no other test references `attendance.store`/`attendance.show` with assumptions this task broke).

- [ ] **Step 13: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Requests/LookupAttendanceEmailRequest.php app/Http/Requests/StoreAttendanceRequest.php \
    app/Http/Controllers/AttendanceFormController.php routes/web.php \
    resources/views/components/attendance-email-form.blade.php \
    resources/views/components/attendance-form.blade.php \
    resources/views/attendance/show.blade.php \
    tests/Feature/AttendanceFormTest.php tests/Feature/AttendanceMemberCheckInTest.php
git commit -m "Add two-step email check-in flow with member pre-fill and duplicate guard"
```

---

### Task 4: Admin member management (list, search, detail with history, edit)

**Files:**
- Create: `app/Http/Requests/UpdateMemberRequest.php`
- Create: `app/Http/Controllers/Admin/MemberController.php`
- Modify: `routes/web.php`
- Create: `resources/views/admin/members/index.blade.php`
- Create: `resources/views/admin/members/show.blade.php`
- Create: `resources/views/admin/members/edit.blade.php`
- Modify: `resources/views/components/layouts/admin.blade.php`
- Test: `tests/Feature/Admin/MemberManagementTest.php`

**Interfaces:**
- Consumes: `Member` model and `Member::attendances()` from Task 1.
- Produces: `admin.members.index` / `admin.members.show` / `admin.members.edit` / `admin.members.update` routes.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Admin/MemberManagementTest.php`:

```php
<?php

use App\Enums\AttendanceTitle;
use App\Models\Attendance;
use App\Models\MeetingSession;
use App\Models\Member;
use App\Models\User;

it('redirects guests to login for every member route', function () {
    $member = Member::factory()->create();

    $this->get(route('admin.members.index'))->assertRedirect(route('admin.login'));
    $this->get(route('admin.members.show', $member))->assertRedirect(route('admin.login'));
    $this->get(route('admin.members.edit', $member))->assertRedirect(route('admin.login'));
    $this->put(route('admin.members.update', $member), [])->assertRedirect(route('admin.login'));
});

it('lists members to an authenticated admin', function () {
    Member::factory()->create(['name' => 'Jean Dupont', 'email' => 'jean@example.com']);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.members.index'))
        ->assertOk()
        ->assertSee('Jean Dupont')
        ->assertSee('jean@example.com');
});

it('filters the member list by search term', function () {
    Member::factory()->create(['name' => 'Jean Dupont', 'club' => 'RC Cotonou Ife']);
    Member::factory()->create(['name' => 'Awa Bello', 'club' => 'RC Porto-Novo']);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.members.index', ['search' => 'Porto-Novo']))
        ->assertOk()
        ->assertSee('Awa Bello')
        ->assertDontSee('Jean Dupont');
});

it('shows a member detail page with their attendance history', function () {
    $member = Member::factory()->create(['name' => 'Jean Dupont']);
    $meetingSession = MeetingSession::factory()->create(['title' => 'Réunion du 10 janvier']);

    Attendance::factory()->create([
        'member_id' => $member->id,
        'meeting_session_id' => $meetingSession->id,
        'classification' => 'Classification A',
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.members.show', $member))
        ->assertOk()
        ->assertSee('Réunion du 10 janvier')
        ->assertSee('Classification A');
});

it('updates a member', function () {
    $member = Member::factory()->create(['club' => 'RC Cotonou Ife']);

    $this->actingAs(User::factory()->create())
        ->put(route('admin.members.update', $member), [
            'title' => AttendanceTitle::Rotarien->value,
            'name' => $member->name,
            'club' => 'RC Porto-Novo',
            'phone' => $member->phone,
            'classification' => $member->classification,
            'email' => $member->email,
        ])->assertRedirect(route('admin.members.show', $member));

    expect($member->fresh()->club)->toBe('RC Porto-Novo');
});

it('rejects an email that collides with another member', function () {
    Member::factory()->create(['email' => 'existing@example.com']);
    $member = Member::factory()->create(['email' => 'jean@example.com']);

    $this->actingAs(User::factory()->create())
        ->put(route('admin.members.update', $member), [
            'title' => AttendanceTitle::Rotarien->value,
            'name' => $member->name,
            'club' => $member->club,
            'phone' => $member->phone,
            'classification' => $member->classification,
            'email' => 'existing@example.com',
        ])->assertSessionHasErrors(['email']);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=MemberManagementTest`

Expected: FAIL — none of the `admin.members.*` routes exist yet.

- [ ] **Step 3: Add the `UpdateMemberRequest`**

Run: `php artisan make:request UpdateMemberRequest --no-interaction`

Replace its contents:

```php
<?php

namespace App\Http\Requests;

use App\Enums\AttendanceTitle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMemberRequest extends FormRequest
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
            'title' => ['required', Rule::enum(AttendanceTitle::class)],
            'name' => ['required', 'string', 'max:255'],
            'club' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'classification' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('members', 'email')->ignore($this->route('member'))],
        ];
    }
}
```

- [ ] **Step 4: Add the `MemberController`**

Create `app/Http/Controllers/Admin/MemberController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateMemberRequest;
use App\Models\Attendance;
use App\Models\Member;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MemberController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->string('search'));

        $members = Member::query()
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('club', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->get();

        return view('admin.members.index', [
            'members' => $members,
            'search' => $search,
        ]);
    }

    public function show(Member $member): View
    {
        $attendances = $member->attendances()
            ->with('meetingSession')
            ->get()
            ->sortByDesc(fn (Attendance $attendance) => $attendance->meetingSession->date);

        return view('admin.members.show', [
            'member' => $member,
            'attendances' => $attendances,
        ]);
    }

    public function edit(Member $member): View
    {
        return view('admin.members.edit', ['member' => $member]);
    }

    public function update(UpdateMemberRequest $request, Member $member): RedirectResponse
    {
        $member->update($request->validated());

        return redirect()->route('admin.members.show', $member);
    }
}
```

- [ ] **Step 5: Add the admin routes**

In `routes/web.php`, add `use App\Http\Controllers\Admin\MemberController;` to the imports, then add inside the `auth`-middleware group (alongside the `users` routes):

```php
Route::get('members', [MemberController::class, 'index'])->name('members.index');
Route::get('members/{member}', [MemberController::class, 'show'])->name('members.show');
Route::get('members/{member}/edit', [MemberController::class, 'edit'])->name('members.edit');
Route::put('members/{member}', [MemberController::class, 'update'])->name('members.update');
```

- [ ] **Step 6: Add the index view**

Create `resources/views/admin/members/index.blade.php`:

```blade
<x-layouts.admin title="Membres — Administration">
    <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)] md:p-8">
        <h1 class="font-display text-xl font-extrabold text-navy">Membres</h1>

        <form method="GET" action="{{ route('admin.members.index') }}" class="mt-4 flex max-w-sm gap-2">
            <input type="text" name="search" value="{{ $search }}" placeholder="Nom, email ou club"
                class="w-full rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            <button type="submit"
                class="cursor-pointer rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                Rechercher
            </button>
        </form>

        <div class="mt-6 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-divider text-muted-strong">
                        <th class="py-2 pr-4 font-semibold">Nom</th>
                        <th class="py-2 pr-4 font-semibold">Email</th>
                        <th class="py-2 pr-4 font-semibold">Club</th>
                        <th class="py-2 pr-4 font-semibold"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-divider">
                    @foreach ($members as $member)
                        <tr>
                            <td class="py-3 pr-4 font-semibold text-navy">{{ $member->name }}</td>
                            <td class="py-3 pr-4">{{ $member->email }}</td>
                            <td class="py-3 pr-4">{{ $member->club }}</td>
                            <td class="py-3 pr-4 text-right">
                                <a href="{{ route('admin.members.show', $member) }}" class="text-sm font-semibold text-navy underline">
                                    Voir
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-layouts.admin>
```

- [ ] **Step 7: Add the show view**

Create `resources/views/admin/members/show.blade.php`:

```blade
<x-layouts.admin :title="$member->name . ' — Administration'">
    <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)] md:p-8">
        <div class="flex items-center justify-between gap-3">
            <h1 class="font-display text-xl font-extrabold text-navy">{{ $member->name }}</h1>
            <a href="{{ route('admin.members.edit', $member) }}"
                class="cursor-pointer rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                Modifier
            </a>
        </div>

        <dl class="mt-4 grid grid-cols-2 gap-4 text-sm">
            <div>
                <dt class="font-semibold text-muted-strong">Email</dt>
                <dd>{{ $member->email }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-muted-strong">Club</dt>
                <dd>{{ $member->club }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-muted-strong">Téléphone</dt>
                <dd>{{ $member->phone }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-muted-strong">Titre / Qualité</dt>
                <dd>{{ $member->title->value }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-muted-strong">Classification</dt>
                <dd>{{ $member->classification }}</dd>
            </div>
        </dl>

        <h2 class="mt-8 font-display text-lg font-extrabold text-navy">Historique des présences</h2>

        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-divider text-muted-strong">
                        <th class="py-2 pr-4 font-semibold">Séance</th>
                        <th class="py-2 pr-4 font-semibold">Date</th>
                        <th class="py-2 pr-4 font-semibold">Club</th>
                        <th class="py-2 pr-4 font-semibold">Classification</th>
                        <th class="py-2 pr-4 font-semibold">Présent</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-divider">
                    @foreach ($attendances as $attendance)
                        <tr>
                            <td class="py-3 pr-4 font-semibold text-navy">{{ $attendance->meetingSession->title }}</td>
                            <td class="py-3 pr-4 text-muted">{{ $attendance->meetingSession->date->format('d/m/Y') }}</td>
                            <td class="py-3 pr-4">{{ $attendance->club }}</td>
                            <td class="py-3 pr-4">{{ $attendance->classification }}</td>
                            <td class="py-3 pr-4">{{ $attendance->present ? 'Oui' : 'Non' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-layouts.admin>
```

- [ ] **Step 8: Add the edit view**

Create `resources/views/admin/members/edit.blade.php`:

```blade
<x-layouts.admin :title="'Modifier ' . $member->name . ' — Administration'">
    <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)] md:p-8">
        <h1 class="font-display text-xl font-extrabold text-navy">Modifier {{ $member->name }}</h1>

        <form method="POST" action="{{ route('admin.members.update', $member) }}" class="mt-4 flex max-w-md flex-col gap-3">
            @csrf
            @method('PUT')

            <div class="flex flex-col gap-1.5">
                <label for="title" class="text-sm font-semibold">Titre / Qualité</label>
                <select id="title" name="title" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
                    <option value="">Sélectionnez…</option>
                    @foreach (\App\Enums\AttendanceTitle::cases() as $titleOption)
                        <option value="{{ $titleOption->value }}" @selected(old('title', $member->title->value) === $titleOption->value)>
                            {{ $titleOption->value }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="name" class="text-sm font-semibold">Nom</label>
                <input type="text" id="name" name="name" value="{{ old('name', $member->name) }}" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="club" class="text-sm font-semibold">Club</label>
                <input type="text" id="club" name="club" value="{{ old('club', $member->club) }}" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="phone" class="text-sm font-semibold">Téléphone</label>
                <input type="tel" id="phone" name="phone" value="{{ old('phone', $member->phone) }}" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="classification" class="text-sm font-semibold">Classification</label>
                <input type="text" id="classification" name="classification" value="{{ old('classification', $member->classification) }}"
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="email" class="text-sm font-semibold">Email</label>
                <input type="email" id="email" name="email" value="{{ old('email', $member->email) }}" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>

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

- [ ] **Step 9: Add the sidebar entry**

In `resources/views/components/layouts/admin.blade.php`, add this link right after the `Paramètres mail` link and before the closing `</nav>`:

```blade
<a href="{{ route('admin.members.index') }}" @click="close()"
    class="cursor-pointer rounded-lg px-3 py-2 text-sm font-semibold {{ request()->routeIs('admin.members.*') ? 'bg-navy text-white' : 'text-navy hover:bg-cream' }}">
    Membres
</a>
```

- [ ] **Step 10: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=MemberManagementTest`

Expected: all PASS.

- [ ] **Step 11: Run the full suite to check for regressions**

Run: `php artisan test --compact`

Expected: PASS.

- [ ] **Step 12: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Requests/UpdateMemberRequest.php app/Http/Controllers/Admin/MemberController.php \
    routes/web.php resources/views/admin/members resources/views/components/layouts/admin.blade.php \
    tests/Feature/Admin/MemberManagementTest.php
git commit -m "Add admin member management: list, search, detail with attendance history, edit"
```

---

## Self-Review Notes

- **Spec coverage:** Data model + migrations → Task 1/2. Public 2-step flow, pre-fill, write-through, duplicate guard, validation-failure edge case → Task 3. Admin list/search/detail-with-history/edit → Task 4. All spec sections have a corresponding task.
- **Deviation from the spec's illustrative schema:** the spec sketched `members.title` as nullable; this plan makes it `NOT NULL` (matching `attendances.title`, which has always been required) to avoid an empty-string-vs-enum validation edge case with no other benefit, since `title` is always populated by both the check-in flow and the backfill migration.
- **Type consistency:** `Member::normalizeEmail(string): string` used identically in `AttendanceFormController::lookup()`/`store()` and in the backfill migration's normalization logic (inlined there since migrations can't depend on app models). `<x-attendance-form>`'s `email`/`member` props match what `show()`/`lookup()` pass. `UpdateMemberRequest`/`StoreAttendanceRequest`/`LookupAttendanceEmailRequest` field names match the `members`/`attendances` columns throughout.
