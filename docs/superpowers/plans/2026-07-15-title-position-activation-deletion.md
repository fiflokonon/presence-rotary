# Titre/Poste Activation & Deletion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an admin deactivate (reversibly hide from future selection) or delete (permanently, when unused) a `Title` or `Position`, without breaking any existing `Member`/`Attendance` record that already references it.

**Architecture:** Add an `is_active` boolean to both `titles` and `positions`. Two new model scopes (`active()`, `activeOrId()`) filter every select/checkbox list in the app to "active, or whatever this specific record already has" — so nothing already-assigned silently disappears from its own form. New `toggleActive`/`destroy` controller actions follow the app's existing toggle/thin-controller conventions; deletion relies entirely on the already-existing `restrictOnDelete` foreign keys, caught and translated into a friendly flashed message.

**Tech Stack:** Laravel 13, PHP 8.4, Pest 4, Alpine.js (existing cascade), Tailwind v4 (existing `bg-success-bg text-success` / `bg-divider text-muted` badge tokens, already used for `MeetingSession::is_open`).

## Global Constraints

- Follow existing repo conventions exactly: thin controllers, `Model::update()`/`delete()` directly for simple toggles (no FormRequest needed for a boolean flip), Blade views under `resources/views/admin/<resource>/`.
- Deactivation is reversible and always allowed — never blocked by usage.
- Deletion is permanent and only succeeds when nothing references the row — rely on the existing `restrictOnDelete` FK constraints (from the earlier titre/poste plan), catch `Illuminate\Database\QueryException`, flash a French error via `session('error')`, never a pre-emptive existence check.
- No cascading between `Title.is_active` and `Position.is_active` — deactivating one never touches the other's flag.
- "Active, or the record's own current value" applies everywhere a select/checkbox list is built: `AttendanceFormController::show()`/`lookup()`, `MemberController::edit()`, `TitleController::edit()`. `TitleController::create()` is active-only (nothing to preserve).
- Inactive-but-shown options get `' (inactif)'` appended to their label. Never use the HTML `disabled` attribute on these — rely on query scoping alone, since disabled-option submission semantics are inconsistent across browsers.
- Existing color tokens (confirmed in `resources/css/app.css` and used for `MeetingSession::is_open` in `resources/views/admin/sessions/show.blade.php:58`): `bg-success-bg text-success` for an active/positive badge, `bg-divider text-muted` for inactive/negative. Reuse these exactly — do not invent new token names.
- Spec: `docs/superpowers/specs/2026-07-15-title-position-activation-deletion-design.md`

---

### Task 1: `is_active` column and model scopes

**Files:**
- Create: `database/migrations/2026_07_15_130000_add_is_active_to_titles_and_positions_tables.php`
- Modify: `app/Models/Title.php`
- Modify: `app/Models/Position.php`
- Test: `tests/Feature/Models/TitleTest.php` (extend)
- Test: `tests/Feature/Models/PositionTest.php` (new)

**Interfaces:**
- Produces: `Title::active()`/`Title::activeOrId(?int $id)` and `Position::active()`/`Position::activeOrId(?int $id)` query scopes. Both models gain `is_active` (boolean, default `true`) as a real column, in `$fillable`, cast to `boolean`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Models/TitleTest.php`:

```php
it('defaults is_active to true', function () {
    $title = Title::factory()->create();

    expect($title->is_active)->toBeTrue();
});

it('scopes to active titles only', function () {
    Title::factory()->create(['is_active' => true, 'name' => 'Active One']);
    Title::factory()->create(['is_active' => false, 'name' => 'Inactive One']);

    expect(Title::active()->pluck('name')->all())->toBe(['Active One']);
});

it('scopes to active titles plus a specific inactive id', function () {
    $active = Title::factory()->create(['is_active' => true]);
    $inactive = Title::factory()->create(['is_active' => false]);
    Title::factory()->create(['is_active' => false]);

    $ids = Title::activeOrId($inactive->id)->pluck('id')->sort()->values()->all();

    expect($ids)->toBe(collect([$active->id, $inactive->id])->sort()->values()->all());
});

