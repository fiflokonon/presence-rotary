# Liste de présence — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the attendance-sheet module (public emargement form + admin dashboard) described in `README.md` inside this Laravel 13 application, replacing the static HTML design reference with working Blade/Alpine/Eloquent code.

**Architecture:** Two Eloquent models (`MeetingSession`, `Attendance`) with a category derived from an enum; a public controller for the emargement form; an `admin/*` route group protected by hand-rolled session auth for session management, the live dashboard, presence correction, and PDF export. Interactivity (search/filter, late-mode reveal) is done with Alpine.js on top of server-rendered Blade; all state-mutating actions (toggle present, toggle open/close, login) are classic form POSTs, not fetch calls.

**Tech Stack:** Laravel 13, PHP 8.4, Blade, Alpine.js (npm), Tailwind v4, `barryvdh/laravel-dompdf` (composer, confirmed installable: resolves to v3.1.2), Pest 4.

## Global Constraints

- Follow existing Laravel/Pest/Pint conventions already declared in `CLAUDE.md` (curly braces always, constructor promotion, explicit return types, PHPDoc array shapes, TitleCase enum keys).
- Run `vendor/bin/pint --dirty --format agent` after any PHP changes, before considering a task done.
- Use `php artisan make:*` commands to scaffold new files, never hand-create boilerplate Laravel already generates.
- No new dependencies beyond the two approved in the spec: `barryvdh/laravel-dompdf` (composer) and `alpinejs` (npm).
- `MeetingSession` (not `Session`) is the model/table name — the app already has a `sessions` table for Laravel's own DB session driver; do not collide with it.
- `category` is always a computed Eloquent accessor on `Attendance`, never a stored column.
- Design tokens (colors, radii, spacing, font sizes) are documented in `docs/superpowers/specs/2026-07-10-liste-presence-design.md` and `README.md` — use the literal hex values via Tailwind arbitrary values (e.g. `bg-[#12213D]`).
- No logo file (`assets/rotary-nexus-logo.png`) currently exists in the repository — use a text placeholder in the header instead of an `<img>`, do not invent/fetch an image.
- `APP_LOCALE` must be `fr` so `Carbon`'s `translatedFormat()` renders French month names for session dates.
- After each task's tests pass, commit with a message describing that task only.

---

### Task 1: Dependencies, locale, fonts, Alpine wiring

**Files:**
- Modify: `composer.json`, `composer.lock` (via `composer require`)
- Modify: `package.json`, `package-lock.json` (via `npm install`)
- Modify: `.env`, `.env.example` (`APP_LOCALE`, `APP_FALLBACK_LOCALE`)
- Modify: `vite.config.js`
- Modify: `resources/css/app.css`
- Modify: `resources/js/app.js`

**Interfaces:**
- Produces: `window.Alpine` global available in every Blade view that loads `resources/js/app.js`; Tailwind `font-sans` → Source Sans 3, new `font-display` theme token → Libre Franklin.

- [ ] **Step 1: Install the PDF package**

Run: `composer require barryvdh/laravel-dompdf`
Expected: locks `barryvdh/laravel-dompdf` `^3.1` (already confirmed compatible with `laravel/framework` v13.19.0).

- [ ] **Step 2: Install Alpine.js**

Run: `npm install alpinejs`
Expected: `alpinejs` added to `package.json` dependencies.

- [ ] **Step 3: Set the app locale to French**

In `.env` and `.env.example`, change:
```
APP_LOCALE=en
APP_FALLBACK_LOCALE=en
```
to:
```
APP_LOCALE=fr
APP_FALLBACK_LOCALE=fr
```

- [ ] **Step 4: Swap the Vite font config for the design's typefaces**

In `vite.config.js`, replace the `fonts` array:
```js
fonts: [
    bunny('Instrument Sans', {
        weights: [400, 500, 600],
    }),
],
```
with:
```js
fonts: [
    bunny('Libre Franklin', {
        weights: [700, 800],
    }),
    bunny('Source Sans 3', {
        weights: [400, 500, 600, 700],
    }),
],
```

- [ ] **Step 5: Wire the fonts into Tailwind's theme**

In `resources/css/app.css`, replace the `@theme` block:
```css
@theme {
    --font-sans: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji',
        'Segoe UI Symbol', 'Noto Color Emoji';
}
```
with:
```css
@theme {
    --font-sans: 'Source Sans 3', ui-sans-serif, system-ui, sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji',
        'Segoe UI Symbol', 'Noto Color Emoji';
    --font-display: 'Libre Franklin', ui-sans-serif, system-ui, sans-serif;
}
```

- [ ] **Step 6: Bootstrap Alpine in the app entrypoint**

Replace the contents of `resources/js/app.js` (currently just `//`) with:
```js
import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.start();
```

- [ ] **Step 7: Verify the build compiles**

Run: `npm run build`
Expected: exits 0, `public/build/manifest.json` regenerated with no errors.

- [ ] **Step 8: Commit**

```bash
git add composer.json composer.lock package.json package-lock.json .env.example .env vite.config.js resources/css/app.css resources/js/app.js
git commit -m "chore: install dompdf and Alpine.js, switch to French locale and design typefaces"
```

---

### Task 2: `AttendanceCategory` and `AttendanceTitle` enums

**Files:**
- Create: `app/Enums/AttendanceCategory.php`
- Create: `app/Enums/AttendanceTitle.php`
- Test: `tests/Unit/Enums/AttendanceTitleTest.php`

**Interfaces:**
- Produces: `AttendanceTitle` (backed string enum, 17 cases) with `category(): AttendanceCategory` and `values(): array<int, string>`; `AttendanceCategory` (backed string enum, 4 cases: `Officials`, `Members`, `Rotaractors`, `Guests`) with `label(): string` and `colors(): array{bg: string, accent: string}`.

- [ ] **Step 1: Write the failing test**

Run: `php artisan make:test --pest Enums/AttendanceTitleTest --unit --no-interaction`

Replace the generated file's contents with:
```php
<?php

use App\Enums\AttendanceCategory;
use App\Enums\AttendanceTitle;

it('maps official titles to the officials category', function (AttendanceTitle $title) {
    expect($title->category())->toBe(AttendanceCategory::Officials);
})->with([
    AttendanceTitle::Pdg,
    AttendanceTitle::Dg,
    AttendanceTitle::Dge,
    AttendanceTitle::Dgn,
    AttendanceTitle::Adg,
    AttendanceTitle::PAdg,
    AttendanceTitle::PastPresident,
    AttendanceTitle::President,
    AttendanceTitle::PresidentElu,
    AttendanceTitle::PresidentNomme,
    AttendanceTitle::Secretaire,
    AttendanceTitle::Tresorier,
    AttendanceTitle::Protocole,
    AttendanceTitle::PresidentDeCommission,
]);

it('maps Rotarien to the members category', function () {
    expect(AttendanceTitle::Rotarien->category())->toBe(AttendanceCategory::Members);
});

it('maps Rotaractien to the rotaractors category', function () {
    expect(AttendanceTitle::Rotaractien->category())->toBe(AttendanceCategory::Rotaractors);
});

it('maps Invité to the guests category', function () {
    expect(AttendanceTitle::Invite->category())->toBe(AttendanceCategory::Guests);
});

it('lists all title values for the form select', function () {
    expect(AttendanceTitle::values())->toHaveCount(17)
        ->and(AttendanceTitle::values())->toContain('Rotaractien', 'Invité', 'PDG');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=AttendanceTitleTest`
Expected: FAIL — `Class "App\Enums\AttendanceTitle" not found`.

