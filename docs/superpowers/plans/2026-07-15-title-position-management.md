# Titre + Poste Management Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the hardcoded `AttendanceTitle` enum with admin-managed `Title` and `Position` (poste) models, many-to-many linked, and switch the check-in form + admin member form to a cascading titre-then-poste selection.

**Architecture:** Two new Eloquent models (`Title`, `Position`) with a `position_title` pivot table. `Member` and `Attendance` gain `title_id`/`position_id` foreign keys replacing their `title` string column. The public check-in form and admin member-edit form render a titre select plus a poste select that Alpine.js filters client-side from a JSON map embedded in the page; server-side validation independently enforces that the poste belongs to the submitted titre. New `Admin\TitleController`/`Admin\PositionController` provide CRUD, with the titre edit screen managing the pivot via checkboxes.

**Tech Stack:** Laravel 13, PHP 8.4, Pest 4, Alpine.js (already in use), Tailwind v4 (existing admin/public design tokens).

## Global Constraints

- Follow existing repo conventions exactly: thin controllers, one `FormRequest` per write action, Blade views under `resources/views/admin/<resource>/`, routes under the `admin.` prefix inside the existing `auth`-protected group in `routes/web.php`.
- No `destroy` actions for `Title`/`Position` (matches `MemberController`, which has none) — deletion is prevented at the DB level via `restrictOnDelete`.
- No Livewire/Vue/AJAX — cascading titre→poste selection is done client-side with Alpine from data embedded once per page load.
- Run `vendor/bin/pint --dirty --format agent` after every task that touches PHP files, before committing.
- Run `php artisan test --compact` (or `--filter=...` while iterating) after every task; all tests must pass before moving to the next task.
- Titles seed data: `Rotary` (category `members`), `Rotaract` (`rotaractors`), `JCI`, `Lions`, `Inner Wheel`, `RRD`, `Invité` (all four `guests`).
- Positions seed data: `PDG, DG, DGE, DGN, AdG, PAdG, Past Président, Président, Président Elu, Président Nommé, Secrétaire, Trésorier, Protocole, Président de Commission, Vice-Président, Membre`.
- Pivot seed: `Rotary` → all 16 positions. `Rotaract`, `JCI`, `Lions`, `Inner Wheel`, `RRD` → `Président, Vice-Président, Secrétaire, Trésorier, Membre`. `Invité` → none.
- Backfill mapping (old `AttendanceTitle` value → new titre/poste), exact and lossless:

  | old value | new titre | new poste |
  |---|---|---|
  | PDG, DG, DGE, DGN, AdG, PAdG, Past Président, Président, Président Elu, Président Nommé, Secrétaire, Trésorier, Protocole, Président de Commission | Rotary | *(same name)* |
  | Rotarien | Rotary | Membre |
  | Rotaractien | Rotaract | Membre |
  | Invité | Invité | *(null)* |

- Spec: `docs/superpowers/specs/2026-07-15-title-position-management-design.md`

---

### Task 1: Title & Position models, migrations, factories

**Files:**
- Create: `database/migrations/2026_07_15_120000_create_titles_table.php`
- Create: `database/migrations/2026_07_15_120001_create_positions_table.php`
- Create: `database/migrations/2026_07_15_120002_create_position_title_table.php`
- Create: `app/Models/Title.php`
- Create: `app/Models/Position.php`
- Create: `database/factories/TitleFactory.php`
- Create: `database/factories/PositionFactory.php`
- Test: `tests/Feature/Models/TitleTest.php`

**Interfaces:**
- Produces: `Title` model with `name: string`, `category: AttendanceCategory` (cast), `positions(): BelongsToMany`. `Position` model with `name: string`, `titles(): BelongsToMany`. `Title::factory()`, `Position::factory()`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\AttendanceCategory;
use App\Models\Position;
use App\Models\Title;

it('casts category to the AttendanceCategory enum', function () {
    $title = Title::factory()->create(['category' => AttendanceCategory::Guests]);

    expect($title->category)->toBe(AttendanceCategory::Guests);
});

it('can have many positions attached', function () {
    $title = Title::factory()->create();
    $position = Position::factory()->create(['name' => 'Président']);

    $title->positions()->attach($position);

    expect($title->positions()->pluck('name')->all())->toBe(['Président']);
});

it('exposes the titles a position is linked to', function () {
    $position = Position::factory()->create();
    $titleA = Title::factory()->create();
    $titleB = Title::factory()->create();

    $position->titles()->attach([$titleA->id, $titleB->id]);

    expect($position->titles()->pluck('id')->sort()->values()->all())
        ->toBe([$titleA->id, $titleB->id]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TitleTest`
Expected: FAIL — `Class "App\Models\Title" not found`.

- [ ] **Step 3: Create the migrations**

```php
// database/migrations/2026_07_15_120000_create_titles_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('titles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('category');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('titles');
    }
};
```

```php
// database/migrations/2026_07_15_120001_create_positions_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('positions');
    }
};
```

```php
// database/migrations/2026_07_15_120002_create_position_title_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('position_title', function (Blueprint $table) {
            $table->foreignId('title_id')->constrained()->cascadeOnDelete();
            $table->foreignId('position_id')->constrained()->cascadeOnDelete();
            $table->primary(['title_id', 'position_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('position_title');
    }
};
```

- [ ] **Step 4: Create the models**

```php
// app/Models/Title.php
<?php

namespace App\Models;

use App\Enums\AttendanceCategory;
use Database\Factories\TitleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Title extends Model
{
    /** @use HasFactory<TitleFactory> */
    use HasFactory;

    protected $fillable = ['name', 'category'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['category' => AttendanceCategory::class];
    }

    public function positions(): BelongsToMany
    {
        return $this->belongsToMany(Position::class);
    }
}
```

```php
// app/Models/Position.php
<?php

namespace App\Models;

use Database\Factories\PositionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Position extends Model
{
    /** @use HasFactory<PositionFactory> */
    use HasFactory;

    protected $fillable = ['name'];

    public function titles(): BelongsToMany
    {
        return $this->belongsToMany(Title::class);
    }
}
```

- [ ] **Step 5: Create the factories**

```php
// database/factories/TitleFactory.php
<?php

namespace Database\Factories;

use App\Enums\AttendanceCategory;
use App\Models\Title;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Title>
 */
class TitleFactory extends Factory
{
    protected $model = Title::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'category' => fake()->randomElement(AttendanceCategory::cases()),
        ];
    }
}
```

```php
// database/factories/PositionFactory.php
<?php

namespace Database\Factories;

use App\Models\Position;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Position>
 */
class PositionFactory extends Factory
{
    protected $model = Position::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->jobTitle(),
        ];
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=TitleTest`
Expected: PASS (3 tests).

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/Title.php app/Models/Position.php \
  database/factories/TitleFactory.php database/factories/PositionFactory.php \
  database/migrations/2026_07_15_120000_create_titles_table.php \
  database/migrations/2026_07_15_120001_create_positions_table.php \
  database/migrations/2026_07_15_120002_create_position_title_table.php \
  tests/Feature/Models/TitleTest.php
git commit -m "Add Title and Position models with many-to-many pivot"
```

---

### Task 2: Seed initial titles, positions, and their pivot links

**Files:**
- Create: `database/migrations/2026_07_15_120003_seed_titles_and_positions.php`
- Test: `tests/Feature/Migrations/SeedTitlesAndPositionsTest.php`

**Interfaces:**
- Consumes: `titles`, `positions`, `position_title` tables from Task 1.
- Produces: seeded rows any later task/test can rely on existing in a fresh database (via `RefreshDatabase`, which runs every migration).

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Position;
use App\Models\Title;

it('seeds the starter titles with their categories', function () {
    expect(Title::pluck('category', 'name')->map(fn ($category) => $category->value)->all())->toBe([
        'Rotary' => 'members',
        'Rotaract' => 'rotaractors',
        'JCI' => 'guests',
        'Lions' => 'guests',
        'Inner Wheel' => 'guests',
        'RRD' => 'guests',
        'Invité' => 'guests',
    ]);
});

it('seeds the starter positions', function () {
    // Sort both sides with the same PHP `sort()` call rather than hand-typing
    // an expected order — PHP's default string comparison is byte-wise, not
    // locale-aware French collation, so accented names don't sort where a
    // human would expect (e.g. "Protocole" sorts before "Président").
    $expected = collect([
        'PDG', 'DG', 'DGE', 'DGN', 'AdG', 'PAdG', 'Past Président', 'Président',
        'Président Elu', 'Président Nommé', 'Secrétaire', 'Trésorier', 'Protocole',
        'Président de Commission', 'Vice-Président', 'Membre',
    ])->sort()->values()->all();

    expect(Position::pluck('name')->sort()->values()->all())->toBe($expected);
});