it('activeOrId with a null id behaves like active alone', function () {
    $active = Title::factory()->create(['is_active' => true]);
    Title::factory()->create(['is_active' => false]);

    expect(Title::activeOrId(null)->pluck('id')->all())->toBe([$active->id]);
});
```

Create `tests/Feature/Models/PositionTest.php`:

```php
<?php

use App\Models\Position;

it('defaults is_active to true', function () {
    $position = Position::factory()->create();

    expect($position->is_active)->toBeTrue();
});

it('scopes to active positions only', function () {
    Position::factory()->create(['is_active' => true, 'name' => 'Active Poste']);
    Position::factory()->create(['is_active' => false, 'name' => 'Inactive Poste']);

    expect(Position::active()->pluck('name')->all())->toBe(['Active Poste']);
});

it('scopes to active positions plus a specific inactive id', function () {
    $active = Position::factory()->create(['is_active' => true]);
    $inactive = Position::factory()->create(['is_active' => false]);
    Position::factory()->create(['is_active' => false]);

    $ids = Position::activeOrId($inactive->id)->pluck('id')->sort()->values()->all();

    expect($ids)->toBe(collect([$active->id, $inactive->id])->sort()->values()->all());
});

it('activeOrId with a null id behaves like active alone', function () {
    $active = Position::factory()->create(['is_active' => true]);
    Position::factory()->create(['is_active' => false]);

    expect(Position::activeOrId(null)->pluck('id')->all())->toBe([$active->id]);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=TitleTest`
Run: `php artisan test --filter=PositionTest`
Expected: FAIL — `is_active` column doesn't exist yet; `active()`/`activeOrId()` scopes don't exist.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('titles', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('category');
        });
        Schema::table('positions', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('titles', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
```

Save as `database/migrations/2026_07_15_130000_add_is_active_to_titles_and_positions_tables.php`.

- [ ] **Step 4: Update the models**

```php
// app/Models/Title.php
<?php

namespace App\Models;

use App\Enums\AttendanceCategory;
use Database\Factories\TitleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Title extends Model
{
    /** @use HasFactory<TitleFactory> */
    use HasFactory;

    protected $fillable = ['name', 'category', 'is_active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => AttendanceCategory::class,
            'is_active' => 'boolean',
        ];
    }

    public function positions(): BelongsToMany
    {
        return $this->belongsToMany(Position::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeActiveOrId(Builder $query, ?int $id): void
    {
        $query->where('is_active', true)->when(
            $id !== null,
            fn (Builder $q) => $q->orWhere('id', $id),
        );
    }
}
```

```php
// app/Models/Position.php
<?php

namespace App\Models;

use Database\Factories\PositionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Position extends Model
{
    /** @use HasFactory<PositionFactory> */
    use HasFactory;

    protected $fillable = ['name', 'is_active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function titles(): BelongsToMany
    {
        return $this->belongsToMany(Title::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeActiveOrId(Builder $query, ?int $id): void
    {
        $query->where('is_active', true)->when(
            $id !== null,
            fn (Builder $q) => $q->orWhere('id', $id),
        );
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=TitleTest`
Run: `php artisan test --filter=PositionTest`
Expected: both PASS.

- [ ] **Step 6: Run the full suite**

Run: `php artisan test --compact`
Expected: PASS — 119 + 8 new = 127 tests, 100%. (No existing code reads `is_active` yet, so nothing else is affected.)

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_07_15_130000_add_is_active_to_titles_and_positions_tables.php \
  app/Models/Title.php app/Models/Position.php \
  tests/Feature/Models/TitleTest.php tests/Feature/Models/PositionTest.php
git commit -m "Add is_active column and active/activeOrId scopes to Title and Position"
```

---

### Task 2: Admin toggle-active and delete actions

**Files:**
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/Admin/TitleController.php`
- Modify: `app/Http/Controllers/Admin/PositionController.php`
- Modify: `resources/views/admin/titles/index.blade.php`
- Modify: `resources/views/admin/positions/index.blade.php`
- Modify: `tests/Feature/Admin/TitleManagementTest.php`
- Modify: `tests/Feature/Admin/PositionManagementTest.php`

**Interfaces:**
- Consumes: `is_active` column from Task 1.
- Produces: `admin.titles.toggle-active`, `admin.titles.destroy`, `admin.positions.toggle-active`, `admin.positions.destroy` named routes. `TitleController::toggleActive(Title): RedirectResponse`, `TitleController::destroy(Title): RedirectResponse` (same shape on `PositionController`).

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/Admin/TitleManagementTest.php`, extending the existing
`'redirects guests to login for every title route'` test's body with two
more assertions (after the existing `assertRedirect` lines, before the
closing `});`):

```php
    $this->patch(route('admin.titles.toggle-active', $title))->assertRedirect(route('admin.login'));
    $this->delete(route('admin.titles.destroy', $title))->assertRedirect(route('admin.login'));