- [ ] **Step 3: Create the `AttendanceCategory` enum**

Create `app/Enums/AttendanceCategory.php`:
```php
<?php

namespace App\Enums;

enum AttendanceCategory: string
{
    case Officials = 'officials';
    case Members = 'members';
    case Rotaractors = 'rotaractors';
    case Guests = 'guests';

    public function label(): string
    {
        return match ($this) {
            self::Officials => 'Bureau / Officiels',
            self::Members => 'Membres',
            self::Rotaractors => 'Rotaractiens',
            self::Guests => 'Invités',
        };
    }

    /**
     * @return array{bg: string, accent: string}
     */
    public function colors(): array
    {
        return match ($this) {
            self::Officials => ['bg' => '#EAF1FB', 'accent' => '#17458F'],
            self::Members => ['bg' => '#E7F5F1', 'accent' => '#0E7C66'],
            self::Rotaractors => ['bg' => '#FDF3E2', 'accent' => '#C77700'],
            self::Guests => ['bg' => '#F1EFEA', 'accent' => '#6B6558'],
        };
    }
}
```

- [ ] **Step 4: Create the `AttendanceTitle` enum**

Create `app/Enums/AttendanceTitle.php`:
```php
<?php

namespace App\Enums;

enum AttendanceTitle: string
{
    case Pdg = 'PDG';
    case Dg = 'DG';
    case Dge = 'DGE';
    case Dgn = 'DGN';
    case Adg = 'AdG';
    case PAdg = 'PAdG';
    case PastPresident = 'Past Président';
    case President = 'Président';
    case PresidentElu = 'Président Elu';
    case PresidentNomme = 'Président Nommé';
    case Secretaire = 'Secrétaire';
    case Tresorier = 'Trésorier';
    case Protocole = 'Protocole';
    case PresidentDeCommission = 'Président de Commission';
    case Rotarien = 'Rotarien';
    case Rotaractien = 'Rotaractien';
    case Invite = 'Invité';

    public function category(): AttendanceCategory
    {
        return match ($this) {
            self::Pdg, self::Dg, self::Dge, self::Dgn, self::Adg, self::PAdg,
            self::PastPresident, self::President, self::PresidentElu, self::PresidentNomme,
            self::Secretaire, self::Tresorier, self::Protocole, self::PresidentDeCommission => AttendanceCategory::Officials,
            self::Rotarien => AttendanceCategory::Members,
            self::Rotaractien => AttendanceCategory::Rotaractors,
            self::Invite => AttendanceCategory::Guests,
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --compact --filter=AttendanceTitleTest`
Expected: PASS (17 tests).

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Enums tests/Unit/Enums
git commit -m "feat: add AttendanceTitle and AttendanceCategory enums with title-to-category mapping"
```

---

### Task 3: `MeetingSession` model, migration, factory

**Files:**
- Create: `app/Models/MeetingSession.php` (via `make:model`)
- Create: `database/migrations/xxxx_create_meeting_sessions_table.php`
- Create: `database/factories/MeetingSessionFactory.php`
- Test: `tests/Feature/Models/MeetingSessionTest.php`
- Modify: `tests/Pest.php` (enable `RefreshDatabase` for `Feature` tests)

**Interfaces:**
- Consumes: nothing.
- Produces: `MeetingSession::active(): ?self`, `MeetingSession::activate(): void` (instance method — deactivates any other active session, activates `$this`), fillable `title`, `date`, `time`, `is_open`, `is_active`; relation `attendances(): HasMany` (used by Task 4+).

- [ ] **Step 1: Enable RefreshDatabase for feature tests**

In `tests/Pest.php`, uncomment the trait so feature tests get a clean SQLite DB each run:
```php
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');
```

- [ ] **Step 2: Scaffold the model, migration, and factory**

Run: `php artisan make:model MeetingSession -mf --no-interaction`
Expected: creates `app/Models/MeetingSession.php`, a migration under `database/migrations/`, and `database/factories/MeetingSessionFactory.php`.

- [ ] **Step 3: Write the failing test**

Run: `php artisan make:test --pest Models/MeetingSessionTest --no-interaction`

Replace the generated file's contents with:
```php
<?php

use App\Models\MeetingSession;

it('activates a session and deactivates any other active session', function () {
    $first = MeetingSession::factory()->create(['is_active' => true]);
    $second = MeetingSession::factory()->create(['is_active' => false]);

    $second->activate();

    expect($first->fresh()->is_active)->toBeFalse()
        ->and($second->fresh()->is_active)->toBeTrue();
});

it('resolves the active session', function () {
    MeetingSession::factory()->create(['is_active' => false]);
    $active = MeetingSession::factory()->create(['is_active' => true]);

    expect(MeetingSession::active()->id)->toBe($active->id);
});

it('returns null when no session is active', function () {
    MeetingSession::factory()->create(['is_active' => false]);

    expect(MeetingSession::active())->toBeNull();
});

it('casts date, is_open, and is_active', function () {
    $meetingSession = MeetingSession::factory()->create([
        'date' => '2026-07-10',
        'is_open' => 1,
        'is_active' => 0,
    ]);

    expect($meetingSession->date)->toBeInstanceOf(Illuminate\Support\Carbon::class)
        ->and($meetingSession->is_open)->toBeTrue()
        ->and($meetingSession->is_active)->toBeFalse();
});
```

- [ ] **Step 4: Run the test to verify it fails**

Run: `php artisan test --compact --filter=MeetingSessionTest`
Expected: FAIL — table `meeting_sessions` not found / method `activate` not found.

- [ ] **Step 5: Write the migration**

Replace the contents of the generated `database/migrations/xxxx_create_meeting_sessions_table.php` `up()`/`down()`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->date('date');
            $table->time('time');
            $table->boolean('is_open')->default(true);
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_sessions');
    }
};
```

- [ ] **Step 6: Write the model**

Replace the contents of `app/Models/MeetingSession.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MeetingSession extends Model
{
    /** @use HasFactory<\Database\Factories\MeetingSessionFactory> */
    use HasFactory;

    protected $fillable = ['title', 'date', 'time', 'is_open', 'is_active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'is_open' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public static function active(): ?self
    {
        return static::where('is_active', true)->first();
    }

    public function activate(): void
    {
        static::where('is_active', true)->update(['is_active' => false]);
        $this->update(['is_active' => true]);
    }
}
```

- [ ] **Step 7: Write the factory**

Replace the contents of `database/factories/MeetingSessionFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Models\MeetingSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MeetingSession>
 */
class MeetingSessionFactory extends Factory
{
    protected $model = MeetingSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => 'Réunion du '.fake()->dayOfWeek(),
            'date' => fake()->date(),
            'time' => '12:30:00',
            'is_open' => true,
            'is_active' => false,
        ];
    }
}
```

- [ ] **Step 8: Run the test to verify it passes**

Run: `php artisan test --compact --filter=MeetingSessionTest`
Expected: PASS (4 tests).