it('links Rotary to every seeded position', function () {
    $rotary = Title::where('name', 'Rotary')->sole();

    expect($rotary->positions()->count())->toBe(16);
});

it('links Invité to no positions', function () {
    $invite = Title::where('name', 'Invité')->sole();

    expect($invite->positions()->count())->toBe(0);
});

it('links JCI to the five generic officer positions', function () {
    $jci = Title::where('name', 'JCI')->sole();

    expect($jci->positions()->pluck('name')->sort()->values()->all())
        ->toBe(['Membre', 'Président', 'Secrétaire', 'Trésorier', 'Vice-Président']);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SeedTitlesAndPositionsTest`
Expected: FAIL — all assertions fail, tables are empty.

- [ ] **Step 3: Write the seed migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $titles = [
            ['name' => 'Rotary', 'category' => 'members'],
            ['name' => 'Rotaract', 'category' => 'rotaractors'],
            ['name' => 'JCI', 'category' => 'guests'],
            ['name' => 'Lions', 'category' => 'guests'],
            ['name' => 'Inner Wheel', 'category' => 'guests'],
            ['name' => 'RRD', 'category' => 'guests'],
            ['name' => 'Invité', 'category' => 'guests'],
        ];

        $titleIds = [];
        foreach ($titles as $title) {
            $titleIds[$title['name']] = DB::table('titles')->insertGetId([
                ...$title,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $positionNames = [
            'PDG', 'DG', 'DGE', 'DGN', 'AdG', 'PAdG', 'Past Président', 'Président',
            'Président Elu', 'Président Nommé', 'Secrétaire', 'Trésorier', 'Protocole',
            'Président de Commission', 'Vice-Président', 'Membre',
        ];

        $positionIds = [];
        foreach ($positionNames as $name) {
            $positionIds[$name] = DB::table('positions')->insertGetId([
                'name' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $genericOfficerPositions = ['Président', 'Vice-Président', 'Secrétaire', 'Trésorier', 'Membre'];

        $pivot = [
            ...array_map(
                fn (string $name) => ['title_id' => $titleIds['Rotary'], 'position_id' => $positionIds[$name]],
                $positionNames,
            ),
        ];

        foreach (['Rotaract', 'JCI', 'Lions', 'Inner Wheel', 'RRD'] as $titleName) {
            foreach ($genericOfficerPositions as $positionName) {
                $pivot[] = ['title_id' => $titleIds[$titleName], 'position_id' => $positionIds[$positionName]];
            }
        }

        DB::table('position_title')->insert($pivot);
    }

    public function down(): void
    {
        DB::table('position_title')->truncate();
        DB::table('positions')->truncate();
        DB::table('titles')->truncate();
    }
};
```

Save as `database/migrations/2026_07_15_120003_seed_titles_and_positions.php`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=SeedTitlesAndPositionsTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_07_15_120003_seed_titles_and_positions.php \
  tests/Feature/Migrations/SeedTitlesAndPositionsTest.php
git commit -m "Seed starter titles, positions, and their pivot links"
```

---

### Task 3: Add title_id/position_id columns and backfill from the old title column

**Files:**
- Create: `database/migrations/2026_07_15_120004_add_title_and_position_to_members_table.php`
- Create: `database/migrations/2026_07_15_120005_add_title_and_position_to_attendances_table.php`
- Create: `database/migrations/2026_07_15_120006_backfill_title_and_position_ids.php`
- Test: `tests/Feature/Migrations/BackfillTitleAndPositionIdsTest.php`

**Interfaces:**
- Consumes: `members.title`/`attendances.title` (still the old string enum column, untouched by this task), `titles`/`positions` seeded in Task 2.
- Produces: `members.title_id`, `members.position_id`, `attendances.title_id`, `attendances.position_id` — all nullable, all populated for every existing row after this task. The old `title` column still exists (dropped only in Task 7) so nothing that reads it today breaks yet.

- [ ] **Step 1: Write the failing test**

Follow the same pattern as the existing
`tests/Feature/BackfillMembersFromAttendancesTest.php`: `include` the
migration file directly and call `->up()` on it, rather than going through
Artisan. This is required, not just stylistic — `RefreshDatabase` already
applies every migration under `database/migrations/` (including the two
schema migrations and the backfill migration itself) before each test runs,
so by the time this test body executes, the `migrations` table already
marks the backfill migration as applied against an empty table. Calling
`Artisan::call('migrate', ...)` again would silently no-op (Artisan skips
migrations it already tracks as run) — `include $path; $migration->up();`
bypasses that tracking and re-runs the migration's logic directly against
the rows this test just inserted.

```php
<?php

use Illuminate\Support\Facades\DB;

it('backfills title_id and position_id for every old AttendanceTitle value', function () {
    $rotaryPositions = [
        'PDG', 'DG', 'DGE', 'DGN', 'AdG', 'PAdG', 'Past Président', 'Président',
        'Président Elu', 'Président Nommé', 'Secrétaire', 'Trésorier', 'Protocole',
        'Président de Commission',
    ];

    $meetingSessionId = DB::table('meeting_sessions')->insertGetId([
        'title' => 'Réunion test', 'date' => '2026-01-01', 'time' => '18:00',
        'is_active' => false, 'is_open' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);

    foreach ([...$rotaryPositions, 'Rotarien', 'Rotaractien', 'Invité'] as $oldValue) {
        DB::table('attendances')->insert([
            'meeting_session_id' => $meetingSessionId, 'title' => $oldValue, 'name' => $oldValue,
            'club' => 'RC Cotonou Ife', 'phone' => '+229 90 00 00 00', 'present' => true,
            'is_late' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // These rows now have title_id/position_id = null (columns added by the
    // schema migrations from Step 3, which RefreshDatabase already ran).
    // Re-run the backfill migration's logic directly against them.
    $migrationPath = glob(database_path('migrations/*_backfill_title_and_position_ids.php'))[0];
    $migration = include $migrationPath;
    $migration->up();

    foreach ($rotaryPositions as $positionName) {
        $row = DB::table('attendances')->join('titles', 'attendances.title_id', '=', 'titles.id')
            ->leftJoin('positions', 'attendances.position_id', '=', 'positions.id')
            ->where('attendances.title', $positionName)
            ->select('titles.name as title_name', 'positions.name as position_name')
            ->sole();

        expect($row->title_name)->toBe('Rotary')->and($row->position_name)->toBe($positionName);
    }

    $rotarien = DB::table('attendances')->join('titles', 'attendances.title_id', '=', 'titles.id')
        ->leftJoin('positions', 'attendances.position_id', '=', 'positions.id')
        ->where('attendances.title', 'Rotarien')
        ->select('titles.name as title_name', 'positions.name as position_name')->sole();
    expect($rotarien->title_name)->toBe('Rotary')->and($rotarien->position_name)->toBe('Membre');

    $rotaractien = DB::table('attendances')->join('titles', 'attendances.title_id', '=', 'titles.id')
        ->leftJoin('positions', 'attendances.position_id', '=', 'positions.id')
        ->where('attendances.title', 'Rotaractien')
        ->select('titles.name as title_name', 'positions.name as position_name')->sole();
    expect($rotaractien->title_name)->toBe('Rotaract')->and($rotaractien->position_name)->toBe('Membre');

    $invite = DB::table('attendances')->join('titles', 'attendances.title_id', '=', 'titles.id')
        ->where('attendances.title', 'Invité')
        ->select('titles.name as title_name', 'attendances.position_id')->sole();
    expect($invite->title_name)->toBe('Invité')->and($invite->position_id)->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=BackfillTitleAndPositionIdsTest`
Expected: FAIL — `glob()` returns an empty array (no migration file matches
yet), so `$migrationPath` access errors.

- [ ] **Step 3: Write the two schema migrations**

```php
// database/migrations/2026_07_15_120004_add_title_and_position_to_members_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->foreignId('title_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            $table->foreignId('position_id')->nullable()->after('title_id')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropConstrainedForeignId('title_id');
            $table->dropConstrainedForeignId('position_id');
        });
    }
};
```

```php
// database/migrations/2026_07_15_120005_add_title_and_position_to_attendances_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->foreignId('title_id')->nullable()->after('member_id')->constrained()->restrictOnDelete();
            $table->foreignId('position_id')->nullable()->after('title_id')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('title_id');
            $table->dropConstrainedForeignId('position_id');
        });
    }
};
```

- [ ] **Step 4: Write the backfill migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    private function mapping(): array
    {
        $rotaryPositions = [
            'PDG', 'DG', 'DGE', 'DGN', 'AdG', 'PAdG', 'Past Président', 'Président',
            'Président Elu', 'Président Nommé', 'Secrétaire', 'Trésorier', 'Protocole',
            'Président de Commission',
        ];

        $map = [];
        foreach ($rotaryPositions as $name) {
            $map[$name] = ['Rotary', $name];
        }
        $map['Rotarien'] = ['Rotary', 'Membre'];
        $map['Rotaractien'] = ['Rotaract', 'Membre'];
        $map['Invité'] = ['Invité', null];

        return $map;
    }

    public function up(): void
    {
        $titleIds = DB::table('titles')->pluck('id', 'name');
        $positionIds = DB::table('positions')->pluck('id', 'name');

        foreach ($this->mapping() as $oldValue => [$titleName, $positionName]) {
            $titleId = $titleIds[$titleName];
            $positionId = $positionName !== null ? $positionIds[$positionName] : null;

            foreach (['members', 'attendances'] as $table) {
                DB::table($table)
                    ->where('title', $oldValue)
                    ->update(['title_id' => $titleId, 'position_id' => $positionId]);
            }
        }
    }

    public function down(): void
    {
        DB::table('members')->update(['title_id' => null, 'position_id' => null]);
        DB::table('attendances')->update(['title_id' => null, 'position_id' => null]);
    }
};
```