```

Then add these new tests to the same file:

```php
it('toggles a titles active status', function () {
    $title = Title::factory()->create(['is_active' => true]);

    $this->actingAs(User::factory()->create())
        ->patch(route('admin.titles.toggle-active', $title))
        ->assertRedirect(route('admin.titles.index'));

    expect($title->fresh()->is_active)->toBeFalse();

    $this->actingAs(User::factory()->create())
        ->patch(route('admin.titles.toggle-active', $title))
        ->assertRedirect(route('admin.titles.index'));

    expect($title->fresh()->is_active)->toBeTrue();
});

it('deletes an unused title', function () {
    $title = Title::factory()->create();

    $this->actingAs(User::factory()->create())
        ->delete(route('admin.titles.destroy', $title))
        ->assertRedirect(route('admin.titles.index'));

    expect(Title::find($title->id))->toBeNull();
});

it('blocks deleting a title referenced by a member with a friendly message', function () {
    $title = Title::factory()->create();
    Member::factory()->create(['title_id' => $title->id]);

    $this->actingAs(User::factory()->create())
        ->delete(route('admin.titles.destroy', $title))
        ->assertRedirect(route('admin.titles.index'))
        ->assertSessionHas('error');

    expect(Title::find($title->id))->not->toBeNull();
});
```

Add `use App\Models\Member;` to the top of the file if not already present
(check the existing imports first — `TitleManagementTest.php` currently
imports `AttendanceCategory`, `Position`, `Title`, `User`).

Add to `tests/Feature/Admin/PositionManagementTest.php`, extending the
existing `'redirects guests to login for every position route'` test the
same way:

```php
    $this->patch(route('admin.positions.toggle-active', $position))->assertRedirect(route('admin.login'));
    $this->delete(route('admin.positions.destroy', $position))->assertRedirect(route('admin.login'));
```

Then add:

```php
it('toggles a positions active status', function () {
    $position = Position::factory()->create(['is_active' => true]);

    $this->actingAs(User::factory()->create())
        ->patch(route('admin.positions.toggle-active', $position))
        ->assertRedirect(route('admin.positions.index'));

    expect($position->fresh()->is_active)->toBeFalse();
});

it('deletes an unused position', function () {
    $position = Position::factory()->create();

    $this->actingAs(User::factory()->create())
        ->delete(route('admin.positions.destroy', $position))
        ->assertRedirect(route('admin.positions.index'));

    expect(Position::find($position->id))->toBeNull();
});

it('blocks deleting a position referenced by an attendance with a friendly message', function () {
    $position = Position::factory()->create();
    Attendance::factory()->create(['position_id' => $position->id]);

    $this->actingAs(User::factory()->create())
        ->delete(route('admin.positions.destroy', $position))
        ->assertRedirect(route('admin.positions.index'))
        ->assertSessionHas('error');

    expect(Position::find($position->id))->not->toBeNull();
});
```

Add `use App\Models\Attendance;` to the top of the file if not already present.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=TitleManagementTest`
Run: `php artisan test --filter=PositionManagementTest`
Expected: FAIL — routes `admin.titles.toggle-active`/`admin.titles.destroy`/`admin.positions.toggle-active`/`admin.positions.destroy` don't exist.

- [ ] **Step 3: Add the routes**

In `routes/web.php`, add right after the existing `titles.update` line:

```php
Route::patch('titles/{title}/toggle-active', [TitleController::class, 'toggleActive'])->name('titles.toggle-active');
Route::delete('titles/{title}', [TitleController::class, 'destroy'])->name('titles.destroy');
```

And right after the existing `positions.update` line:

```php
Route::patch('positions/{position}/toggle-active', [PositionController::class, 'toggleActive'])->name('positions.toggle-active');
Route::delete('positions/{position}', [PositionController::class, 'destroy'])->name('positions.destroy');
```

- [ ] **Step 4: Add the controller actions**

In `app/Http/Controllers/Admin/TitleController.php`, add `use Illuminate\Database\QueryException;` to the imports, then add these two methods (after `update`, before the closing `}`):

```php
public function toggleActive(Title $title): RedirectResponse
{
    $title->update(['is_active' => ! $title->is_active]);

    return redirect()->route('admin.titles.index');
}

public function destroy(Title $title): RedirectResponse
{
    try {
        $title->delete();
    } catch (QueryException) {
        return redirect()->route('admin.titles.index')
            ->with('error', 'Ce titre est utilisé par des membres ou des présences existantes — désactivez-le plutôt que de le supprimer.');
    }

    return redirect()->route('admin.titles.index');
}
```

In `app/Http/Controllers/Admin/PositionController.php`, same import addition, then:

```php
public function toggleActive(Position $position): RedirectResponse
{
    $position->update(['is_active' => ! $position->is_active]);

    return redirect()->route('admin.positions.index');
}

public function destroy(Position $position): RedirectResponse
{
    try {
        $position->delete();
    } catch (QueryException) {
        return redirect()->route('admin.positions.index')
            ->with('error', 'Ce poste est utilisé par des membres ou des présences existantes — désactivez-le plutôt que de le supprimer.');
    }

    return redirect()->route('admin.positions.index');
}
```

- [ ] **Step 5: Update the index views**

Replace the full contents of `resources/views/admin/titles/index.blade.php`:

```blade
<x-layouts.admin title="Titres — Administration">
    <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)] md:p-8">
        <div class="flex items-center justify-between gap-3">
            <h1 class="font-display text-xl font-extrabold text-navy">Titres</h1>
            <a href="{{ route('admin.titles.create') }}"
                class="cursor-pointer rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                Ajouter un titre
            </a>
        </div>

        @if (session('error'))
            <div class="mt-4 rounded-lg bg-error-bg px-4 py-3 text-sm text-error">
                {{ session('error') }}
            </div>
        @endif

        <div class="mt-6 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-divider text-muted-strong">
                        <th class="py-2 pr-4 font-semibold">Nom</th>
                        <th class="py-2 pr-4 font-semibold">Catégorie</th>
                        <th class="py-2 pr-4 font-semibold">Postes liés</th>
                        <th class="py-2 pr-4 font-semibold">Statut</th>
                        <th class="py-2 pr-4 font-semibold"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-divider">
                    @foreach ($titles as $title)
                        <tr>
                            <td class="py-3 pr-4 font-semibold text-navy">{{ $title->name }}</td>
                            <td class="py-3 pr-4">{{ $title->category->label() }}</td>
                            <td class="py-3 pr-4">{{ $title->positions_count }}</td>
                            <td class="py-3 pr-4">
                                <span class="rounded-full px-2 py-1 text-xs font-semibold {{ $title->is_active ? 'bg-success-bg text-success' : 'bg-divider text-muted' }}">
                                    {{ $title->is_active ? 'Actif' : 'Inactif' }}
                                </span>
                            </td>
                            <td class="py-3 pr-4 text-right">
                                <div class="flex items-center justify-end gap-3">
                                    <a href="{{ route('admin.titles.edit', $title) }}" class="text-sm font-semibold text-navy underline">
                                        Modifier
                                    </a>
                                    <form method="POST" action="{{ route('admin.titles.toggle-active', $title) }}">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="cursor-pointer text-sm font-semibold text-navy underline">
                                            {{ $title->is_active ? 'Désactiver' : 'Activer' }}
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.titles.destroy', $title) }}"
                                        onsubmit="return confirm('Supprimer définitivement ce titre ?');">
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
        </div>
    </div>
</x-layouts.admin>
```