- [ ] **Step 9: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/MeetingSession.php database/migrations database/factories/MeetingSessionFactory.php tests/Feature/Models/MeetingSessionTest.php tests/Pest.php
git commit -m "feat: add MeetingSession model with single-active-session semantics"
```

---

### Task 4: `Attendance` model, migration, factory

**Files:**
- Create: `app/Models/Attendance.php` (via `make:model`)
- Create: `database/migrations/xxxx_create_attendances_table.php`
- Create: `database/factories/AttendanceFactory.php`
- Test: `tests/Feature/Models/AttendanceTest.php`

**Interfaces:**
- Consumes: `MeetingSession` (Task 3), `AttendanceTitle`/`AttendanceCategory` (Task 2).
- Produces: `Attendance` with fillable `meeting_session_id`, `title`, `name`, `club`, `phone`, `classification`, `email`, `present`, `is_late`; `title` cast to `AttendanceTitle`; computed `category` accessor returning `AttendanceCategory`; `meetingSession(): BelongsTo`. Used by the public form controller (Task 6) and the admin dashboard (Task 9).

- [ ] **Step 1: Scaffold the model, migration, and factory**

Run: `php artisan make:model Attendance -mf --no-interaction`

- [ ] **Step 2: Write the failing test**

Run: `php artisan make:test --pest Models/AttendanceTest --no-interaction`

Replace the generated file's contents with:
```php
<?php

use App\Enums\AttendanceCategory;
use App\Enums\AttendanceTitle;
use App\Models\Attendance;
use App\Models\MeetingSession;

it('derives its category from its title', function () {
    $attendance = Attendance::factory()->create([
        'title' => AttendanceTitle::Rotaractien,
    ]);

    expect($attendance->category)->toBe(AttendanceCategory::Rotaractors);
});

it('casts its title to the AttendanceTitle enum', function () {
    $attendance = Attendance::factory()->create(['title' => 'Rotarien']);

    expect($attendance->title)->toBe(AttendanceTitle::Rotarien);
});

it('belongs to a meeting session', function () {
    $meetingSession = MeetingSession::factory()->create();
    $attendance = Attendance::factory()->for($meetingSession)->create();

    expect($attendance->meetingSession->is($meetingSession))->toBeTrue();
});

it('casts present and is_late to booleans', function () {
    $attendance = Attendance::factory()->create(['present' => 1, 'is_late' => 0]);

    expect($attendance->present)->toBeTrue()
        ->and($attendance->is_late)->toBeFalse();
});
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `php artisan test --compact --filter=AttendanceTest`
Expected: FAIL — table `attendances` not found.

- [ ] **Step 4: Write the migration**

Replace the contents of the generated `database/migrations/xxxx_create_attendances_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_session_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('name');
            $table->string('club');
            $table->string('phone');
            $table->string('classification')->nullable();
            $table->string('email')->nullable();
            $table->boolean('present')->default(true);
            $table->boolean('is_late')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
```

- [ ] **Step 5: Write the model**

Replace the contents of `app/Models/Attendance.php`:
```php
<?php

namespace App\Models;

use App\Enums\AttendanceCategory;
use App\Enums\AttendanceTitle;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    /** @use HasFactory<\Database\Factories\AttendanceFactory> */
    use HasFactory;

    protected $fillable = [
        'meeting_session_id', 'title', 'name', 'club', 'phone',
        'classification', 'email', 'present', 'is_late',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'title' => AttendanceTitle::class,
            'present' => 'boolean',
            'is_late' => 'boolean',
        ];
    }

    public function meetingSession(): BelongsTo
    {
        return $this->belongsTo(MeetingSession::class);
    }

    protected function category(): Attribute
    {
        return Attribute::get(fn (): AttendanceCategory => $this->title->category());
    }
}
```

- [ ] **Step 6: Write the factory**

Replace the contents of `database/factories/AttendanceFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Enums\AttendanceTitle;
use App\Models\Attendance;
use App\Models\MeetingSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attendance>
 */
class AttendanceFactory extends Factory
{
    protected $model = Attendance::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'meeting_session_id' => MeetingSession::factory(),
            'title' => fake()->randomElement(AttendanceTitle::cases()),
            'name' => fake()->name(),
            'club' => 'RC Cotonou Nexus',
            'phone' => fake()->phoneNumber(),
            'classification' => null,
            'email' => null,
            'present' => true,
            'is_late' => false,
        ];
    }
}
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `php artisan test --compact --filter=AttendanceTest`
Expected: PASS (4 tests).

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/Attendance.php database/migrations database/factories/AttendanceFactory.php tests/Feature/Models/AttendanceTest.php
git commit -m "feat: add Attendance model with title cast and category accessor"
```

---

### Task 5: Admin user seeder

**Files:**
- Modify: `database/seeders/DatabaseSeeder.php`

**Interfaces:**
- Produces: one `users` row (`admin@rotarynexus.test` / `password`) usable by the auth tests in Task 7.

- [ ] **Step 1: Update the seeder**

Replace the contents of `database/seeders/DatabaseSeeder.php`:
```php
<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Admin',
            'email' => 'admin@rotarynexus.test',
            'password' => bcrypt('password'),
        ]);
    }
}
```

- [ ] **Step 2: Migrate and seed the local database, then verify**

Run: `php artisan migrate:fresh --seed --no-interaction`
Then run: `php artisan tinker --execute 'echo App\Models\User::where("email", "admin@rotarynexus.test")->exists() ? "OK" : "MISSING";'`
Expected: prints `OK`.

- [ ] **Step 3: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/seeders/DatabaseSeeder.php
git commit -m "feat: seed a default admin user"
```

---

### Task 6: Public attendance form

**Files:**
- Create: `app/Http/Requests/StoreAttendanceRequest.php`
- Create: `app/Http/Controllers/AttendanceFormController.php`
- Create: `resources/views/components/layouts/app.blade.php`
- Create: `resources/views/components/attendance-form.blade.php`
- Create: `resources/views/attendance/show.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/AttendanceFormTest.php`

**Interfaces:**
- Consumes: `MeetingSession::active()` (Task 3), `Attendance` (Task 4), `AttendanceTitle::cases()` (Task 2).
- Produces: named routes `attendance.show` (`GET /`), `attendance.store` (`POST /attendances`).

- [ ] **Step 1: Write the failing tests**

Run: `php artisan make:test --pest AttendanceFormTest --no-interaction`

Replace the generated file's contents with:
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

it('shows the form when the active session is open', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);

    $this->get(route('attendance.show'))
        ->assertOk()
        ->assertSee('Liste de présence')
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
        'club' => 'RC Cotonou Nexus',
        'phone' => '+229 90 00 00 00',
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
    ])->assertRedirect(route('attendance.show'));

    expect(Attendance::first())
        ->present->toBeTrue()
        ->is_late->toBeTrue();
});

it('rejects a submission missing required fields', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);

    $this->post(route('attendance.store'), ['name' => 'Jean Dupont'])
        ->assertSessionHasErrors(['title', 'club', 'phone']);

    expect(Attendance::count())->toBe(0);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=AttendanceFormTest`
Expected: FAIL — route `attendance.show` not defined.

- [ ] **Step 3: Write the form request**

Create `app/Http/Requests/StoreAttendanceRequest.php`:
```php
<?php

namespace App\Http\Requests;

use App\Enums\AttendanceTitle;
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
            'title' => ['required', Rule::enum(AttendanceTitle::class)],
            'name' => ['required', 'string', 'max:255'],
            'club' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'classification' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
        ];
    }
}
```

- [ ] **Step 4: Write the controller**

Create `app/Http/Controllers/AttendanceFormController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAttendanceRequest;
use App\Models\Attendance;
use App\Models\MeetingSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AttendanceFormController extends Controller
{
    public function show(): View
    {
        return view('attendance.show', [
            'meetingSession' => MeetingSession::active(),
        ]);
    }

    public function store(StoreAttendanceRequest $request): RedirectResponse
    {
        $meetingSession = MeetingSession::active();

        abort_if($meetingSession === null, 404);

        Attendance::create([
            ...$request->validated(),
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

- [ ] **Step 5: Register the routes**

Replace the contents of `routes/web.php`:
```php
<?php