Save as `database/migrations/2026_07_15_120006_backfill_title_and_position_ids.php`.

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=BackfillTitleAndPositionIdsTest`
Expected: PASS.

- [ ] **Step 6: Run the full suite to confirm nothing else broke**

Run: `php artisan test --compact`
Expected: PASS — every existing test still creates/reads the old `title`
string column exactly as before; nothing yet reads `title_id`.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_07_15_120004_add_title_and_position_to_members_table.php \
  database/migrations/2026_07_15_120005_add_title_and_position_to_attendances_table.php \
  database/migrations/2026_07_15_120006_backfill_title_and_position_ids.php \
  tests/Feature/Migrations/BackfillTitleAndPositionIdsTest.php
git commit -m "Add title_id/position_id columns and backfill from old title values"
```

---

### Addendum (discovered during Task 4): the legacy `title` column must be dropped now, not deferred to Task 7

Task 3 added `title_id`/`position_id` alongside the old `title` string
column on both tables, leaving `title` in place (originally `NOT NULL`, no
default) for the finalize migration to drop later (originally planned for
Task 7). This does not work: Eloquent's attribute resolution checks
`$this->attributes` (the row's raw DB columns) **before** it checks
relations or eager-loaded relations. As long as a real `title` column
exists on the table, `$attendance->title` resolves to that column's raw
value (a string, or `null` once nothing writes to it) for any model
instance that was hydrated from a real query — it never reaches the
`title(): BelongsTo` relation method of the same name, eager-loaded or not.
Verified empirically: for a model just returned from `Model::factory()->create()`
(never re-queried), `$attributes` never picked up the `title` key at all, so
`->title` happens to resolve via the relation — but the moment the same row
is re-fetched (`Model::find()`, `with('title')`, any real `SELECT *`), the
`title` key is present (even if `null`) and shadows the relation, making
`->title` return `null` unconditionally. This broke `Attendance::category`
(`$this->title->category`) for every DB-refetched row, and would have made
Task 6's planned eager-loading (`->with('title')`) silently useless — the
eager-loaded relation would never be reached either.

Fix: drop the old `title` column outright, immediately after Task 3's
backfill, rather than waiting for the finalize task. Nothing needs it
anymore — `title_id`/`position_id` already hold everything the backfill
produced, and `$fillable` guarding means no application code can
accidentally attempt to write to a dropped column (see Task 5/6, which only
touch `title_id`/`position_id`/the `title()` relation, never the raw
column).

`database/migrations/2026_07_15_120007_drop_legacy_title_column.php`,
applied before Task 4's model/factory changes:

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
            $table->dropColumn('title');
        });
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn('title');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->string('title')->nullable();
        });
        Schema::table('attendances', function (Blueprint $table) {
            $table->string('title')->nullable();
        });
    }
};
```

Task 7's finalize migration filename is renumbered from `120007` to
`120008` (`2026_07_15_120008_finalize_title_and_position_columns.php`) to
make room for this addendum in the timestamp sequence, and its job shrinks
to just tightening `title_id` to `NOT NULL` on both tables — the column
drop already happened here. See the updated Task 7 below.

Consequence for the six files Task 4's brief predicted would break: the
actual breakage after this fix is narrower and for more precise reasons —
`AttendanceFormTest`/`AttendanceMemberCheckInTest` still submit a `title`
form field that `StoreAttendanceRequest` still validates (unaffected by
this schema change, since validation reads request input, not the DB
column) and so continue to pass until Task 5 rewrites them;
`Unit/Enums/AttendanceTitleTest` tests the enum directly and is unaffected
until Task 7 deletes the enum file; `Admin/MemberManagementTest`'s "show"
case renders `$member->title->value` (`Title` has no `value` attribute, so
this now renders blank rather than crashing) but doesn't assert on the
displayed title text, so it passes even though the display is wrong until
Task 5 fixes it (see below). Only `Admin/AttendanceDashboardTest` (both
cases) and `Admin/AttendancePdfExportTest` are expected to actually **fail**
after Task 4 — both still call `Attendance::factory()->create(['title' =>
AttendanceTitle::Rotarien, ...])`, and since the `title` column is now
dropped entirely (not just unfillable), that insert errors outright with
"no such column." Task 4's Step 6 should be read as "confirm failures are
confined to these two files, for this column-doesn't-exist reason — not a
hard six-file list."

**Two more casualties of the column drop, discovered empirically**: dropping
`title` also breaks any test that manually re-invokes an *older* migration
whose own code reads `attendances.title`/`members.title` directly — this
codebase's established convention for testing one-time data migrations
(`include $migrationPath; $migration->up();`, used by Task 3's own backfill
test and by the pre-existing, unrelated `BackfillMembersFromAttendancesTest`
from an earlier shipped feature) only works as long as no *later* migration
removes a column the re-invoked migration touches. Once `title` is gone,
both break with "no such column: attendances.title" — not because either
migration's logic was wrong (both were correct when reviewed/shipped), but
because their precondition column no longer exists in a fully-migrated
database. This is the same lifecycle as `Unit/Enums/AttendanceTitleTest`
being retired in Task 7 once its subject (the enum) is deleted — a
migration test that can no longer reconstruct its precondition state is
retired, not fixed. Delete both, as part of this task:
- `tests/Feature/Migrations/BackfillTitleAndPositionIdsTest.php` (Task 3's
  own test — its migration's correctness was already verified and approved;
  this only retires the *test*, not the migration file, which stays and
  still ran correctly the one time it mattered)
- `tests/Feature/BackfillMembersFromAttendancesTest.php` (pre-existing,
  unrelated to this plan — same reasoning; its migration
  (`2026_07_14_214017_backfill_members_from_attendances.php`) already ran
  once in any real deployment and keeps its permanent effect regardless)

---

### Task 4: Cut over Member and Attendance models to Title/Position relations

**Files:**
- Create: `database/migrations/2026_07_15_120007_drop_legacy_title_column.php` (addendum above — must land before the model/factory changes below)
- Modify: `app/Models/Member.php`
- Modify: `app/Models/Attendance.php`
- Modify: `database/factories/MemberFactory.php`
- Modify: `database/factories/AttendanceFactory.php`
- Modify: `tests/Feature/Models/AttendanceTest.php`
- Delete: `tests/Feature/Migrations/BackfillTitleAndPositionIdsTest.php` (addendum above — retired now that `title` is dropped, its migration's correctness was already reviewed/approved in Task 3)
- Delete: `tests/Feature/BackfillMembersFromAttendancesTest.php` (addendum above — pre-existing, unrelated test retired for the same reason; its migration file stays untouched)

**Interfaces:**
- Consumes: `Title`, `Position` models (Task 1).
- Produces: `Member::title(): BelongsTo`, `Member::position(): BelongsTo`, same on `Attendance`; `Attendance::category` now reads `$this->title->category` (a plain property, not a method call). `MemberFactory`/`AttendanceFactory` no longer set `title`; they set `title_id`/`position_id` via `Title::factory()`/`Position::factory()`.

This task does NOT touch `StoreAttendanceRequest`, `UpdateMemberRequest`, or
any Blade view yet — those still reference the (now-removed) `title`
attribute. Per the addendum above, only `Admin/AttendanceDashboardTest` and
`Admin/AttendancePdfExportTest` are expected to go red after this task
(both still factory-create with `'title' => AttendanceTitle::X`, which now
errors since the column is gone) — they stay red until Task 6.
`AttendanceFormTest`, `AttendanceMemberCheckInTest`, and
`Admin/MemberManagementTest` continue to pass at this point (see addendum
for why) and are cut over cosmetically/for-correctness in Task 5. Do not
attempt to fix any of this task's expected `AttendanceDashboardTest`/
`AttendancePdfExportTest` breakage here — that's Task 6's job.

- [ ] **Step 1: Write the failing test**

Replace the two enum-dependent tests in `tests/Feature/Models/AttendanceTest.php`:

```php
<?php

