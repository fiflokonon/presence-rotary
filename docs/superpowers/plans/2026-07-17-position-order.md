# Position Order Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an admin define a hierarchical display order for postes (Titre/Qualité — `App\Models\Position`) and use it to sort the admin attendance roster, both within its existing groups and, optionally, as a flat organisation-agnostic list.

**Architecture:** Mirror the existing `Title.order` feature (nullable `unsigned integer` column, append-on-create, up/down swap via a `moveOrder` controller action) onto `Position`. The roster's Alpine component (`attendanceDashboard` in `resources/js/app.js`) gains a client-side sort by `positionOrder` applied within its existing category groups, plus an opt-in flat "sort by position only" mode.

**Tech Stack:** Laravel 13 / PHP 8.4, Pest 4, Alpine.js, Blade, SQLite.

## Global Constraints

- PHP 8.4, `laravel/framework` v13 — use constructor property promotion and explicit return types on any new/changed method.
- Always use curly braces for control structures, even single-line bodies.
- Run `vendor/bin/pint --dirty --format agent` after any PHP change, before the task's final commit.
- Tests use Pest (`php artisan make:test --pest {name}` for new files); run via `php artisan test --compact --filter=testName`. Do not delete existing tests without approval.
- No JS test runner exists in this project (`package.json` has no test script) — new `app.js` logic is verified indirectly through the server-rendered JSON payload in Pest feature tests, plus a final manual browser check (per CLAUDE.md's frontend-change verification rule).
- All user-facing copy is French, matching the existing admin UI's tone (e.g. "Ordre", "Déplacer vers le haut/bas").
- Full design reference: `docs/superpowers/specs/2026-07-17-position-order-design.md`.

---

### Task 1: `order` column on `positions` + alphabetical backfill

**Files:**
- Create: `database/migrations/2026_07_17_090000_add_order_to_positions_table.php`
- Create: `database/migrations/2026_07_17_090001_initialize_position_order_values.php`
- Modify: `app/Models/Position.php`
- Test: `tests/Feature/Models/PositionTest.php`
- Test: `tests/Feature/Migrations/SeedTitlesAndPositionsTest.php`

**Interfaces:**
- Produces: `positions.order` (nullable `unsigned integer` column), `Position::$fillable` includes `'order'`. Later tasks read/write `$position->order` directly (plain Eloquent attribute, no accessor).

- [ ] **Step 1: Write the failing model test**

Add to `tests/Feature/Models/PositionTest.php`:

```php
it('defaults order to null for a factory-created position', function () {
    $position = Position::factory()->create();

    expect($position->order)->toBeNull();
});
```

- [ ] **Step 2: Write the failing backfill test**

Add to `tests/Feature/Migrations/SeedTitlesAndPositionsTest.php`:

```php
it('assigns the seeded positions a hierarchical order matching alphabetical sort', function () {
    $expected = Position::query()->orderBy('name')->pluck('name')->all();

    expect(Position::query()->orderBy('order')->pluck('name')->all())->toBe($expected);
});
```

- [ ] **Step 3: Run both tests to verify they fail**

Run: `php artisan test --compact --filter="defaults order to null|assigns the seeded positions a hierarchical order"`
Expected: FAIL — `order` is not a recognized column/attribute (SQL error or `Unknown column`).

- [ ] **Step 4: Create the schema migration**

`database/migrations/2026_07_17_090000_add_order_to_positions_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->unsignedInteger('order')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn('order');
        });
    }
};
```

- [ ] **Step 5: Create the backfill migration**

`database/migrations/2026_07_17_090001_initialize_position_order_values.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $positions = DB::table('positions')
            ->whereNull('order')
            ->orderBy('name')
            ->get();

        foreach ($positions as $index => $position) {
            DB::table('positions')->where('id', $position->id)->update(['order' => $index]);
        }
    }

    public function down(): void
    {
        DB::table('positions')->update(['order' => null]);
    }
};
```

- [ ] **Step 6: Add `order` to `Position::$fillable`**

In `app/Models/Position.php`, change:

```php
protected $fillable = ['name', 'is_active'];
```

to:

```php
protected $fillable = ['name', 'is_active', 'order'];
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --compact --filter="defaults order to null|assigns the seeded positions a hierarchical order"`
Expected: PASS

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_07_17_090000_add_order_to_positions_table.php database/migrations/2026_07_17_090001_initialize_position_order_values.php app/Models/Position.php tests/Feature/Models/PositionTest.php tests/Feature/Migrations/SeedTitlesAndPositionsTest.php
git commit -m "feat: add hierarchical order column to positions"
```

---

### Task 2: `PositionController` — default order on create + `moveOrder`

**Files:**
- Modify: `app/Http/Controllers/Admin/PositionController.php`
- Modify: `routes/web.php:53` (insert new route between `positions.toggle-active` and `positions.destroy`)
- Test: `tests/Feature/Admin/PositionManagementTest.php`

**Interfaces:**
- Consumes: `positions.order` column from Task 1.
- Produces: `PositionController::moveOrder(Position $position, string $direction): RedirectResponse`, route `admin.positions.move-order` (PATCH `positions/{position}/move-order/{direction}`). `PositionController::index()` now orders by `order` then `name`; `store()` appends new positions at `max(order) + 1`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/Admin/PositionManagementTest.php`:

```php
it('creates a position with the next order value appended at the end', function () {
    Position::factory()->create(['order' => 5]);

    $this->actingAs(User::factory()->create())
        ->post(route('admin.positions.store'), ['name' => 'Porte-étendard']);

    expect(Position::where('name', 'Porte-étendard')->sole()->order)->toBe(6);
});

it('lists positions ordered by their order value rather than name', function () {
    Position::factory()->create(['name' => 'Zed', 'order' => 0]);
    Position::factory()->create(['name' => 'Alpha', 'order' => 1]);

    $response = $this->actingAs(User::factory()->create())
        ->get(route('admin.positions.index'));

    $response->assertOk();
    $content = $response->getContent();

    expect(strpos($content, 'Zed'))->toBeLessThan(strpos($content, 'Alpha'));
});

it('moves a position up, swapping order with the previous one', function () {
    $first = Position::factory()->create(['order' => 0]);
    $second = Position::factory()->create(['order' => 1]);

    $this->actingAs(User::factory()->create())
        ->patch(route('admin.positions.move-order', [$second, 'up']))
        ->assertRedirect(route('admin.positions.index'));

    expect($second->fresh()->order)->toBe(0)
        ->and($first->fresh()->order)->toBe(1);
});

it('moves a position down, swapping order with the next one', function () {
    $first = Position::factory()->create(['order' => 0]);
    $second = Position::factory()->create(['order' => 1]);

    $this->actingAs(User::factory()->create())
        ->patch(route('admin.positions.move-order', [$first, 'down']))
        ->assertRedirect(route('admin.positions.index'));

    expect($first->fresh()->order)->toBe(1)
        ->and($second->fresh()->order)->toBe(0);
});

it('does nothing when moving the first position up', function () {
    $first = Position::factory()->create(['order' => 0]);
    Position::factory()->create(['order' => 1]);

    $this->actingAs(User::factory()->create())
        ->patch(route('admin.positions.move-order', [$first, 'up']))
        ->assertRedirect(route('admin.positions.index'));

    expect($first->fresh()->order)->toBe(0);
});

it('assigns an order to a position with a null order before moving it', function () {
    $position = Position::factory()->create(['order' => null]);
    Position::factory()->create(['order' => 0]);

    $this->actingAs(User::factory()->create())
        ->patch(route('admin.positions.move-order', [$position, 'up']))
        ->assertRedirect(route('admin.positions.index'));

    expect($position->fresh()->order)->not->toBeNull();
});

it('rejects an invalid move direction', function () {
    $position = Position::factory()->create(['order' => 0]);

    $this->actingAs(User::factory()->create())
        ->patch(route('admin.positions.move-order', [$position, 'sideways']))
        ->assertNotFound();
});

it('requires authentication to move a positions order', function () {
    $position = Position::factory()->create();

    $this->patch(route('admin.positions.move-order', [$position, 'up']))
        ->assertRedirect(route('admin.login'));
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter="order"`
Expected: FAIL — route `admin.positions.move-order` doesn't exist (`RouteNotFoundException`), and `store` doesn't set `order`.

- [ ] **Step 3: Add the route**

In `routes/web.php`, between the `positions.toggle-active` line (53) and the `positions.destroy` line (54), insert:

```php
        Route::patch('positions/{position}/move-order/{direction}', [PositionController::class, 'moveOrder'])->name('positions.move-order');
```

- [ ] **Step 4: Update `PositionController`**

Replace the full contents of `app/Http/Controllers/Admin/PositionController.php` with:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePositionRequest;
use App\Http\Requests\UpdatePositionRequest;
use App\Models\Position;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PositionController extends Controller
{
    public function index(): View
    {
        return view('admin.positions.index', [
            'positions' => Position::orderBy('order')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.positions.create');
    }

    public function store(StorePositionRequest $request): RedirectResponse
    {
        $maxOrder = Position::max('order');
        $nextOrder = $maxOrder === null ? 0 : $maxOrder + 1;

        Position::create([...$request->validated(), 'order' => $nextOrder]);

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

    public function toggleActive(Position $position): RedirectResponse
    {
        $position->update(['is_active' => ! $position->is_active]);

        return redirect()->route('admin.positions.index');
    }

    public function moveOrder(Position $position, string $direction): RedirectResponse
    {
        if ($position->order === null) {
            $maxOrder = Position::max('order');
            $position->update(['order' => ($maxOrder === null ? 0 : $maxOrder + 1)]);
            $position->refresh();
        }

        $direction = strtolower($direction);
        abort_if(! in_array($direction, ['up', 'down']), 404);

        if ($direction === 'up') {
            $swapWith = Position::where('order', '<', $position->order)->orderByDesc('order')->first();
        } else {
            $swapWith = Position::where('order', '>', $position->order)->orderBy('order')->first();
        }

        if ($swapWith !== null) {
            $tempOrder = $position->order;
            $position->update(['order' => $swapWith->order]);
            $swapWith->update(['order' => $tempOrder]);
        }

        return redirect()->route('admin.positions.index');
    }

    public function destroy(Position $position): RedirectResponse
    {
        try {
            $position->delete();
        } catch (QueryException) {
            return redirect()->route('admin.positions.index')
                ->with('error', 'Ce titre/qualité est utilisé par des membres ou des présences existantes — désactivez-le plutôt que de le supprimer.');
        }

        return redirect()->route('admin.positions.index');
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact --filter="order"`
Expected: PASS

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Admin/PositionController.php routes/web.php tests/Feature/Admin/PositionManagementTest.php
git commit -m "feat: order positions and let admins reorder them"
```

---

### Task 3: `admin/positions/index.blade.php` — order column + up/down arrows

**Files:**
- Modify: `resources/views/admin/positions/index.blade.php`
- Test: `tests/Feature/Admin/PositionManagementTest.php`

**Interfaces:**
- Consumes: `admin.positions.move-order` route from Task 2, `$positions` (ordered collection) from `PositionController::index()`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Admin/PositionManagementTest.php`:

```php
it('shows up and down order controls on the positions index', function () {
    $position = Position::factory()->create(['order' => 0]);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.positions.index'))
        ->assertOk()
        ->assertSee('action="'.route('admin.positions.move-order', [$position, 'up']).'"', false)
        ->assertSee('action="'.route('admin.positions.move-order', [$position, 'down']).'"', false);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter="shows up and down order controls"`
Expected: FAIL — no such markup on the page.

- [ ] **Step 3: Update the view**

Replace the `<thead>`/`<tbody>` block in `resources/views/admin/positions/index.blade.php` (lines 18-60) with:

```blade
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-divider text-muted-strong">
                        <th class="py-2 pr-4 font-semibold">Nom</th>
                        <th class="py-2 pr-4 font-semibold">Ordre</th>
                        <th class="py-2 pr-4 font-semibold">Statut</th>
                        <th class="py-2 pr-4 font-semibold"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-divider">
                    @foreach ($positions as $position)
                        <tr>
                            <td class="py-3 pr-4 font-semibold text-navy">{{ $position->name }}</td>
                            <td class="py-3 pr-4">
                                <div class="flex items-center gap-2">
                                    <form method="POST" action="{{ route('admin.positions.move-order', [$position, 'up']) }}" class="inline">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="cursor-pointer text-lg leading-none text-muted hover:text-navy" title="Déplacer vers le haut">
                                            ↑
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.positions.move-order', [$position, 'down']) }}" class="inline">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="cursor-pointer text-lg leading-none text-muted hover:text-navy" title="Déplacer vers le bas">
                                            ↓
                                        </button>
                                    </form>
                                </div>
                            </td>
                            <td class="py-3 pr-4">
                                <span class="rounded-full px-2 py-1 text-xs font-semibold {{ $position->is_active ? 'bg-success-bg text-success' : 'bg-divider text-muted' }}">
                                    {{ $position->is_active ? 'Actif' : 'Inactif' }}
                                </span>
                            </td>
                            <td class="py-3 pr-4 text-right">
                                <div class="flex items-center justify-end gap-3">
                                    <a href="{{ route('admin.positions.edit', $position) }}" class="text-sm font-semibold text-navy underline">
                                        Modifier
                                    </a>
                                    <form method="POST" action="{{ route('admin.positions.toggle-active', $position) }}">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="cursor-pointer text-sm font-semibold text-navy underline">
                                            {{ $position->is_active ? 'Désactiver' : 'Activer' }}
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.positions.destroy', $position) }}"
                                        onsubmit="return confirm('Supprimer définitivement ce titre/qualité ?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="cursor-pointer text-sm font-semibold text-error underline">
                                            Supprimer
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact --filter="shows up and down order controls"`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add resources/views/admin/positions/index.blade.php tests/Feature/Admin/PositionManagementTest.php
git commit -m "feat: show order controls on the positions admin list"
```

---

### Task 4: Roster payload — `positionOrder` + sort within groups

**Files:**
- Modify: `resources/views/admin/sessions/show.blade.php`
- Modify: `resources/js/app.js`
- Test: `tests/Feature/Admin/AttendanceDashboardTest.php`

**Interfaces:**
- Consumes: `$attendance->position?->order` (Task 1).
- Produces: each JSON record in the `attendanceDashboard` payload gains `positionOrder: number|null`. `attendanceDashboard` gains a `sortByPosition(records)` method, used by the `groups` getter — later tasks (Task 5) reuse `sortByPosition`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Admin/AttendanceDashboardTest.php`:

```php
it('includes each attendances position order in the roster payload', function () {
    $meetingSession = MeetingSession::factory()->create();
    $rotaryTitle = Title::where('name', 'Rotary')->sole();
    $president = $rotaryTitle->positions()->where('name', 'Président')->sole();
    $member = $rotaryTitle->positions()->where('name', 'Membre')->sole();
    $president->update(['order' => 0]);
    $member->update(['order' => 10]);

    Attendance::factory()->for($meetingSession)->create([
        'title_id' => $rotaryTitle->id,
        'position_id' => $member->id,
        'name' => 'Awa Bello',
    ]);
    Attendance::factory()->for($meetingSession)->create([
        'title_id' => $rotaryTitle->id,
        'position_id' => $president->id,
        'name' => 'Jean Dupont',
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.show', $meetingSession));

    $response->assertOk();

    preg_match("/attendanceDashboard\(JSON\.parse\('(.+?)'\)\)/s", $response->getContent(), $matches);
    $json = str_replace(chr(92).'u0022', '"', $matches[1]);
    $records = collect(json_decode($json, true))->keyBy('name');

    expect($records['Jean Dupont']['positionOrder'])->toBe(0)
        ->and($records['Awa Bello']['positionOrder'])->toBe(10);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter="includes each attendances position order"`
Expected: FAIL — `positionOrder` key missing from the decoded records.

- [ ] **Step 3: Add `positionOrder` to the JSON payload**

In `resources/views/admin/sessions/show.blade.php`, in the `x-data="attendanceDashboard(...)"` map (lines 3-15), add the field after `'position'`:

```php
        x-data="attendanceDashboard(@js($attendances->map(fn ($attendance) => [
            'id' => $attendance->id,
            'name' => $attendance->name,
            'title' => $attendance->title->name,
            'position' => $attendance->position?->name,
            'positionOrder' => $attendance->position?->order,
            'club' => $attendance->club,
            'phone' => $attendance->phone,
            'category' => $attendance->category->value,
            'categoryLabel' => $attendance->category->label(),
            'present' => $attendance->present,
            'isLate' => $attendance->is_late,
            'hasMisc' => $attendance->has_misc,
        ])))"
```

- [ ] **Step 4: Add `sortByPosition` and apply it in `groups`**

In `resources/js/app.js`, replace the `get groups()` block (lines 29-38) with:

```js
    get groups() {
        const order = ['officials', 'members', 'rotaractors', 'guests'];

        return order
            .map((category) => ({
                category,
                records: this.sortByPosition(this.filtered.filter((record) => record.category === category)),
            }))
            .filter((group) => group.records.length > 0);
    },
    sortByPosition(records) {
        return [...records].sort((a, b) => {
            const aOrder = a.positionOrder ?? Infinity;
            const bOrder = b.positionOrder ?? Infinity;

            if (aOrder !== bOrder) return aOrder - bOrder;

            return a.name.localeCompare(b.name);
        });
    },
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --compact --filter="includes each attendances position order"`
Expected: PASS

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/admin/sessions/show.blade.php resources/js/app.js tests/Feature/Admin/AttendanceDashboardTest.php
git commit -m "feat: sort roster groups by position order"
```

---

### Task 5: Roster sort-mode toggle — grouped vs. flat by position

**Files:**
- Modify: `resources/views/admin/sessions/show.blade.php`
- Modify: `resources/js/app.js`
- Test: `tests/Feature/Admin/AttendanceDashboardTest.php`

**Interfaces:**
- Consumes: `sortByPosition(records)` from Task 4.
- Produces: `attendanceDashboard.sortMode` (`'grouped' | 'position'`), `attendanceDashboard.flatSorted` getter.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Admin/AttendanceDashboardTest.php`:

```php
it('exposes a sort-mode toggle button on the roster', function () {
    $meetingSession = MeetingSession::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.show', $meetingSession))
        ->assertOk()
        ->assertSee('sortMode = sortMode', false)
        ->assertSee('x-show="sortMode === \'position\'"', false);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter="exposes a sort-mode toggle button"`
Expected: FAIL — no such markup on the page yet.

- [ ] **Step 3: Add `sortMode` and `flatSorted` to `app.js`**

In `resources/js/app.js`, inside `Alpine.data('attendanceDashboard', ...)`, add `sortMode: 'grouped',` next to the other reactive properties (after `activeMiscFilter: 'all',`), and add a `flatSorted` getter next to `groups`:

```js
    activeMiscFilter: 'all',
    sortMode: 'grouped',
```

```js
    get flatSorted() {
        return this.sortByPosition(this.filtered);
    },
```

- [ ] **Step 4: Add the toggle button and flat-list block**

In `resources/views/admin/sessions/show.blade.php`, add the toggle button at the end of the filter row (after the "Sans divers" button, before the row's closing `</div>` — currently ending at line 159):

```blade
            <span class="h-6 w-px bg-divider md:mx-1"></span>
            <button type="button" @click="sortMode = sortMode === 'grouped' ? 'position' : 'grouped'"
                class="cursor-pointer rounded-full border border-border px-3 py-2 text-xs font-semibold text-navy">
                <span x-text="sortMode === 'grouped' ? 'Trier par poste' : 'Grouper par organisation'"></span>
            </button>
```

Then replace the roster container (lines 161-195, from `<div class="max-h-[520px] overflow-y-auto px-4 pb-8 md:px-8">` to its closing `</div>`) with:

```blade
        <div class="max-h-[520px] overflow-y-auto px-4 pb-8 md:px-8">
            <div x-show="sortMode === 'grouped'">
                <template x-for="group in groups" :key="group.category">
                    <div class="mb-5">
                        <p class="mb-2 text-xs font-semibold uppercase text-muted-strong" x-text="group.records[0].categoryLabel + ' (' + group.records.length + ')'"></p>
                        <template x-for="record in group.records" :key="record.id">
                            <div class="flex flex-col gap-2 border-b border-divider py-2.5 sm:flex-row sm:items-center sm:justify-between">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-[34px] w-[34px] shrink-0 items-center justify-center rounded-full bg-divider text-xs font-bold" x-text="initials(record.name)"></div>
                                    <div>
                                        <p class="text-[14.5px] font-semibold text-navy" x-text="record.name"></p>
                                        <p class="text-[12.5px] text-muted-strong">
                                            <span x-text="record.title + (record.position ? ' — ' + record.position : '') + ' · ' + record.club"></span>
                                            <span x-show="record.isLate" class="font-bold text-gold"> · marqué en retard</span>
                                            <span x-show="record.hasMisc" class="font-bold text-navy"> · divers</span>
                                        </p>
                                        <p class="mt-0.5 font-mono text-xs text-muted-strong sm:hidden" x-text="record.phone"></p>
                                    </div>
                                </div>
                                <div class="flex items-center justify-between gap-3 sm:justify-end">
                                    <span class="hidden font-mono text-sm text-muted-strong sm:inline" x-text="record.phone"></span>
                                    <form method="POST" :action="'/admin/attendances/' + record.id + '/toggle-present'">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit"
                                            :class="record.present ? 'bg-success-bg text-success' : 'border border-border text-muted'"
                                            class="cursor-pointer rounded-lg px-3 py-1.5 text-xs font-semibold">
                                            <span x-text="record.present ? 'Présent' : 'Marquer présent'"></span>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
            <div x-show="sortMode === 'position'">
                <template x-for="record in flatSorted" :key="record.id">
                    <div class="flex flex-col gap-2 border-b border-divider py-2.5 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex items-center gap-3">
                            <div class="flex h-[34px] w-[34px] shrink-0 items-center justify-center rounded-full bg-divider text-xs font-bold" x-text="initials(record.name)"></div>
                            <div>
                                <p class="text-[14.5px] font-semibold text-navy" x-text="record.name"></p>
                                <p class="text-[12.5px] text-muted-strong">
                                    <span x-text="record.title + (record.position ? ' — ' + record.position : '') + ' · ' + record.club"></span>
                                    <span x-show="record.isLate" class="font-bold text-gold"> · marqué en retard</span>
                                    <span x-show="record.hasMisc" class="font-bold text-navy"> · divers</span>
                                </p>
                                <p class="mt-0.5 font-mono text-xs text-muted-strong sm:hidden" x-text="record.phone"></p>
                            </div>
                        </div>
                        <div class="flex items-center justify-between gap-3 sm:justify-end">
                            <span class="hidden font-mono text-sm text-muted-strong sm:inline" x-text="record.phone"></span>
                            <form method="POST" :action="'/admin/attendances/' + record.id + '/toggle-present'">
                                @csrf
                                @method('PATCH')
                                <button type="submit"
                                    :class="record.present ? 'bg-success-bg text-success' : 'border border-border text-muted'"
                                    class="cursor-pointer rounded-lg px-3 py-1.5 text-xs font-semibold">
                                    <span x-text="record.present ? 'Présent' : 'Marquer présent'"></span>
                                </button>
                            </form>
                        </div>
                    </div>
                </template>
            </div>
        </div>
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --compact --filter="exposes a sort-mode toggle button"`
Expected: PASS

- [ ] **Step 6: Run the full test suite**

Run: `php artisan test --compact`
Expected: PASS, no regressions.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/admin/sessions/show.blade.php resources/js/app.js tests/Feature/Admin/AttendanceDashboardTest.php
git commit -m "feat: add a flat sort-by-position mode to the attendance roster"
```

- [ ] **Step 8: Manual browser verification**

Run `npm run build` (or ask the user to run `npm run dev`/`composer run dev` if they want live-reload), then open a meeting session's admin roster page (`/admin/sessions/{id}`). Confirm:
- The roster groups render in the same order as before, each internally sorted by poste (e.g. "Président" above "Membre" within the same organisation).
- Clicking "Trier par poste" flattens the list into a single poste-ordered sequence spanning organisations, and the button label flips to "Grouper par organisation".
- Clicking it again restores the grouped view.

---

## Self-Review Notes

- **Spec coverage:** §1 (data model) → Task 1. §2 (admin UI, `moveOrder`) → Tasks 2-3. §3 (roster sort — grouped-mode position sort, flat mode toggle) → Tasks 4-5. All spec sections have a corresponding task.
- **Placeholder scan:** no TBD/TODO; every step has complete, runnable code.
- **Type consistency:** `sortByPosition` (defined Task 4, reused Task 5) keeps the same name and signature throughout; `positionOrder` field name is consistent between the Blade payload (Task 4) and the JS comparator (Tasks 4-5); route name `admin.positions.move-order` matches between Task 2 (definition) and Task 3 (view usage).