use App\Http\Controllers\AttendanceFormController;
use Illuminate\Support\Facades\Route;

Route::get('/', [AttendanceFormController::class, 'show'])->name('attendance.show');
Route::post('/attendances', [AttendanceFormController::class, 'store'])->name('attendance.store');
```

- [ ] **Step 6: Create the shared public layout component**

Create `resources/views/components/layouts/app.blade.php`:
```blade
@props(['title' => 'Liste de présence — RC Cotonou Nexus'])
<!doctype html>
<html lang="fr" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-[#F5F3EE] font-sans text-[#12213D] antialiased">
    {{ $slot }}
</body>
</html>
```

- [ ] **Step 7: Create the reusable attendance form component**

Create `resources/views/components/attendance-form.blade.php`:
```blade
@props(['late' => false])

<form method="POST" action="{{ route('attendance.store') }}" class="flex flex-col gap-4 px-6 pb-6 pt-4">
    @csrf

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
        <label for="title" class="text-sm font-semibold text-[#12213D]">Titre / Qualité*</label>
        <select id="title" name="title" required
            class="rounded-lg border border-[#DEDAD0] px-3 py-2 text-sm">
            <option value="">Sélectionnez…</option>
            @foreach (\App\Enums\AttendanceTitle::cases() as $titleOption)
                <option value="{{ $titleOption->value }}" @selected(old('title') === $titleOption->value)>
                    {{ $titleOption->value }}
                </option>
            @endforeach
        </select>
    </div>

    <div class="flex flex-col gap-1.5">
        <label for="name" class="text-sm font-semibold text-[#12213D]">Nom et prénoms*</label>
        <input type="text" id="name" name="name" value="{{ old('name') }}" required
            class="rounded-lg border border-[#DEDAD0] px-3 py-2 text-sm">
    </div>

    <div class="flex flex-col gap-1.5">
        <label for="club" class="text-sm font-semibold text-[#12213D]">Votre club*</label>
        <input type="text" id="club" name="club" value="{{ old('club') }}" required
            class="rounded-lg border border-[#DEDAD0] px-3 py-2 text-sm">
    </div>

    <div class="flex flex-col gap-1.5">
        <label for="phone" class="text-sm font-semibold text-[#12213D]">Numéro de téléphone*</label>
        <input type="tel" id="phone" name="phone" value="{{ old('phone') }}" required
            class="rounded-lg border border-[#DEDAD0] px-3 py-2 text-sm">
    </div>

    <div class="flex flex-col gap-1.5">
        <label for="classification" class="text-sm font-semibold text-[#12213D]">Classification</label>
        <input type="text" id="classification" name="classification" value="{{ old('classification') }}"
            class="rounded-lg border border-[#DEDAD0] px-3 py-2 text-sm">
    </div>

    <div class="flex flex-col gap-1.5">
        <label for="email" class="text-sm font-semibold text-[#12213D]">Adresse e-mail</label>
        <input type="email" id="email" name="email" value="{{ old('email') }}"
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

- [ ] **Step 8: Create the public form page**

Create `resources/views/attendance/show.blade.php`:
```blade
<x-layouts.app :title="'Liste de présence' . ($meetingSession ? ' — ' . $meetingSession->title : '')">
    <div class="mx-auto flex min-h-screen max-w-[420px] items-center px-4 py-10">
        <div class="w-full overflow-hidden rounded-xl bg-white shadow-[0_2px_10px_rgba(20,30,50,.06)]">
            <div class="bg-[#12213D] px-6 pb-[18px] pt-[22px]">
                <p class="font-display text-lg font-extrabold text-white">RC Cotonou Nexus</p>
                <p class="mt-2 text-[10px] font-semibold uppercase tracking-wide text-[#F2B94D]">District 9103</p>
                <p class="font-display text-[15px] font-bold text-white">RC Cotonou Nexus</p>
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
                    <x-attendance-form :late="false" />
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
                            <x-attendance-form :late="true" />
                        </div>
                    </div>
                @endif
            @endif
        </div>
    </div>
</x-layouts.app>
```

- [ ] **Step 9: Delete the now-unused starter view**

Run: `rm resources/views/welcome.blade.php`

- [ ] **Step 10: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=AttendanceFormTest`
Expected: PASS (6 tests).

- [ ] **Step 11: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Requests/StoreAttendanceRequest.php app/Http/Controllers/AttendanceFormController.php resources/views routes/web.php tests/Feature/AttendanceFormTest.php
git commit -m "feat: build the public attendance form with open/closed/late/confirmation states"
```

---

### Task 7: Admin authentication

**Files:**
- Create: `app/Http/Requests/LoginRequest.php`
- Create: `app/Http/Controllers/Admin/AuthController.php`
- Create: `resources/views/admin/auth/login.blade.php`
- Create: `resources/views/components/layouts/admin.blade.php`
- Modify: `routes/web.php`
- Modify: `bootstrap/app.php` (redirect guests to `admin.login` instead of the default `login` route)
- Test: `tests/Feature/Admin/AuthTest.php`

**Interfaces:**
- Consumes: Laravel's default `User` model/`users` table.
- Produces: named routes `admin.login` (`GET`/`POST /admin/login`), `admin.logout` (`POST /admin/logout`); `<x-layouts.admin>` component (used by Tasks 8 and 9) that renders a logout button when authenticated.

- [ ] **Step 1: Write the failing tests**

Run: `php artisan make:test --pest Admin/AuthTest --no-interaction`

Replace the generated file's contents with:
```php
<?php

use App\Models\User;

it('shows the login form to a guest', function () {
    $this->get(route('admin.login'))->assertOk();
});

it('redirects guests hitting admin routes to the login form', function () {
    $this->get(route('admin.sessions.index'))->assertRedirect(route('admin.login'));
});

it('logs an admin in with valid credentials', function () {
    $user = User::factory()->create(['password' => bcrypt('secret123')]);

    $this->post(route('admin.login'), [
        'email' => $user->email,
        'password' => 'secret123',
    ])->assertRedirect(route('admin.sessions.index'));

    $this->assertAuthenticatedAs($user);
});

it('rejects invalid credentials', function () {
    $user = User::factory()->create(['password' => bcrypt('secret123')]);

    $this->post(route('admin.login'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('logs an admin out', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('admin.logout'))
        ->assertRedirect(route('admin.login'));

    $this->assertGuest();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=Admin/AuthTest`
Expected: FAIL — route `admin.login` not defined.

- [ ] **Step 3: Write the login form request**

Create `app/Http/Requests/LoginRequest.php`:
```php
<?php

namespace App\Http\Requests;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
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
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt($this->only('email', 'password'))) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
```

- [ ] **Step 4: Write the auth controller**

Create `app/Http/Controllers/Admin/AuthController.php`:
```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function create(): View
    {
        return view('admin.auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        return redirect()->route('admin.sessions.index');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
```

- [ ] **Step 5: Register the routes**

Replace the contents of `routes/web.php`:
```php
<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\AttendanceFormController;
use Illuminate\Support\Facades\Route;

Route::get('/', [AttendanceFormController::class, 'show'])->name('attendance.show');
Route::post('/attendances', [AttendanceFormController::class, 'store'])->name('attendance.store');

Route::prefix('admin')->name('admin.')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('login', [AuthController::class, 'create'])->name('login');
        Route::post('login', [AuthController::class, 'store'])->name('login.store');
    });

    Route::middleware('auth')->group(function () {
        Route::post('logout', [AuthController::class, 'destroy'])->name('logout');
    });
});
```

Note: `admin.sessions.index` (referenced by the tests and by `store()`/`destroy()` above) is registered in Task 8 — this task's tests for redirects to `admin.sessions.index` will only fully pass once Task 8 adds that route; run this task's login/logout-specific tests individually until then if needed, but write the routes file exactly as above since Task 8 appends to the same `auth` group.

- [ ] **Step 6: Configure the guest redirect target**

In `bootstrap/app.php`, add `redirectGuestsTo` inside `withMiddleware`:
```php
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn () => route('admin.login'));
    })
```

- [ ] **Step 7: Create the admin layout component**

Create `resources/views/components/layouts/admin.blade.php`:
```blade
@props(['title' => 'Administration — RC Cotonou Nexus'])
<!doctype html>
<html lang="fr" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full bg-[#F5F3EE] font-sans text-[#12213D] antialiased">
    <div class="mx-auto max-w-[1040px] px-4 py-8">
        <div class="mb-4 flex items-center justify-between">
            <span class="text-sm font-semibold text-[#12213D]">RC Cotonou Nexus · Administration</span>
            @auth
                <form method="POST" action="{{ route('admin.logout') }}">
                    @csrf
                    <button type="submit" class="text-sm font-semibold text-[#C77700]">Se déconnecter</button>
                </form>
            @endauth
        </div>
        {{ $slot }}
    </div>
</body>
</html>
```

- [ ] **Step 8: Create the login view**

Create `resources/views/admin/auth/login.blade.php`:
```blade
<x-layouts.app title="Connexion administrateur">
    <div class="mx-auto flex min-h-screen max-w-[380px] items-center px-4">
        <div class="w-full rounded-xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)]">
            <h1 class="font-display text-xl font-extrabold text-[#12213D]">Connexion administrateur</h1>

            @if ($errors->any())
                <div class="mt-4 rounded-lg bg-[#FBEAEA] px-4 py-3 text-sm text-[#B23B3B]">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('admin.login.store') }}" class="mt-4 flex flex-col gap-4">
                @csrf
                <div class="flex flex-col gap-1.5">
                    <label for="email" class="text-sm font-semibold">E-mail</label>
                    <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus
                        class="rounded-lg border border-[#DEDAD0] px-3 py-2 text-sm">
                </div>
                <div class="flex flex-col gap-1.5">
                    <label for="password" class="text-sm font-semibold">Mot de passe</label>
                    <input type="password" id="password" name="password" required
                        class="rounded-lg border border-[#DEDAD0] px-3 py-2 text-sm">
                </div>
                <button type="submit"
                    class="rounded-lg bg-[#12213D] px-4 py-2.5 text-sm font-bold text-white hover:bg-[#1c3559]">
                    Se connecter
                </button>
            </form>
        </div>
    </div>
</x-layouts.app>
```

- [ ] **Step 9: Run the full suite after Task 8 adds `admin.sessions.index`**

This task's tests that redirect to `admin.sessions.index` will fail until Task 8 registers that route — that is expected. For now, run only the routable subset:
Run: `php artisan test --compact --filter="logs an admin in|shows the login form|rejects invalid|logs an admin out"`
Expected: PASS (4 of 5 — the guest-redirect test is finished in Task 8).

- [ ] **Step 10: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Requests/LoginRequest.php app/Http/Controllers/Admin/AuthController.php resources/views/admin/auth resources/views/components/layouts/admin.blade.php routes/web.php bootstrap/app.php tests/Feature/Admin/AuthTest.php
git commit -m "feat: add hand-rolled admin login/logout"
```

---

### Task 8: Admin session management (list, create + auto-activate, toggle open/close)

**Files:**
- Create: `app/Http/Requests/StoreMeetingSessionRequest.php`
- Create: `app/Http/Controllers/Admin/MeetingSessionController.php` (index, store, toggleOpen — `show` and `exportPdf` land in Tasks 9–10)
- Create: `resources/views/admin/sessions/index.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Admin/MeetingSessionManagementTest.php`

**Interfaces:**
- Consumes: `MeetingSession` (Task 3), admin `auth` middleware (Task 7).
- Produces: named routes `admin.sessions.index` (`GET /admin/sessions`), `admin.sessions.store` (`POST /admin/sessions`), `admin.sessions.toggle-open` (`POST /admin/sessions/{meetingSession}/toggle-open`).

- [ ] **Step 1: Write the failing tests**

Run: `php artisan make:test --pest Admin/MeetingSessionManagementTest --no-interaction`

Replace the generated file's contents with:
```php
<?php

use App\Models\MeetingSession;
use App\Models\User;

it('redirects guests to login', function () {
    $this->get(route('admin.sessions.index'))->assertRedirect(route('admin.login'));
});

it('lists existing sessions to an authenticated admin', function () {
    $meetingSession = MeetingSession::factory()->create(['title' => 'Réunion hebdomadaire']);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.index'))
        ->assertOk()
        ->assertSee('Réunion hebdomadaire');
});

it('creates a session and auto-activates it, deactivating the previous one', function () {
    $previous = MeetingSession::factory()->create(['is_active' => true]);

    $this->actingAs(User::factory()->create())
        ->post(route('admin.sessions.store'), [
            'title' => 'Réunion du 10 juillet',
            'date' => '2026-07-10',
            'time' => '12:30',
        ])->assertRedirect();

    $created = MeetingSession::where('title', 'Réunion du 10 juillet')->firstOrFail();

    expect($created->is_active)->toBeTrue()
        ->and($created->is_open)->toBeTrue()
        ->and($previous->fresh()->is_active)->toBeFalse();
});

it('rejects an invalid session creation payload', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('admin.sessions.store'), ['title' => ''])
        ->assertSessionHasErrors(['title', 'date', 'time']);
});