use App\Enums\AttendanceCategory;
use App\Models\Attendance;
use App\Models\MeetingSession;
use App\Models\Title;

it('derives its category from its title', function () {
    $title = Title::factory()->create(['category' => AttendanceCategory::Rotaractors]);
    $attendance = Attendance::factory()->create(['title_id' => $title->id]);

    expect($attendance->category)->toBe(AttendanceCategory::Rotaractors);
});

it('belongs to a title and an optional position', function () {
    $title = Title::factory()->create();
    $attendance = Attendance::factory()->create(['title_id' => $title->id, 'position_id' => null]);

    expect($attendance->title->is($title))->toBeTrue()
        ->and($attendance->position)->toBeNull();
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

(Removes the old `'casts its title to the AttendanceTitle enum'` test — that
cast no longer exists; `title` is now a relation, not a cast attribute.)

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AttendanceTest`
Expected: FAIL — `Attendance::factory()->create(['title_id' => ...])` errors
because `title_id` isn't fillable yet and `AttendanceFactory` still sets
the old `title` enum column.

- [ ] **Step 3: Update the models**

```php
// app/Models/Member.php
<?php

namespace App\Models;

use Database\Factories\MemberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Member extends Model
{
    /** @use HasFactory<MemberFactory> */
    use HasFactory;

    protected $fillable = ['title_id', 'position_id', 'name', 'club', 'phone', 'classification', 'email'];

    public function title(): BelongsTo
    {
        return $this->belongsTo(Title::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
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

```php
// app/Models/Attendance.php
<?php

namespace App\Models;

use App\Enums\AttendanceCategory;
use Database\Factories\AttendanceFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    /** @use HasFactory<AttendanceFactory> */
    use HasFactory;

    protected $fillable = [
        'meeting_session_id', 'member_id', 'title_id', 'position_id', 'name', 'club', 'phone',
        'classification', 'email', 'present', 'is_late',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'present' => 'boolean',
            'is_late' => 'boolean',
        ];
    }

    public function meetingSession(): BelongsTo
    {
        return $this->belongsTo(MeetingSession::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function title(): BelongsTo
    {
        return $this->belongsTo(Title::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    protected function category(): Attribute
    {
        return Attribute::get(fn (): AttendanceCategory => $this->title->category);
    }
}
```

- [ ] **Step 4: Update the factories**

```php
// database/factories/MemberFactory.php
<?php

namespace Database\Factories;

use App\Models\Member;
use App\Models\Title;
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
            'title_id' => Title::factory(),
            'position_id' => null,
            'name' => fake()->name(),
            'club' => 'RC Cotonou Ife',
            'phone' => fake()->phoneNumber(),
            'classification' => null,
            'email' => fake()->unique()->safeEmail(),
        ];
    }
}
```

```php
// database/factories/AttendanceFactory.php
<?php

namespace Database\Factories;

use App\Models\Attendance;
use App\Models\MeetingSession;
use App\Models\Title;
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
            'title_id' => Title::factory(),
            'position_id' => null,
            'name' => fake()->name(),
            'club' => 'RC Cotonou Ife',
            'phone' => fake()->phoneNumber(),
            'classification' => null,
            'email' => null,
            'present' => true,
            'is_late' => false,
        ];
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=AttendanceTest`
Expected: PASS (4 tests).

- [ ] **Step 6: Confirm the expected breakage, nothing more**

Run: `php artisan test --compact`
Expected: FAIL only in `Admin/AttendanceDashboardTest` (both cases) and
`Admin/AttendancePdfExportTest` (one case) — all three still
factory-create an `Attendance` with `'title' => AttendanceTitle::Rotarien`
(or `::Invite`), which now errors with "no such column: attendances.title"
since the column was dropped. `AttendanceFormTest`,
`AttendanceMemberCheckInTest`, `Admin/MemberManagementTest`, and
`Unit/Enums/AttendanceTitleTest` are expected to keep passing (see the
addendum above for why each one doesn't yet surface a failure, even though
some render stale/blank titles). Every other suite (including
`Feature/Models/TitleTest`) must still pass — note
`tests/Feature/Migrations/BackfillTitleAndPositionIdsTest.php` and
`tests/Feature/BackfillMembersFromAttendancesTest.php` no longer exist,
deleted as part of this task per the addendum. If the failure set doesn't
match exactly — extra failures beyond these two files, or these two don't
fail — stop and investigate before proceeding.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_07_15_120007_drop_legacy_title_column.php \
  app/Models/Member.php app/Models/Attendance.php \
  database/factories/MemberFactory.php database/factories/AttendanceFactory.php \
  tests/Feature/Models/AttendanceTest.php
git rm tests/Feature/Migrations/BackfillTitleAndPositionIdsTest.php tests/Feature/BackfillMembersFromAttendancesTest.php
git commit -m "Cut over Member and Attendance models to Title/Position relations"
```

---

### Task 5: Cut over check-in form and member-edit form validation, views, and controller

**Files:**
- Modify: `app/Http/Requests/StoreAttendanceRequest.php`
- Modify: `app/Http/Requests/UpdateMemberRequest.php`
- Modify: `app/Http/Controllers/AttendanceFormController.php`
- Modify: `resources/views/components/attendance-form.blade.php`
- Modify: `resources/views/admin/members/edit.blade.php`
- Modify: `resources/views/admin/members/show.blade.php` (`$member->title->value` at line 26 — was missed in the original file scan; `Title` has no `value` attribute so this silently rendered blank after Task 4's column drop rather than crashing, but it must still become `$member->title->name`)
- Modify: `tests/Feature/AttendanceFormTest.php`
- Modify: `tests/Feature/AttendanceMemberCheckInTest.php`
- Modify: `tests/Feature/Admin/MemberManagementTest.php`

**Interfaces:**
- Consumes: `Title::with('positions')`, `title_id`/`position_id` fillable from Task 4.
- Produces: both forms POST `title_id`/`position_id` instead of `title`; validation rejects a missing poste when the titre has any, and rejects a poste that isn't linked to the submitted titre.

- [ ] **Step 1: Update the request classes**

```php
// app/Http/Requests/StoreAttendanceRequest.php
<?php

namespace App\Http\Requests;

use App\Models\Title;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

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
            'name' => ['required', 'string', 'max:255'],
            'club' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'classification' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
        ];
    }

    private function positionBelongsToTitle(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $title = Title::find($this->input('title_id'));

            if ($title === null) {
                return;
            }

            if ($value === null) {
                if ($title->positions()->exists()) {
                    $fail('Le poste/qualité est obligatoire pour ce titre.');
                }

                return;
            }

            if (! $title->positions()->whereKey($value)->exists()) {
                $fail('Le poste sélectionné ne correspond pas au titre choisi.');
            }
        };
    }
}
```

```php
// app/Http/Requests/UpdateMemberRequest.php
<?php

namespace App\Http\Requests;

use App\Models\Title;
use Closure;
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
            'title_id' => ['required', 'integer', 'exists:titles,id'],
            'position_id' => ['nullable', 'integer', 'exists:positions,id', $this->positionBelongsToTitle()],
            'name' => ['required', 'string', 'max:255'],
            'club' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'classification' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('members', 'email')->ignore($this->route('member'))],
        ];
    }

    private function positionBelongsToTitle(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $title = Title::find($this->input('title_id'));

            if ($title === null) {
                return;
            }

            if ($value === null) {
                if ($title->positions()->exists()) {
                    $fail('Le poste/qualité est obligatoire pour ce titre.');
                }

                return;
            }

            if (! $title->positions()->whereKey($value)->exists()) {
                $fail('Le poste sélectionné ne correspond pas au titre choisi.');
            }
        };
    }
}
```

- [ ] **Step 2: Update the controller to pass titles with positions to the view**

In `app/Http/Controllers/AttendanceFormController.php`, add `use App\Models\Title;`
and add `'titles' => Title::with('positions')->orderBy('name')->get(),` to
the `view('attendance.show', [...])` array in both `show()` and `lookup()`.
`store()` needs no change — it already spreads `$request->validated()` and
`$request->safe()->only([...])`, which will now include `title_id`/
`position_id` once the field names below change; update the `only([...])`
list in `store()`:

```php
$member = Member::updateOrCreate(
    ['email' => $email],
    $request->safe()->only(['title_id', 'position_id', 'name', 'club', 'phone', 'classification']),
);
```

- [ ] **Step 3: Update the check-in form view**

Replace the single "Titre / Qualité*" field block in
`resources/views/components/attendance-form.blade.php` (the block starting
`<label for="title" ...>` through its closing `</div>`) with:

```blade
<div x-data="{
        titleId: '{{ old('title_id', $member?->title_id) }}',
        positionId: '{{ old('position_id', $member?->position_id) }}',
        positionsByTitle: {{ Illuminate\Support\Js::from($titles->mapWithKeys(fn ($t) => [
            $t->id => $t->positions->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values(),
        ])) }},
        get availablePositions() { return this.positionsByTitle[this.titleId] ?? [] },
    }"
    class="contents"