Replace the full contents of `resources/views/admin/positions/index.blade.php`:

```blade
<x-layouts.admin title="Postes — Administration">
    <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)] md:p-8">
        <div class="flex items-center justify-between gap-3">
            <h1 class="font-display text-xl font-extrabold text-navy">Postes</h1>
            <a href="{{ route('admin.positions.create') }}"
                class="cursor-pointer rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                Ajouter un poste
            </a>
        </div>

        @if (session('error'))
            <div class="mt-4 rounded-lg bg-error-bg px-4 py-3 text-sm text-error">
                {{ session('error') }}
            </div>
        @endif

        <div class="mt-6 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-divider text-muted-strong">
                        <th class="py-2 pr-4 font-semibold">Nom</th>
                        <th class="py-2 pr-4 font-semibold">Statut</th>
                        <th class="py-2 pr-4 font-semibold"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-divider">
                    @foreach ($positions as $position)
                        <tr>
                            <td class="py-3 pr-4 font-semibold text-navy">{{ $position->name }}</td>
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
                                        onsubmit="return confirm('Supprimer définitivement ce poste ?');">
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
        </div>
    </div>
</x-layouts.admin>
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=TitleManagementTest`
Run: `php artisan test --filter=PositionManagementTest`
Expected: both PASS.

- [ ] **Step 7: Run the full suite**

Run: `php artisan test --compact`
Expected: PASS, 100%.

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add routes/web.php app/Http/Controllers/Admin/TitleController.php app/Http/Controllers/Admin/PositionController.php \
  resources/views/admin/titles/index.blade.php resources/views/admin/positions/index.blade.php \
  tests/Feature/Admin/TitleManagementTest.php tests/Feature/Admin/PositionManagementTest.php
git commit -m "Add admin toggle-active and delete actions for titles and positions"
```

---

### Task 3: Filter the Title admin form's poste checkboxes by active state

**Files:**
- Modify: `app/Http/Controllers/Admin/TitleController.php`
- Modify: `resources/views/admin/titles/edit.blade.php`
- Modify: `tests/Feature/Admin/TitleManagementTest.php`

**Interfaces:**
- Consumes: `Position::active()` scope (Task 1).
- Produces: `TitleController::create()` offers active positions only; `TitleController::edit()` offers active positions plus whatever is already linked to that specific title (regardless of active state).

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/Admin/TitleManagementTest.php`:

```php
it('does not offer an inactive position when creating a new title', function () {
    Position::factory()->create(['name' => 'Poste Retraité', 'is_active' => false]);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.titles.create'))
        ->assertOk()
        ->assertDontSee('Poste Retraité');
});

it('still shows an inactive position already linked to a title being edited', function () {
    $title = Title::factory()->create();
    $inactivePosition = Position::factory()->create(['name' => 'Poste Retraité', 'is_active' => false]);
    $title->positions()->attach($inactivePosition);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.titles.edit', $title))
        ->assertOk()
        ->assertSee('Poste Retraité (inactif)');
});

it('does not offer an inactive position not linked to a title being edited', function () {
    $title = Title::factory()->create();
    Position::factory()->create(['name' => 'Poste Non Lié', 'is_active' => false]);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.titles.edit', $title))
        ->assertOk()
        ->assertDontSee('Poste Non Lié');
});

it('detaches an inactive linked position when unchecked on update', function () {
    $title = Title::factory()->create(['category' => AttendanceCategory::Guests]);
    $inactivePosition = Position::factory()->create(['is_active' => false]);
    $title->positions()->attach($inactivePosition);

    $this->actingAs(User::factory()->create())
        ->put(route('admin.titles.update', $title), [
            'name' => $title->name,
            'category' => AttendanceCategory::Guests->value,
            'position_ids' => [],
        ])->assertRedirect(route('admin.titles.index'));

    expect($title->positions()->count())->toBe(0);
});
```