it('toggles a session open state', function () {
    $meetingSession = MeetingSession::factory()->create(['is_open' => true]);

    $this->actingAs(User::factory()->create())
        ->post(route('admin.sessions.toggle-open', $meetingSession))
        ->assertRedirect();

    expect($meetingSession->fresh()->is_open)->toBeFalse();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=MeetingSessionManagementTest`
Expected: FAIL — route `admin.sessions.index` not defined.

- [ ] **Step 3: Write the form request**

Create `app/Http/Requests/StoreMeetingSessionRequest.php`:
```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMeetingSessionRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'time' => ['required', 'date_format:H:i'],
        ];
    }
}
```

- [ ] **Step 4: Write the controller (partial — `index`, `store`, `toggleOpen`)**

Create `app/Http/Controllers/Admin/MeetingSessionController.php`:
```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMeetingSessionRequest;
use App\Models\MeetingSession;
use Illuminate\Http\RedirectResponse;
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

    public function toggleOpen(MeetingSession $meetingSession): RedirectResponse
    {
        $meetingSession->update(['is_open' => ! $meetingSession->is_open]);

        return redirect()->route('admin.sessions.show', $meetingSession);
    }
}
```

Note: `store()` and `toggleOpen()` redirect to `admin.sessions.show`, which is registered in Task 9. Routing to a not-yet-defined named route only breaks at request time (when Blade's `route()` helper resolves it), so this task's tests — which only assert `assertRedirect()` without following the redirect — pass before Task 9 exists.

- [ ] **Step 5: Register the routes**

Replace the `auth` group in `routes/web.php` with:
```php
    Route::middleware('auth')->group(function () {
        Route::post('logout', [AuthController::class, 'destroy'])->name('logout');
        Route::get('sessions', [MeetingSessionController::class, 'index'])->name('sessions.index');
        Route::post('sessions', [MeetingSessionController::class, 'store'])->name('sessions.store');
        Route::post('sessions/{meetingSession}/toggle-open', [MeetingSessionController::class, 'toggleOpen'])->name('sessions.toggle-open');
    });