>
    <div class="flex flex-col gap-1.5">
        <label for="title_id" class="text-sm font-semibold text-[#12213D]">Titre*</label>
        <select x-model="titleId" id="title_id" name="title_id" required
            class="rounded-lg border border-[#DEDAD0] px-3 py-2 text-sm">
            <option value="">Sélectionnez…</option>
            @foreach ($titles as $titleOption)
                <option value="{{ $titleOption->id }}">{{ $titleOption->name }}</option>
            @endforeach
        </select>
    </div>

    <div class="flex flex-col gap-1.5" x-show="availablePositions.length > 0">
        <label for="position_id" class="text-sm font-semibold text-[#12213D]">Poste / Qualité*</label>
        <select x-model="positionId" id="position_id" name="position_id" :required="availablePositions.length > 0"
            class="rounded-lg border border-[#DEDAD0] px-3 py-2 text-sm">
            <option value="">Sélectionnez…</option>
            <template x-for="position in availablePositions" :key="position.id">
                <option :value="position.id" x-text="position.name"></option>
            </template>
        </select>
    </div>
</div>
```

Add `@props(['late' => false, 'email', 'member' => null])` line's neighbor —
the component already receives `$member`; it also needs `$titles`, so update
line 1 to `@props(['late' => false, 'email', 'member' => null, 'titles'])`.
Then in `resources/views/attendance/show.blade.php`, wherever
`<x-attendance-form ... />` is rendered, add `:titles="$titles"` to the
component tag (there are two call sites — step-2-open and
step-2-closed/late-mode branches; add it to both).

- [ ] **Step 4: Update the admin member-edit form view**

In `resources/views/admin/members/edit.blade.php`, replace the `<select
id="title" name="title" ...>` block (built from `AttendanceTitle::cases()`)
with the same titre/poste pair and Alpine cascade as Step 3, sourced from
`$titles` (add `'titles' => Title::with('positions')->orderBy('name')->get(),`
to `MemberController::edit()`'s view data, importing `use App\Models\Title;`):

```blade
<div x-data="{
        titleId: '{{ old('title_id', $member->title_id) }}',
        positionId: '{{ old('position_id', $member->position_id) }}',
        positionsByTitle: {{ Illuminate\Support\Js::from($titles->mapWithKeys(fn ($t) => [
            $t->id => $t->positions->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values(),
        ])) }},
        get availablePositions() { return this.positionsByTitle[this.titleId] ?? [] },
    }"
    class="contents"
>
    <div class="flex flex-col gap-1.5">
        <label for="title_id" class="text-sm font-semibold">Titre</label>
        <select x-model="titleId" id="title_id" name="title_id" required
            class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            <option value="">Sélectionnez…</option>
            @foreach ($titles as $titleOption)
                <option value="{{ $titleOption->id }}">{{ $titleOption->name }}</option>
            @endforeach
        </select>
    </div>

    <div class="flex flex-col gap-1.5" x-show="availablePositions.length > 0">
        <label for="position_id" class="text-sm font-semibold">Poste / Qualité</label>
        <select x-model="positionId" id="position_id" name="position_id" :required="availablePositions.length > 0"
            class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            <option value="">Sélectionnez…</option>
            <template x-for="position in availablePositions" :key="position.id">
                <option :value="position.id" x-text="position.name"></option>
            </template>
        </select>
    </div>
</div>
```

- [ ] **Step 4b: Fix the member detail page's title display**

In `resources/views/admin/members/show.blade.php` line 26, change
`<dd>{{ $member->title->value }}</dd>` to
`<dd>{{ $member->title->name }}</dd>` — `Title` has no `value` attribute
(that was the enum's), so this line has been silently rendering blank
since Task 4 dropped the old `title` column, without failing any test
(`MemberManagementTest`'s "show" test doesn't assert on the title's
displayed text).

- [ ] **Step 5: Update `MemberController::edit`**

```php
public function edit(Member $member): View
{
    return view('admin.members.edit', [
        'member' => $member,
        'titles' => Title::with('positions')->orderBy('name')->get(),
    ]);
}
```

Add `use App\Models\Title;` to `app/Http/Controllers/Admin/MemberController.php`.

- [ ] **Step 6: Update the check-in feature tests**

In `tests/Feature/AttendanceFormTest.php`, remove `use App\Enums\AttendanceTitle;`
and replace every `'title' => AttendanceTitle::Rotarien->value` /
`AttendanceTitle::Invite->value` with a real seeded titre looked up by name
(the seed migration from Task 2 always runs under `RefreshDatabase`):

```php
use App\Models\Title;
// ...
'title_id' => Title::where('name', 'Rotary')->sole()->id,
'position_id' => Title::where('name', 'Rotary')->sole()->positions()->where('name', 'Membre')->sole()->id,
```

and for the Invité case:

```php
'title_id' => Title::where('name', 'Invité')->sole()->id,
```

(no `position_id` needed — Invité has none). Update the "rejects a
submission missing required fields" test's assertion from
`->assertSessionHasErrors(['title', 'club', 'phone', 'email'])` to
`->assertSessionHasErrors(['title_id', 'club', 'phone', 'email'])`.

In `tests/Feature/AttendanceMemberCheckInTest.php`, apply the same
`AttendanceTitle::X->value` → `Title::where('name', ...)->sole()->id`
substitution everywhere it appears, and update the "re-shows the pre-filled
confirmation form after a failed submission" test's assertion from
`->assertSessionHasErrors(['title', 'club', 'phone'])` to
`->assertSessionHasErrors(['title_id', 'club', 'phone'])`.

In `tests/Feature/Admin/MemberManagementTest.php`, replace both
`'title' => AttendanceTitle::Rotarien->value` occurrences with
`'title_id' => Title::where('name', 'Rotary')->sole()->id, 'position_id' =>
Title::where('name', 'Rotary')->sole()->positions()->where('name', 'Membre')->sole()->id,`
and remove the `use App\Enums\AttendanceTitle;` import, adding
`use App\Models\Title;` instead.

Add four new tests to `tests/Feature/AttendanceFormTest.php` covering the
`positionBelongsToTitle` validation rule from Step 1 — this is the rule the
spec calls out explicitly and it has no coverage yet:

```php
it('requires a position when the submitted title has linked positions', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);
    $rotary = Title::where('name', 'Rotary')->sole();

    $this->post(route('attendance.store'), [
        'title_id' => $rotary->id,
        'name' => 'Jean Dupont',
        'club' => 'RC Cotonou Ife',
        'phone' => '+229 90 00 00 00',
        'email' => 'jean.dupont@example.com',
    ])->assertSessionHasErrors(['position_id']);

    expect(Attendance::count())->toBe(0);
});

it('allows no position when the submitted title has none', function () {
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

    expect(Attendance::first())
        ->title_id->toBe($invite->id)
        ->position_id->toBeNull();
});

it('rejects a position that is not linked to the submitted title', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);
    // JCI is only linked to the 5 generic officer positions (Président,
    // Vice-Président, Secrétaire, Trésorier, Membre) — PDG is Rotary-only.
    $jci = Title::where('name', 'JCI')->sole();
    $rotaryOnlyPosition = Title::where('name', 'Rotary')->sole()->positions()->where('name', 'PDG')->sole();

    $this->post(route('attendance.store'), [
        'title_id' => $jci->id,
        'position_id' => $rotaryOnlyPosition->id,
        'name' => 'Jean Dupont',
        'club' => 'RC Cotonou Ife',
        'phone' => '+229 90 00 00 00',
        'email' => 'jean.dupont@example.com',
    ])->assertSessionHasErrors(['position_id']);

    expect(Attendance::count())->toBe(0);
});