(`AttendanceCategory` is already imported at the top of this test file.)

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=TitleManagementTest`
Expected: FAIL — `create()`/`edit()` still show every position regardless of `is_active`, so "does not offer" assertions fail.

- [ ] **Step 3: Update the controller**

In `app/Http/Controllers/Admin/TitleController.php`, replace `create()` and `edit()`:

```php
public function create(): View
{
    return view('admin.titles.create', [
        'positions' => Position::active()->orderBy('name')->get(),
    ]);
}
```

```php
public function edit(Title $title): View
{
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
```

- [ ] **Step 4: Update the edit view's checkbox label**

In `resources/views/admin/titles/edit.blade.php`, change the checkbox label line from:

```blade
{{ $position->name }}
```

to:

```blade
{{ $position->name }}{{ $position->is_active ? '' : ' (inactif)' }}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=TitleManagementTest`
Expected: PASS.

- [ ] **Step 6: Run the full suite**

Run: `php artisan test --compact`
Expected: PASS, 100%.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Admin/TitleController.php resources/views/admin/titles/edit.blade.php \
  tests/Feature/Admin/TitleManagementTest.php
git commit -m "Filter Title admin form's poste checkboxes by active state"
```

---

### Task 4: Active-or-current filtering on the check-in and member-edit forms

**Files:**
- Modify: `app/Http/Controllers/AttendanceFormController.php`
- Modify: `app/Http/Controllers/Admin/MemberController.php`
- Modify: `resources/views/components/attendance-form.blade.php`
- Modify: `resources/views/admin/members/edit.blade.php`
- Modify: `tests/Feature/AttendanceFormTest.php`
- Modify: `tests/Feature/AttendanceMemberCheckInTest.php`
- Modify: `tests/Feature/Admin/MemberManagementTest.php`

**Interfaces:**
- Consumes: `Title::activeOrId()`/`Position::activeOrId()` scopes (Task 1).
- Produces: the public check-in form and admin member-edit form both offer active titres/postes, plus whatever the resolved member (if any) already has — with `' (inactif)'` appended to any inactive-but-shown option's label.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/AttendanceFormTest.php`:

```php
it('does not offer an inactive title on a blank check-in form', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);
    Title::factory()->create(['name' => 'Ancien Titre', 'is_active' => false]);

    $this->get(route('attendance.show'))
        ->assertOk()
        ->assertDontSee('Ancien Titre');
});
```

(`Title` needs importing at the top of this file if not already present —
check first; `MeetingSession` is already imported.)

Add to `tests/Feature/AttendanceMemberCheckInTest.php`:

```php
it('still shows a returning members inactive title and position, marked inactive', function () {
    MeetingSession::factory()->create(['is_active' => true, 'is_open' => true]);
    $title = Title::factory()->create(['name' => 'Titre Retraité', 'is_active' => false]);
    $position = Position::factory()->create(['name' => 'Poste Retraité', 'is_active' => false]);
    $title->positions()->attach($position);

    Member::factory()->create([
        'email' => 'ancien@example.com',
        'title_id' => $title->id,
        'position_id' => $position->id,
    ]);

    $this->post(route('attendance.lookup'), ['email' => 'ancien@example.com'])
        ->assertOk()
        ->assertSee('Titre Retraité (inactif)')
        ->assertSee('Poste Retraité (inactif)', false);
});
```

Add `use App\Models\Position; use App\Models\Title;` to the top of this
file if not already present (`Member` and `MeetingSession` already are).
Note: the poste name is embedded in the page as JSON (via
`Illuminate\Support\Js::from`, inside the Alpine `positionsByTitle` map),
not as plain HTML text, so `assertSee('Poste Retraité (inactif)', false)`
(the second `false` argument disables HTML-escaping the expected string
before comparison) is needed — plain `assertSee` HTML-escapes the needle,
which would fail to match the JSON-encoded apostrophe-free string here.
Check this assertion's actual behavior when you run it; if it still
doesn't match, inspect the raw response content
(`$response->getContent()`) to see exactly how `Js::from` encoded the
name and adjust the assertion to match verbatim — don't guess further.

Add to `tests/Feature/Admin/MemberManagementTest.php`:

```php
it('does not offer an inactive title for a member whose current title is different', function () {
    $member = Member::factory()->create();
    Title::factory()->create(['name' => 'Titre Retraité', 'is_active' => false]);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.members.edit', $member))
        ->assertOk()
        ->assertDontSee('Titre Retraité');
});

it('still offers a members own inactive title and position on their edit form', function () {
    $title = Title::factory()->create(['name' => 'Titre Retraité', 'is_active' => false]);
    $position = Position::factory()->create(['name' => 'Poste Retraité', 'is_active' => false]);
    $title->positions()->attach($position);
    $member = Member::factory()->create(['title_id' => $title->id, 'position_id' => $position->id]);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.members.edit', $member))
        ->assertOk()
        ->assertSee('Titre Retraité (inactif)');
});
```

(`Title`, `Position`, `Member`, `User` are already imported at the top of
this file.)

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=AttendanceFormTest`
Run: `php artisan test --filter=AttendanceMemberCheckInTest`
Run: `php artisan test --filter=MemberManagementTest`
Expected: FAIL — every title/position currently shows regardless of `is_active`, so the "does not offer" assertions fail, and no `' (inactif)'` suffix exists yet.

- [ ] **Step 3: Update `AttendanceFormController`**

Replace `show()` and `lookup()`:

```php
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
        'titles' => Title::activeOrId($member?->title_id)
            ->with(['positions' => fn ($query) => $query->activeOrId($member?->position_id)])
            ->orderBy('name')
            ->get(),
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
        'titles' => Title::activeOrId($member?->title_id)
            ->with(['positions' => fn ($query) => $query->activeOrId($member?->position_id)])
            ->orderBy('name')
            ->get(),
    ]);
}
```

Note `MeetingSession::active()` here is the existing `MeetingSession`
model's own "find the currently active session" method — unrelated to the
new `Title`/`Position` active scopes added in this plan, despite the
identical method name; do not change it.

- [ ] **Step 4: Update `MemberController::edit`**

```php
public function edit(Member $member): View
{
    return view('admin.members.edit', [
        'member' => $member,
        'titles' => Title::activeOrId($member->title_id)
            ->with(['positions' => fn ($query) => $query->activeOrId($member->position_id)])
            ->orderBy('name')
            ->get(),
    ]);
}
```

- [ ] **Step 5: Update the check-in form view**

In `resources/views/components/attendance-form.blade.php`, change the
`positionsByTitle` map to append the inactive suffix to each poste's name:

```blade
positionsByTitle: {{ Illuminate\Support\Js::from($titles->mapWithKeys(fn ($t) => [
    $t->id => $t->positions->map(fn ($p) => [
        'id' => $p->id,
        'name' => $p->is_active ? $p->name : $p->name.' (inactif)',
    ])->values(),
])) }},
```

And the titre `<option>` loop:

```blade
@foreach ($titles as $titleOption)
    <option value="{{ $titleOption->id }}">{{ $titleOption->is_active ? $titleOption->name : $titleOption->name.' (inactif)' }}</option>
@endforeach
```

- [ ] **Step 6: Update the admin member-edit form view**

Apply the identical two changes to `resources/views/admin/members/edit.blade.php`'s `positionsByTitle` map and titre `<option>` loop.

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --filter=AttendanceFormTest`
Run: `php artisan test --filter=AttendanceMemberCheckInTest`
Run: `php artisan test --filter=MemberManagementTest`
Expected: all PASS. If the JSON-encoding assertion from Step 1 still
doesn't match, inspect `$response->getContent()` directly to see the exact
encoded string and adjust — don't guess repeatedly.

- [ ] **Step 8: Run the full suite**

Run: `php artisan test --compact`
Expected: PASS, 100%.

- [ ] **Step 9: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/AttendanceFormController.php app/Http/Controllers/Admin/MemberController.php \
  resources/views/components/attendance-form.blade.php resources/views/admin/members/edit.blade.php \
  tests/Feature/AttendanceFormTest.php tests/Feature/AttendanceMemberCheckInTest.php \
  tests/Feature/Admin/MemberManagementTest.php
git commit -m "Filter check-in and member-edit forms by active titre/poste, preserving current values"
```