```
And add the import at the top:
```php
use App\Http\Controllers\Admin\MeetingSessionController;
```

- [ ] **Step 6: Create the sessions list/create view**

Create `resources/views/admin/sessions/index.blade.php`:
```blade
<x-layouts.admin title="Séances — Administration">
    <div class="rounded-xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)]">
        <h1 class="font-display text-xl font-extrabold text-[#12213D]">Séances</h1>

        <form method="POST" action="{{ route('admin.sessions.store') }}" class="mt-4 flex flex-wrap items-end gap-3">
            @csrf
            <div class="flex flex-col gap-1.5">
                <label for="title" class="text-sm font-semibold">Titre</label>
                <input type="text" id="title" name="title" value="{{ old('title') }}" required
                    class="rounded-lg border border-[#DEDAD0] px-3 py-2 text-sm">
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="date" class="text-sm font-semibold">Date</label>
                <input type="date" id="date" name="date" value="{{ old('date') }}" required
                    class="rounded-lg border border-[#DEDAD0] px-3 py-2 text-sm">
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="time" class="text-sm font-semibold">Heure</label>
                <input type="time" id="time" name="time" value="{{ old('time') }}" required
                    class="rounded-lg border border-[#DEDAD0] px-3 py-2 text-sm">
            </div>
            <button type="submit"
                class="rounded-lg bg-[#12213D] px-4 py-2.5 text-sm font-bold text-white hover:bg-[#1c3559]">
                Créer et activer
            </button>
        </form>

        @if ($errors->any())
            <div class="mt-4 rounded-lg bg-[#FBEAEA] px-4 py-3 text-sm text-[#B23B3B]">
                {{ $errors->first() }}
            </div>
        @endif

        <ul class="mt-6 divide-y divide-[#EDEAE2]">
            @foreach ($meetingSessions as $meetingSession)
                <li class="flex items-center justify-between py-3">
                    <a href="{{ route('admin.sessions.show', $meetingSession) }}" class="text-sm font-semibold text-[#12213D] hover:underline">
                        {{ $meetingSession->title }} — {{ $meetingSession->date->format('d/m/Y') }}
                    </a>
                    <span class="flex items-center gap-2">
                        @if ($meetingSession->is_active)
                            <span class="rounded-full bg-[#E7F5F1] px-2 py-0.5 text-[11px] font-semibold uppercase text-[#0E7C66]">Active</span>
                        @endif
                        <span class="rounded-full {{ $meetingSession->is_open ? 'bg-[#E7F5F1] text-[#0E7C66]' : 'bg-[#F1EFEA] text-[#6B6558]' }} px-2 py-0.5 text-[11px] font-semibold uppercase">
                            {{ $meetingSession->is_open ? 'Ouverte' : 'Clôturée' }}
                        </span>
                    </span>
                </li>
            @endforeach
        </ul>
    </div>
</x-layouts.admin>
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=MeetingSessionManagementTest`
Expected: PASS (5 tests). Also re-run Task 7's suite: `php artisan test --compact --filter=Admin/AuthTest` — all 5 should now pass since `admin.sessions.index` exists.

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Requests/StoreMeetingSessionRequest.php app/Http/Controllers/Admin/MeetingSessionController.php resources/views/admin/sessions/index.blade.php routes/web.php tests/Feature/Admin/MeetingSessionManagementTest.php
git commit -m "feat: add admin session list, creation with auto-activation, and open/close toggle"
```

---

### Task 9: Admin dashboard (counters, grouped/filterable list, presence toggle)

**Files:**
- Create: `app/Http/Controllers/Admin/AttendanceController.php`
- Modify: `app/Http/Controllers/Admin/MeetingSessionController.php` (add `show`)
- Create: `resources/views/admin/sessions/show.blade.php`
- Modify: `resources/js/app.js` (Alpine `attendanceDashboard` component)
- Modify: `routes/web.php`
- Test: `tests/Feature/Admin/AttendanceDashboardTest.php`

**Interfaces:**
- Consumes: `MeetingSession`, `Attendance`, `AttendanceCategory::cases()/label()/colors()` (Tasks 2–4).
- Produces: named routes `admin.sessions.show` (`GET /admin/sessions/{meetingSession}`), `admin.attendances.toggle-present` (`PATCH /admin/attendances/{attendance}/toggle-present`); global JS function `Alpine.data('attendanceDashboard', ...)` consumed by `resources/views/admin/sessions/show.blade.php`.

- [ ] **Step 1: Write the failing tests**

Run: `php artisan make:test --pest Admin/AttendanceDashboardTest --no-interaction`

Replace the generated file's contents with:
```php
<?php

use App\Enums\AttendanceTitle;
use App\Models\Attendance;
use App\Models\MeetingSession;
use App\Models\User;

it('redirects guests to login', function () {
    $meetingSession = MeetingSession::factory()->create();

    $this->get(route('admin.sessions.show', $meetingSession))
        ->assertRedirect(route('admin.login'));
});

it('shows counters and the roster to an authenticated admin', function () {
    $meetingSession = MeetingSession::factory()->create();
    Attendance::factory()->for($meetingSession)->create(['title' => AttendanceTitle::Rotarien, 'name' => 'Jean Dupont', 'present' => true]);
    Attendance::factory()->for($meetingSession)->create(['title' => AttendanceTitle::Invite, 'name' => 'Awa Bello', 'present' => false]);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.show', $meetingSession))
        ->assertOk()
        ->assertSee('Jean Dupont')
        ->assertSee('Awa Bello')
        ->assertSee('1/2');
});

it('toggles an attendance present flag', function () {
    $meetingSession = MeetingSession::factory()->create();
    $attendance = Attendance::factory()->for($meetingSession)->create(['present' => true]);

    $this->actingAs(User::factory()->create())
        ->patch(route('admin.attendances.toggle-present', $attendance))
        ->assertRedirect(route('admin.sessions.show', $meetingSession));

    expect($attendance->fresh()->present)->toBeFalse();
});

it('requires authentication to toggle presence', function () {
    $attendance = Attendance::factory()->create();

    $this->patch(route('admin.attendances.toggle-present', $attendance))
        ->assertRedirect(route('admin.login'));

    expect($attendance->fresh()->present)->toBeTrue();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=AttendanceDashboardTest`
Expected: FAIL — route `admin.sessions.show` not defined.

- [ ] **Step 3: Add `show` to the sessions controller**

In `app/Http/Controllers/Admin/MeetingSessionController.php`, add this method (and the `View` import is already present):
```php
    public function show(MeetingSession $meetingSession): View
    {
        return view('admin.sessions.show', [
            'meetingSession' => $meetingSession,
            'attendances' => $meetingSession->attendances,
        ]);
    }
```

- [ ] **Step 4: Write the attendance toggle controller**

Create `app/Http/Controllers/Admin/AttendanceController.php`:
```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use Illuminate\Http\RedirectResponse;

class AttendanceController extends Controller
{
    public function togglePresent(Attendance $attendance): RedirectResponse
    {
        $attendance->update(['present' => ! $attendance->present]);

        return redirect()->route('admin.sessions.show', $attendance->meeting_session_id);
    }
}
```

- [ ] **Step 5: Register the routes**

In `routes/web.php`, add to the `auth` group (after `sessions.toggle-open`):
```php
        Route::get('sessions/{meetingSession}', [MeetingSessionController::class, 'show'])->name('sessions.show');
        Route::patch('attendances/{attendance}/toggle-present', [AttendanceController::class, 'togglePresent'])->name('attendances.toggle-present');
```
And add the import:
```php
use App\Http\Controllers\Admin\AttendanceController;
```