it('accepts a position that is linked to the submitted title', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);
    $rotary = Title::where('name', 'Rotary')->sole();
    $president = $rotary->positions()->where('name', 'Président')->sole();

    $this->post(route('attendance.store'), [
        'title_id' => $rotary->id,
        'position_id' => $president->id,
        'name' => 'Jean Dupont',
        'club' => 'RC Cotonou Ife',
        'phone' => '+229 90 00 00 00',
        'email' => 'jean.dupont@example.com',
    ])->assertRedirect(route('attendance.show'))
        ->assertSessionDoesntHaveErrors();

    expect(Attendance::first())->position_id->toBe($president->id);
});
```

- [ ] **Step 7: Run the affected suites**

Run: `php artisan test --filter=AttendanceFormTest`
Run: `php artisan test --filter=AttendanceMemberCheckInTest`
Run: `php artisan test --filter=MemberManagementTest`
Expected: all PASS.

- [ ] **Step 8: Run the full suite**

Run: `php artisan test --compact`
Expected: only `Admin/AttendanceDashboardTest`, `Admin/AttendancePdfExportTest`
still fail (handled in Task 6) — per the Task 4 addendum,
`Unit/Enums/AttendanceTitleTest` was never affected by the schema/model
changes and should already be passing.

- [ ] **Step 9: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Requests/StoreAttendanceRequest.php app/Http/Requests/UpdateMemberRequest.php \
  app/Http/Controllers/AttendanceFormController.php app/Http/Controllers/Admin/MemberController.php \
  resources/views/components/attendance-form.blade.php resources/views/attendance/show.blade.php \
  resources/views/admin/members/edit.blade.php resources/views/admin/members/show.blade.php \
  tests/Feature/AttendanceFormTest.php tests/Feature/AttendanceMemberCheckInTest.php \
  tests/Feature/Admin/MemberManagementTest.php
git commit -m "Cut over check-in and member-edit forms to titre/poste selects"
```

---

### Task 6: Cut over the session dashboard and PDF export

**Files:**
- Modify: `app/Http/Controllers/Admin/MeetingSessionController.php`
- Modify: `resources/views/admin/sessions/show.blade.php`
- Modify: `resources/views/admin/sessions/pdf.blade.php`
- Modify: `tests/Feature/Admin/AttendanceDashboardTest.php`
- Modify: `tests/Feature/Admin/AttendancePdfExportTest.php`

**Interfaces:**
- Consumes: `Attendance::title` relation (Task 4), `Title::with('positions')` not needed here (dashboard only reads `title->name`/`category`).

- [ ] **Step 1: Update the failing tests**

In `tests/Feature/Admin/AttendanceDashboardTest.php`, remove `use
App\Enums\AttendanceTitle;` and replace both `Attendance::factory()
->for($meetingSession)->create(['title' => AttendanceTitle::Rotarien, ...])`
/ `AttendanceTitle::Invite` calls with:

```php
use App\Models\Title;
// ...
Attendance::factory()->for($meetingSession)->create([
    'title_id' => Title::where('name', 'Rotary')->sole()->id,
    'name' => 'Jean Dupont',
    'present' => true,
]);
Attendance::factory()->for($meetingSession)->create([
    'title_id' => Title::where('name', 'Invité')->sole()->id,
    'name' => 'Awa Bello',
    'present' => false,
]);
```

Update the "exposes a title/qualité filter" test's assertions from
`->assertSee('Rotarien')->assertSee('Invité')` to
`->assertSee('Rotary')->assertSee('Invité')` (the titre names now shown are
`Rotary`/`Invité`, not the old `Rotarien` value).

In `tests/Feature/Admin/AttendancePdfExportTest.php`, remove `use
App\Enums\AttendanceTitle;` and replace
`Attendance::factory()->for($meetingSession)->create(['title' =>
AttendanceTitle::Rotarien])` with:

```php
use App\Models\Title;
// ...
Attendance::factory()->for($meetingSession)->create([
    'title_id' => Title::where('name', 'Rotary')->sole()->id,
]);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AttendanceDashboardTest`
Expected: FAIL — `assertSee('Rotary')` fails because the view still emits
`$attendance->title->value` (undefined property on the `Title` model).

- [ ] **Step 3: Update the controller to eager-load the title relation**

```php
// app/Http/Controllers/Admin/MeetingSessionController.php
public function show(MeetingSession $meetingSession): View
{
    return view('admin.sessions.show', [
        'meetingSession' => $meetingSession,
        'attendances' => $meetingSession->attendances()->with('title')->get(),
        'upcomingSessions' => MeetingSession::where('id', '!=', $meetingSession->id)
            ->where('date', '>=', now()->toDateString())
            ->orderBy('date')
            ->get(),
    ]);
}

public function exportPdf(MeetingSession $meetingSession): Response
{
    $pdf = Pdf::loadView('admin.sessions.pdf', [
        'meetingSession' => $meetingSession,
        'attendances' => $meetingSession->attendances()->with('title')->get(),
    ]);

    return $pdf->download("liste-presence-{$meetingSession->id}.pdf");
}
```

(Eager-loading `title` avoids an N+1 query per attendance row now that
`title` is a real relation instead of an enum cast.)

- [ ] **Step 4: Update the two Blade views**

In `resources/views/admin/sessions/show.blade.php` line 6, change
`'title' => $attendance->title->value,` to `'title' => $attendance->title->name,`.

In `resources/views/admin/sessions/pdf.blade.php` line 36, change
`<td>{{ $attendance->title->value }}</td>` to
`<td>{{ $attendance->title->name }}</td>`.

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=AttendanceDashboardTest`
Run: `php artisan test --filter=AttendancePdfExportTest`
Expected: both PASS.

- [ ] **Step 6: Run the full suite**

Run: `php artisan test --compact`
Expected: only `Unit/Enums/AttendanceTitleTest` still fails (handled in Task 7).

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Admin/MeetingSessionController.php \
  resources/views/admin/sessions/show.blade.php resources/views/admin/sessions/pdf.blade.php \
  tests/Feature/Admin/AttendanceDashboardTest.php tests/Feature/Admin/AttendancePdfExportTest.php
git commit -m "Cut over session dashboard and PDF export to the title relation"
```

---

### Task 7: Finalize the schema and remove the old enum

**Files:**
- Create: `database/migrations/2026_07_15_120008_finalize_title_and_position_columns.php`
- Delete: `app/Enums/AttendanceTitle.php`
- Delete: `tests/Unit/Enums/AttendanceTitleTest.php`

**Interfaces:**
- Consumes: every row already has `title_id` populated (Task 3's backfill), no application code reads `members.title`/`attendances.title` anymore — that column was already dropped in the Task 4 addendum, not deferred to here.

- [ ] **Step 1: Confirm no remaining references**

Run: `grep -rn "AttendanceTitle" --include="*.php" --include="*.blade.php" .`
Expected: no output (the enum file and its test still exist at this point,
so `grep -v` those two paths, or just eyeball that the only hits are the
enum file itself and its test file, which this task deletes).

- [ ] **Step 2: Write the finalize migration**

The old `title` column was already dropped by the Task 4 addendum
migration (`2026_07_15_120007_drop_legacy_title_column.php`) — this
migration's only remaining job is tightening `title_id` to `NOT NULL` on
both tables, now that every row has one.

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
            $table->foreignId('title_id')->nullable(false)->change();
        });
        Schema::table('attendances', function (Blueprint $table) {
            $table->foreignId('title_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->foreignId('title_id')->nullable()->change();
        });
        Schema::table('attendances', function (Blueprint $table) {
            $table->foreignId('title_id')->nullable()->change();
        });
    }
};
```

Save as `database/migrations/2026_07_15_120008_finalize_title_and_position_columns.php`.
Note: `->change()` on a column requires `doctrine/dbal` in Laravel <11 —
this app is Laravel 13, which no longer needs that package for `change()`;
no dependency change is required.

- [ ] **Step 3: Delete the old enum and its test**

```bash
rm app/Enums/AttendanceTitle.php tests/Unit/Enums/AttendanceTitleTest.php
```

- [ ] **Step 4: Run the full suite**

Run: `php artisan test --compact`
Expected: PASS, every test in the suite.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "Finalize title_id as required and drop the old title column"
```

---

### Task 8: Admin CRUD for Title and Position