- [ ] **Step 6: Add the Alpine dashboard component**

In `resources/js/app.js`, replace the contents with:
```js
import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.data('attendanceDashboard', (records) => ({
    records,
    search: '',
    activeCategory: 'all',
    get filtered() {
        const search = this.search.toLowerCase();

        return this.records.filter((record) => {
            const matchesCategory = this.activeCategory === 'all' || record.category === this.activeCategory;
            const matchesSearch = record.name.toLowerCase().includes(search);

            return matchesCategory && matchesSearch;
        });
    },
    get groups() {
        const order = ['officials', 'members', 'rotaractors', 'guests'];

        return order
            .map((category) => ({
                category,
                records: this.filtered.filter((record) => record.category === category),
            }))
            .filter((group) => group.records.length > 0);
    },
    initials(name) {
        return name
            .split(' ')
            .filter(Boolean)
            .slice(0, 2)
            .map((part) => part[0])
            .join('')
            .toUpperCase();
    },
}));

Alpine.start();
```

- [ ] **Step 7: Create the dashboard view**

Create `resources/views/admin/sessions/show.blade.php`:
```blade
<x-layouts.admin :title="$meetingSession->title . ' — Dashboard'">
    <div
        x-data="attendanceDashboard(@js($attendances->map(fn ($attendance) => [
            'id' => $attendance->id,
            'name' => $attendance->name,
            'title' => $attendance->title->value,
            'club' => $attendance->club,
            'phone' => $attendance->phone,
            'category' => $attendance->category->value,
            'categoryLabel' => $attendance->category->label(),
            'present' => $attendance->present,
            'isLate' => $attendance->is_late,
        ])))"
        class="rounded-xl bg-white shadow-[0_2px_10px_rgba(20,30,50,.06)]"
    >
        <div class="border-b border-[#EDEAE2] px-8 pb-5 pt-7">
            <p class="text-[11px] font-semibold uppercase text-[#C77700]">RC Cotonou Nexus · District 9103</p>
            <div class="mt-1 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="font-display text-2xl font-extrabold text-[#12213D]">{{ $meetingSession->title }}</h1>
                    <p class="text-[15px] text-[#6B6558]">{{ $meetingSession->date->translatedFormat('d F Y') }}</p>
                </div>
                <div class="flex items-center gap-3">
                    <a href="{{ route('admin.sessions.export-pdf', $meetingSession) }}"
                        class="rounded-lg bg-[#12213D] px-4 py-2 text-sm font-bold text-white hover:bg-[#1c3559]">
                        Exporter en PDF
                    </a>
                    <span class="rounded-full {{ $meetingSession->is_open ? 'bg-[#E7F5F1] text-[#0E7C66]' : 'bg-[#F1EFEA] text-[#6B6558]' }} px-3 py-1 text-xs font-semibold">
                        ● {{ $meetingSession->is_open ? 'Séance ouverte' : 'Séance clôturée' }}
                    </span>
                    <form method="POST" action="{{ route('admin.sessions.toggle-open', $meetingSession) }}">
                        @csrf
                        <button type="submit" class="text-sm font-semibold text-[#12213D] underline">
                            {{ $meetingSession->is_open ? 'Clôturer la séance' : 'Rouvrir la séance' }}
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-3 px-8 py-5 md:grid-cols-5">
            <div class="rounded-lg bg-[#12213D] p-3 text-white">
                <p class="text-lg font-extrabold">{{ $attendances->where('present', true)->count() }}/{{ $attendances->count() }}</p>
                <p class="text-xs">Présents ({{ $attendances->count() > 0 ? round($attendances->where('present', true)->count() / $attendances->count() * 100) : 0 }}%)</p>
            </div>
            @foreach (\App\Enums\AttendanceCategory::cases() as $category)
                @php $categoryCount = $attendances->filter(fn ($attendance) => $attendance->category === $category)->count(); @endphp
                <div class="rounded-lg p-3" style="background-color: {{ $category->colors()['bg'] }}; color: {{ $category->colors()['accent'] }}">
                    <p class="text-lg font-extrabold">{{ $categoryCount }}</p>
                    <p class="text-xs">{{ $category->label() }}</p>
                </div>
            @endforeach
        </div>

        <div class="flex flex-wrap items-center gap-3 px-8 py-4">
            <input type="text" x-model="search" placeholder="Rechercher un nom…"
                class="max-w-[280px] rounded-full border border-[#DEDAD0] px-4 py-2 text-sm">
            <button type="button" @click="activeCategory = 'all'"
                :class="activeCategory === 'all' ? 'bg-[#12213D] text-white' : 'border border-[#DEDAD0]'"
                class="rounded-full px-3 py-1.5 text-xs font-semibold">Tous</button>
            @foreach (\App\Enums\AttendanceCategory::cases() as $category)
                <button type="button" @click="activeCategory = '{{ $category->value }}'"
                    :class="activeCategory === '{{ $category->value }}' ? 'bg-[#12213D] text-white' : 'border border-[#DEDAD0]'"
                    class="rounded-full px-3 py-1.5 text-xs font-semibold">{{ $category->label() }}</button>
            @endforeach
        </div>

        <div class="max-h-[520px] overflow-y-auto px-8 pb-8">
            <template x-for="group in groups" :key="group.category">
                <div class="mb-5">
                    <p class="mb-2 text-xs font-semibold uppercase text-[#8A8474]" x-text="group.records[0].categoryLabel + ' (' + group.records.length + ')'"></p>
                    <template x-for="record in group.records" :key="record.id">
                        <div class="flex items-center justify-between border-b border-[#F2F0EA] py-2.5">
                            <div class="flex items-center gap-3">
                                <div class="flex h-[34px] w-[34px] items-center justify-center rounded-full bg-[#F1EFEA] text-xs font-bold" x-text="initials(record.name)"></div>
                                <div>
                                    <p class="text-[14.5px] font-semibold text-[#12213D]" x-text="record.name"></p>
                                    <p class="text-[12.5px] text-[#8A8474]">
                                        <span x-text="record.title + ' · ' + record.club"></span>
                                        <span x-show="record.isLate" class="font-bold text-[#C77700]"> · marqué en retard</span>
                                    </p>
                                </div>
                            </div>
                            <span class="font-mono text-sm text-[#A39C8C]" x-text="record.phone"></span>
                            <form method="POST" :action="'/admin/attendances/' + record.id + '/toggle-present'">
                                @csrf
                                @method('PATCH')
                                <button type="submit"
                                    :class="record.present ? 'bg-[#E7F5F1] text-[#0E7C66]' : 'border border-[#DEDAD0] text-[#6B6558]'"
                                    class="rounded-lg px-3 py-1.5 text-xs font-semibold">
                                    <span x-text="record.present ? 'Présent' : 'Marquer présent'"></span>
                                </button>
                            </form>
                        </div>
                    </template>
                </div>
            </template>
        </div>
    </div>
</x-layouts.admin>
```

Note: this view references `route('admin.sessions.export-pdf', ...)`, which Task 10 registers. Since it's only resolved when the view is rendered, the `AttendanceDashboardTest` tests above (which don't check for that link's `href` value) still pass once Task 10 lands; render this task's test run only after confirming the route helper doesn't throw — if it does, temporarily comment the export link, finish Task 10, then uncomment. In practice, do Task 10 immediately after this one so the gap is momentary.

- [ ] **Step 8: Run the tests to verify they pass**