**Files:**
- Create: `app/Http/Controllers/Admin/TitleController.php`
- Create: `app/Http/Controllers/Admin/PositionController.php`
- Create: `app/Http/Requests/StoreTitleRequest.php`
- Create: `app/Http/Requests/UpdateTitleRequest.php`
- Create: `app/Http/Requests/StorePositionRequest.php`
- Create: `app/Http/Requests/UpdatePositionRequest.php`
- Create: `resources/views/admin/titles/index.blade.php`
- Create: `resources/views/admin/titles/create.blade.php`
- Create: `resources/views/admin/titles/edit.blade.php`
- Create: `resources/views/admin/positions/index.blade.php`
- Create: `resources/views/admin/positions/create.blade.php`
- Create: `resources/views/admin/positions/edit.blade.php`
- Modify: `resources/views/components/layouts/admin.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Admin/TitleManagementTest.php`
- Test: `tests/Feature/Admin/PositionManagementTest.php`

**Interfaces:**
- Consumes: `Title`, `Position` models (Task 1).
- Produces: `admin.titles.{index,create,store,edit,update}` and
  `admin.positions.{index,create,store,edit,update}` named routes.

- [ ] **Step 1: Write the failing tests**

```php
// tests/Feature/Admin/PositionManagementTest.php
<?php

use App\Models\Position;
use App\Models\User;

it('redirects guests to login for every position route', function () {
    $position = Position::factory()->create();

    $this->get(route('admin.positions.index'))->assertRedirect(route('admin.login'));
    $this->get(route('admin.positions.create'))->assertRedirect(route('admin.login'));
    $this->post(route('admin.positions.store'), [])->assertRedirect(route('admin.login'));
    $this->get(route('admin.positions.edit', $position))->assertRedirect(route('admin.login'));
    $this->put(route('admin.positions.update', $position), [])->assertRedirect(route('admin.login'));
});

it('lists positions to an authenticated admin', function () {
    Position::factory()->create(['name' => 'Trésorier']);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.positions.index'))
        ->assertOk()
        ->assertSee('Trésorier');
});

it('creates a position', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('admin.positions.store'), ['name' => 'Porte-drapeau'])
        ->assertRedirect(route('admin.positions.index'));

    expect(Position::where('name', 'Porte-drapeau')->exists())->toBeTrue();
});

it('rejects a duplicate position name', function () {
    Position::factory()->create(['name' => 'Trésorier']);

    $this->actingAs(User::factory()->create())
        ->post(route('admin.positions.store'), ['name' => 'Trésorier'])
        ->assertSessionHasErrors(['name']);
});

it('updates a position', function () {
    $position = Position::factory()->create(['name' => 'Ancien nom']);

    $this->actingAs(User::factory()->create())
        ->put(route('admin.positions.update', $position), ['name' => 'Nouveau nom'])
        ->assertRedirect(route('admin.positions.index'));

    expect($position->fresh()->name)->toBe('Nouveau nom');
});
```

```php
// tests/Feature/Admin/TitleManagementTest.php
<?php

use App\Enums\AttendanceCategory;
use App\Models\Position;
use App\Models\Title;
use App\Models\User;

it('redirects guests to login for every title route', function () {
    $title = Title::factory()->create();

    $this->get(route('admin.titles.index'))->assertRedirect(route('admin.login'));
    $this->get(route('admin.titles.create'))->assertRedirect(route('admin.login'));
    $this->post(route('admin.titles.store'), [])->assertRedirect(route('admin.login'));
    $this->get(route('admin.titles.edit', $title))->assertRedirect(route('admin.login'));
    $this->put(route('admin.titles.update', $title), [])->assertRedirect(route('admin.login'));
});

it('lists titles with their category to an authenticated admin', function () {
    Title::factory()->create(['name' => 'Lions', 'category' => AttendanceCategory::Guests]);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.titles.index'))
        ->assertOk()
        ->assertSee('Lions');
});

it('creates a title and links the selected positions', function () {
    $president = Position::factory()->create(['name' => 'Président']);
    $secretary = Position::factory()->create(['name' => 'Secrétaire']);

    $this->actingAs(User::factory()->create())
        ->post(route('admin.titles.store'), [
            'name' => 'Kiwanis',
            'category' => AttendanceCategory::Guests->value,
            'position_ids' => [$president->id, $secretary->id],
        ])->assertRedirect(route('admin.titles.index'));

    $title = Title::where('name', 'Kiwanis')->sole();
    expect($title->category)->toBe(AttendanceCategory::Guests)
        ->and($title->positions()->pluck('id')->sort()->values()->all())
        ->toBe([$president->id, $secretary->id]);
});

it('rejects a duplicate title name', function () {
    Title::factory()->create(['name' => 'Lions']);

    $this->actingAs(User::factory()->create())
        ->post(route('admin.titles.store'), ['name' => 'Lions', 'category' => AttendanceCategory::Guests->value])
        ->assertSessionHasErrors(['name']);
});

it('updates a title and replaces its linked positions', function () {
    $title = Title::factory()->create(['category' => AttendanceCategory::Guests]);
    $oldPosition = Position::factory()->create();
    $newPosition = Position::factory()->create();
    $title->positions()->attach($oldPosition);

    $this->actingAs(User::factory()->create())
        ->put(route('admin.titles.update', $title), [
            'name' => $title->name,
            'category' => AttendanceCategory::Members->value,
            'position_ids' => [$newPosition->id],
        ])->assertRedirect(route('admin.titles.index'));

    expect($title->fresh()->category)->toBe(AttendanceCategory::Members)
        ->and($title->positions()->pluck('id')->all())->toBe([$newPosition->id]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TitleManagementTest`
Run: `php artisan test --filter=PositionManagementTest`
Expected: FAIL — routes `admin.titles.index` / `admin.positions.index` don't exist.

- [ ] **Step 3: Write the FormRequests**

```php
// app/Http/Requests/StoreTitleRequest.php
<?php

namespace App\Http\Requests;

use App\Enums\AttendanceCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTitleRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255', 'unique:titles,name'],
            'category' => ['required', Rule::enum(AttendanceCategory::class)],
            'position_ids' => ['array'],
            'position_ids.*' => ['integer', 'exists:positions,id'],
        ];
    }
}
```

```php
// app/Http/Requests/UpdateTitleRequest.php
<?php

namespace App\Http\Requests;

use App\Enums\AttendanceCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTitleRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255', Rule::unique('titles', 'name')->ignore($this->route('title'))],
            'category' => ['required', Rule::enum(AttendanceCategory::class)],
            'position_ids' => ['array'],
            'position_ids.*' => ['integer', 'exists:positions,id'],
        ];
    }
}
```

```php
// app/Http/Requests/StorePositionRequest.php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePositionRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255', 'unique:positions,name'],
        ];
    }
}
```

```php
// app/Http/Requests/UpdatePositionRequest.php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePositionRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255', Rule::unique('positions', 'name')->ignore($this->route('position'))],
        ];
    }
}
```

- [ ] **Step 4: Write the controllers**

```php
// app/Http/Controllers/Admin/TitleController.php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTitleRequest;
use App\Http\Requests\UpdateTitleRequest;
use App\Models\Position;
use App\Models\Title;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class TitleController extends Controller
{
    public function index(): View
    {
        return view('admin.titles.index', [
            'titles' => Title::withCount('positions')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.titles.create', [
            'positions' => Position::orderBy('name')->get(),
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
        return view('admin.titles.edit', [
            'title' => $title,
            'positions' => Position::orderBy('name')->get(),
            'linkedPositionIds' => $title->positions()->pluck('positions.id')->all(),
        ]);
    }

    public function update(UpdateTitleRequest $request, Title $title): RedirectResponse
    {
        $title->update($request->safe()->only(['name', 'category']));
        $title->positions()->sync($request->input('position_ids', []));

        return redirect()->route('admin.titles.index');
    }
}
```

```php
// app/Http/Controllers/Admin/PositionController.php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePositionRequest;
use App\Http\Requests\UpdatePositionRequest;
use App\Models\Position;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PositionController extends Controller
{
    public function index(): View
    {
        return view('admin.positions.index', [
            'positions' => Position::orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.positions.create');
    }

    public function store(StorePositionRequest $request): RedirectResponse
    {
        Position::create($request->validated());

        return redirect()->route('admin.positions.index');
    }

    public function edit(Position $position): View
    {
        return view('admin.positions.edit', ['position' => $position]);
    }

    public function update(UpdatePositionRequest $request, Position $position): RedirectResponse
    {
        $position->update($request->validated());

        return redirect()->route('admin.positions.index');
    }
}
```

- [ ] **Step 5: Add the routes**

In `routes/web.php`, add `use App\Http\Controllers\Admin\PositionController;`
and `use App\Http\Controllers\Admin\TitleController;` to the imports, then
inside the existing `Route::middleware('auth')->group(...)` block (right
after the `members.*` routes), add:

```php
Route::get('titles', [TitleController::class, 'index'])->name('titles.index');
Route::get('titles/create', [TitleController::class, 'create'])->name('titles.create');
Route::post('titles', [TitleController::class, 'store'])->name('titles.store');
Route::get('titles/{title}/edit', [TitleController::class, 'edit'])->name('titles.edit');
Route::put('titles/{title}', [TitleController::class, 'update'])->name('titles.update');
Route::get('positions', [PositionController::class, 'index'])->name('positions.index');
Route::get('positions/create', [PositionController::class, 'create'])->name('positions.create');
Route::post('positions', [PositionController::class, 'store'])->name('positions.store');
Route::get('positions/{position}/edit', [PositionController::class, 'edit'])->name('positions.edit');
Route::put('positions/{position}', [PositionController::class, 'update'])->name('positions.update');
```

- [ ] **Step 6: Write the Blade views**

```blade
{{-- resources/views/admin/positions/index.blade.php --}}
<x-layouts.admin title="Postes — Administration">
    <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)] md:p-8">
        <div class="flex items-center justify-between gap-3">
            <h1 class="font-display text-xl font-extrabold text-navy">Postes</h1>
            <a href="{{ route('admin.positions.create') }}"
                class="cursor-pointer rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                Ajouter un poste
            </a>
        </div>

        <div class="mt-6 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-divider text-muted-strong">
                        <th class="py-2 pr-4 font-semibold">Nom</th>
                        <th class="py-2 pr-4 font-semibold"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-divider">
                    @foreach ($positions as $position)
                        <tr>
                            <td class="py-3 pr-4 font-semibold text-navy">{{ $position->name }}</td>
                            <td class="py-3 pr-4 text-right">
                                <a href="{{ route('admin.positions.edit', $position) }}" class="text-sm font-semibold text-navy underline">
                                    Modifier
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

```blade
{{-- resources/views/admin/positions/create.blade.php --}}
<x-layouts.admin title="Ajouter un poste — Administration">
    <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)] md:p-8">
        <h1 class="font-display text-xl font-extrabold text-navy">Ajouter un poste</h1>

        <form method="POST" action="{{ route('admin.positions.store') }}" class="mt-4 flex max-w-md flex-col gap-3">
            @csrf
            <div class="flex flex-col gap-1.5">
                <label for="name" class="text-sm font-semibold">Nom</label>
                <input type="text" id="name" name="name" value="{{ old('name') }}" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>
            <button type="submit"
                class="mt-2 cursor-pointer self-start rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                Créer le poste
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

```blade
{{-- resources/views/admin/positions/edit.blade.php --}}
<x-layouts.admin :title="'Modifier ' . $position->name . ' — Administration'">
    <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)] md:p-8">
        <h1 class="font-display text-xl font-extrabold text-navy">Modifier {{ $position->name }}</h1>

        <form method="POST" action="{{ route('admin.positions.update', $position) }}" class="mt-4 flex max-w-md flex-col gap-3">
            @csrf
            @method('PUT')
            <div class="flex flex-col gap-1.5">
                <label for="name" class="text-sm font-semibold">Nom</label>
                <input type="text" id="name" name="name" value="{{ old('name', $position->name) }}" required
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

```blade
{{-- resources/views/admin/titles/index.blade.php --}}
<x-layouts.admin title="Titres — Administration">
    <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)] md:p-8">
        <div class="flex items-center justify-between gap-3">
            <h1 class="font-display text-xl font-extrabold text-navy">Titres</h1>
            <a href="{{ route('admin.titles.create') }}"
                class="cursor-pointer rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                Ajouter un titre
            </a>
        </div>

        <div class="mt-6 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-divider text-muted-strong">
                        <th class="py-2 pr-4 font-semibold">Nom</th>
                        <th class="py-2 pr-4 font-semibold">Catégorie</th>
                        <th class="py-2 pr-4 font-semibold">Postes liés</th>
                        <th class="py-2 pr-4 font-semibold"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-divider">
                    @foreach ($titles as $title)
                        <tr>
                            <td class="py-3 pr-4 font-semibold text-navy">{{ $title->name }}</td>
                            <td class="py-3 pr-4">{{ $title->category->label() }}</td>
                            <td class="py-3 pr-4">{{ $title->positions_count }}</td>
                            <td class="py-3 pr-4 text-right">
                                <a href="{{ route('admin.titles.edit', $title) }}" class="text-sm font-semibold text-navy underline">
                                    Modifier
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

```blade
{{-- resources/views/admin/titles/create.blade.php --}}
<x-layouts.admin title="Ajouter un titre — Administration">
    <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)] md:p-8">
        <h1 class="font-display text-xl font-extrabold text-navy">Ajouter un titre</h1>

        <form method="POST" action="{{ route('admin.titles.store') }}" class="mt-4 flex max-w-md flex-col gap-3">
            @csrf
            <div class="flex flex-col gap-1.5">
                <label for="name" class="text-sm font-semibold">Nom</label>
                <input type="text" id="name" name="name" value="{{ old('name') }}" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="category" class="text-sm font-semibold">Catégorie</label>
                <select id="category" name="category" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
                    <option value="">Sélectionnez…</option>
                    @foreach (\App\Enums\AttendanceCategory::cases() as $categoryOption)
                        <option value="{{ $categoryOption->value }}" @selected(old('category') === $categoryOption->value)>
                            {{ $categoryOption->label() }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="flex flex-col gap-1.5">
                <span class="text-sm font-semibold">Postes liés</span>
                <div class="flex flex-col gap-1.5 rounded-lg border border-border p-3">
                    @foreach ($positions as $position)
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="position_ids[]" value="{{ $position->id }}"
                                @checked(collect(old('position_ids', []))->contains($position->id))>
                            {{ $position->name }}
                        </label>
                    @endforeach
                </div>
            </div>
            <button type="submit"
                class="mt-2 cursor-pointer self-start rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                Créer le titre
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

```blade
{{-- resources/views/admin/titles/edit.blade.php --}}
<x-layouts.admin :title="'Modifier ' . $title->name . ' — Administration'">
    <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)] md:p-8">
        <h1 class="font-display text-xl font-extrabold text-navy">Modifier {{ $title->name }}</h1>

        <form method="POST" action="{{ route('admin.titles.update', $title) }}" class="mt-4 flex max-w-md flex-col gap-3">
            @csrf
            @method('PUT')
            <div class="flex flex-col gap-1.5">
                <label for="name" class="text-sm font-semibold">Nom</label>
                <input type="text" id="name" name="name" value="{{ old('name', $title->name) }}" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="category" class="text-sm font-semibold">Catégorie</label>
                <select id="category" name="category" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
                    @foreach (\App\Enums\AttendanceCategory::cases() as $categoryOption)
                        <option value="{{ $categoryOption->value }}" @selected(old('category', $title->category->value) === $categoryOption->value)>
                            {{ $categoryOption->label() }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="flex flex-col gap-1.5">
                <span class="text-sm font-semibold">Postes liés</span>
                <div class="flex flex-col gap-1.5 rounded-lg border border-border p-3">
                    @foreach ($positions as $position)
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="position_ids[]" value="{{ $position->id }}"
                                @checked(collect(old('position_ids', $linkedPositionIds))->contains($position->id))>
                            {{ $position->name }}
                        </label>
                    @endforeach
                </div>
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

- [ ] **Step 7: Add sidebar links**

In `resources/views/components/layouts/admin.blade.php`, add two entries to
the `<nav>` block, right after the existing "Membres" link:

```blade
<a href="{{ route('admin.titles.index') }}" @click="close()"
    class="cursor-pointer rounded-lg px-3 py-2 text-sm font-semibold {{ request()->routeIs('admin.titles.*') ? 'bg-navy text-white' : 'text-navy hover:bg-cream' }}">
    Titres
</a>
<a href="{{ route('admin.positions.index') }}" @click="close()"
    class="cursor-pointer rounded-lg px-3 py-2 text-sm font-semibold {{ request()->routeIs('admin.positions.*') ? 'bg-navy text-white' : 'text-navy hover:bg-cream' }}">
    Postes
</a>
```

- [ ] **Step 8: Run test to verify it passes**

Run: `php artisan test --filter=TitleManagementTest`
Run: `php artisan test --filter=PositionManagementTest`
Expected: both PASS.

- [ ] **Step 9: Run the full suite**

Run: `php artisan test --compact`
Expected: PASS, every test in the suite.

- [ ] **Step 10: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "Add admin CRUD for titles and positions"
```