Run: `npm run build && php artisan test --compact --filter=AttendanceDashboardTest`
Expected: PASS (4 tests).

- [ ] **Step 9: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Admin resources/views/admin/sessions/show.blade.php resources/js/app.js routes/web.php tests/Feature/Admin/AttendanceDashboardTest.php
git commit -m "feat: add admin dashboard with counters, Alpine search/filter, and presence toggle"
```

---

### Task 10: PDF export

**Files:**
- Modify: `app/Http/Controllers/Admin/MeetingSessionController.php` (add `exportPdf`)
- Create: `resources/views/admin/sessions/pdf.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Admin/AttendancePdfExportTest.php`

**Interfaces:**
- Consumes: `MeetingSession`, `Attendance`, `AttendanceCategory` (Tasks 2–4), `Barryvdh\DomPDF\Facade\Pdf` (Task 1).
- Produces: named route `admin.sessions.export-pdf` (`GET /admin/sessions/{meetingSession}/export-pdf`).

- [ ] **Step 1: Write the failing test**

Run: `php artisan make:test --pest Admin/AttendancePdfExportTest --no-interaction`

Replace the generated file's contents with:
```php
<?php

use App\Enums\AttendanceTitle;
use App\Models\Attendance;
use App\Models\MeetingSession;
use App\Models\User;

it('requires authentication to export the PDF', function () {
    $meetingSession = MeetingSession::factory()->create();

    $this->get(route('admin.sessions.export-pdf', $meetingSession))
        ->assertRedirect(route('admin.login'));
});

it('downloads a PDF grouped by category for an authenticated admin', function () {
    $meetingSession = MeetingSession::factory()->create();
    Attendance::factory()->for($meetingSession)->create(['title' => AttendanceTitle::Rotarien]);

    $response = $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.export-pdf', $meetingSession));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toBe('application/pdf');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=AttendancePdfExportTest`
Expected: FAIL — route `admin.sessions.export-pdf` not defined.

- [ ] **Step 3: Add `exportPdf` to the sessions controller**

In `app/Http/Controllers/Admin/MeetingSessionController.php`, add the import:
```php
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
```
and the method:
```php
    public function exportPdf(MeetingSession $meetingSession): Response
    {
        $pdf = Pdf::loadView('admin.sessions.pdf', [
            'meetingSession' => $meetingSession,
            'attendances' => $meetingSession->attendances,
        ]);

        return $pdf->download("liste-presence-{$meetingSession->id}.pdf");
    }
```

- [ ] **Step 4: Register the route**

In `routes/web.php`, add to the `auth` group (after `sessions.show`):
```php
        Route::get('sessions/{meetingSession}/export-pdf', [MeetingSessionController::class, 'exportPdf'])->name('sessions.export-pdf');
```

- [ ] **Step 5: Create the PDF view**

Create `resources/views/admin/sessions/pdf.blade.php`:
```blade
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; color: #12213D; }
        h1 { font-size: 18px; margin-bottom: 2px; }
        p.subtitle { color: #6B6558; margin-top: 0; }
        h2 { font-size: 13px; margin-top: 18px; margin-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 4px 6px; border-bottom: 1px solid #EDEAE2; font-size: 11px; }
    </style>
</head>
<body>
    <h1>{{ $meetingSession->title }}</h1>
    <p class="subtitle">{{ $meetingSession->date->translatedFormat('d F Y') }} — RC Cotonou Nexus, District 9103</p>

    @foreach (\App\Enums\AttendanceCategory::cases() as $category)
        @php $categoryAttendances = $attendances->filter(fn ($attendance) => $attendance->category === $category); @endphp
        @if ($categoryAttendances->isNotEmpty())
            <h2>{{ $category->label() }} ({{ $categoryAttendances->count() }})</h2>
            <table>
                <thead>
                    <tr>
                        <th>Nom</th>
                        <th>Titre</th>
                        <th>Club</th>
                        <th>Téléphone</th>
                        <th>Présent</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($categoryAttendances as $attendance)
                        <tr>
                            <td>{{ $attendance->name }}</td>
                            <td>{{ $attendance->title->value }}</td>
                            <td>{{ $attendance->club }}</td>
                            <td>{{ $attendance->phone }}</td>
                            <td>{{ $attendance->present ? 'Oui' : 'Non' }}{{ $attendance->is_late ? ' (retard)' : '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endforeach
</body>
</html>
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test --compact --filter=AttendancePdfExportTest`
Expected: PASS (2 tests).

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Admin/MeetingSessionController.php resources/views/admin/sessions/pdf.blade.php routes/web.php tests/Feature/Admin/AttendancePdfExportTest.php
git commit -m "feat: add PDF export of the attendance list grouped by category"
```

---

### Task 11: Final verification

**Files:**
- Delete: `tests/Feature/ExampleTest.php`, `tests/Unit/ExampleTest.php` (Laravel starter placeholders, superseded by real coverage)
- No new source files — this task only verifies the whole module end-to-end.

- [ ] **Step 1: Remove the starter placeholder tests**

Run: `rm tests/Feature/ExampleTest.php tests/Unit/ExampleTest.php`

- [ ] **Step 2: Run the full automated test suite**

Run: `php artisan test --compact`
Expected: all tests pass (enums, models, public form, admin auth, session management, dashboard, PDF export).

- [ ] **Step 3: Run Pint across the whole app**

Run: `vendor/bin/pint --format agent`
Expected: no style violations remain (auto-fixed).

- [ ] **Step 4: Build frontend assets**

Run: `npm run build`
Expected: exits 0.

- [ ] **Step 5: Manual browser smoke test**

Run: `composer run dev` (starts the Artisan server, queue listener, and Vite dev server together) in the background, then in a browser:
1. Visit `/` with no session in the DB → see "Aucune séance en cours".
2. Log in at `/admin/login` with `admin@rotarynexus.test` / `password`.
3. Create a session from `/admin/sessions` → confirm redirect to its dashboard, counters at 0.
4. Visit `/` in another tab → confirm the new session's form appears, submit it → confirm the confirmation screen.
5. Back on the dashboard, reload → confirm the new attendee appears in the right category group, search/filter chips work, "Présent"/"Marquer présent" toggles and persists after reload.
6. Clôturer la séance from the dashboard → visit `/` → confirm the "porte fermée" screen and that "Marquer ma présence en retard" reveals the form with the orange banner; submit it → confirm it shows as "marqué en retard" on the dashboard after reload.
7. Click "Exporter en PDF" → confirm a PDF downloads and opens with the roster grouped by category.
8. Log out → confirm `/admin/sessions` redirects back to the login form.

Stop the `composer run dev` process once verified.

- [ ] **Step 6: Commit the cleanup**

```bash
git add tests
git commit -m "chore: remove Laravel starter placeholder tests"
```

## Self-Review Notes

- Spec coverage: public form (all 4 states), admin auth, session CRUD + auto-activation, dashboard (counters, search/filter, grouping, presence toggle, open/close toggle), PDF export, and the data model are each covered by a task with real code and a real test.
- No `TBD`/placeholder steps remain; every code-bearing step includes complete file contents.
- Naming is consistent across tasks: `MeetingSession`, `Attendance`, `AttendanceTitle`, `AttendanceCategory`, `admin.sessions.*`, `admin.attendances.toggle-present`, `attendanceDashboard` Alpine component — verified no drift between the task that defines each symbol and the tasks that consume it.
- Two forward references are called out explicitly where a task's redirect/link target is registered by a later task (Task 7 → 8, Task 9 → 10); both are safe because they're only resolved at request/render time, not at route-registration time, and are resolved within one task of being introduced.
