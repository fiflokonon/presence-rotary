# Principal Organizations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the fixed 4-value `AttendanceCategory` enum with an admin-controlled `is_principal` boolean on `Title`, capped at 3 simultaneously-flagged organisations, so the admin dashboard/roster/PDF group attendances by organisation name (for principal organisations) or a fixed "Autres organisations" bucket (for everything else).

**Architecture:** Expand-then-contract schema change: add `titles.is_principal` and relax `titles.category` to nullable first (Task 1), migrate every consumer over to the new `Attendance::groupLabel` accessor and `Title::principal()` scope (Tasks 2-5), then drop `titles.category` and delete `AttendanceCategory` entirely once nothing references it (Task 6). This keeps the app fully working and the test suite green after every task.

**Tech Stack:** Laravel 13 / PHP 8.4, Pest 4, Alpine.js, Blade, DomPDF, SQLite.

## Global Constraints

- **Depends on `docs/superpowers/plans/2026-07-17-position-order.md` — implement that plan first.** Tasks 4-5 below edit `resources/js/app.js` and `resources/views/admin/sessions/show.blade.php` assuming that plan's `sortMode`, `sortByPosition`, `flatSorted`, and `positionOrder` additions are already in place; the code shown here is the state *after* that plan.
- PHP 8.4, `laravel/framework` v13 — use constructor property promotion and explicit return types on any new/changed method.
- Always use curly braces for control structures, even single-line bodies.
- Run `vendor/bin/pint --dirty --format agent` after any PHP change, before the task's final commit.
- Tests use Pest (`php artisan make:test --pest {name}` for new files); run via `php artisan test --compact --filter=testName`. Do not delete existing tests without approval — this plan only removes tests whose underlying feature (the `category` column/enum) is itself being removed in the same task.
- No JS test runner exists in this project — `app.js` changes are verified indirectly through the server-rendered JSON payload in Pest feature tests, plus a final manual browser check.
- All user-facing copy is French, matching the existing admin UI's tone.
- Full design reference: `docs/superpowers/specs/2026-07-17-principal-organizations-design.md`.

---

### Task 1: `is_principal` column, backfill, `Title` model

**Files:**
- Create: `database/migrations/2026_07_17_100000_add_is_principal_to_titles_table.php`
- Create: `database/migrations/2026_07_17_100001_backfill_is_principal_values.php`
- Modify: `app/Models/Title.php`
- Test: `tests/Feature/Models/TitleTest.php`
- Test: `tests/Feature/Migrations/SeedTitlesAndPositionsTest.php`

**Interfaces:**
- Produces: `titles.is_principal` (boolean, default `false`); `titles.category` becomes nullable (still present, still enum-cast — removed in Task 6). `Title::$fillable` includes `is_principal`. `Title::scopePrincipal(Builder $query): void`. `Title::OTHER_ORGANIZATIONS_LABEL` (`'Autres organisations'`) and `Title::MAX_PRINCIPAL` (`3`) constants — later tasks (2-6) use both.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/Models/TitleTest.php`:

```php
it('defaults is_principal to false', function () {
    $title = Title::factory()->create();

    expect($title->is_principal)->toBeFalse();
});

it('scopes to principal titles only', function () {
    $principal = Title::factory()->create(['is_principal' => true]);
    $other = Title::factory()->create(['is_principal' => false]);

    $ids = Title::principal()->pluck('id');

    expect($ids)->toContain($principal->id)
        ->and($ids)->not->toContain($other->id);
});
```

Add to `tests/Feature/Migrations/SeedTitlesAndPositionsTest.php`:

```php
it('flags Rotary and Rotaract as principal organisations', function () {
    expect(Title::whereIn('name', ['Rotary', 'Rotaract'])->pluck('is_principal')->all())->toBe([true, true])
        ->and(Title::where('name', 'JCI')->sole()->is_principal)->toBeFalse();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter="is_principal|principal titles|principal organisations"`
Expected: FAIL — `is_principal` column/scope doesn't exist yet.

- [ ] **Step 3: Create the schema migration**

`database/migrations/2026_07_17_100000_add_is_principal_to_titles_table.php`:

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
            $table->boolean('is_principal')->default(false)->after('name');
        });

        Schema::table('titles', function (Blueprint $table) {
            $table->string('category')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('titles', function (Blueprint $table) {
            $table->string('category')->nullable(false)->change();
        });

        Schema::table('titles', function (Blueprint $table) {
            $table->dropColumn('is_principal');
        });
    }
};
```

- [ ] **Step 4: Create the backfill migration**

`database/migrations/2026_07_17_100001_backfill_is_principal_values.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('titles')->whereIn('name', ['Rotary', 'Rotaract'])->update(['is_principal' => true]);
    }

    public function down(): void
    {
        DB::table('titles')->whereIn('name', ['Rotary', 'Rotaract'])->update(['is_principal' => false]);
    }
};
```

- [ ] **Step 5: Update the `Title` model**

Replace the full contents of `app/Models/Title.php` with:

```php
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

    public const GUEST_NAME = 'Invité';

    public const OTHER_ORGANIZATIONS_LABEL = 'Autres organisations';

    public const MAX_PRINCIPAL = 3;

    protected $fillable = ['name', 'category', 'is_principal', 'is_active', 'order'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => AttendanceCategory::class,
            'is_active' => 'boolean',
            'is_principal' => 'boolean',
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
        // Grouped in a nested where() — an ungrouped top-level orWhere()
        // here would leak across any other where() clause a caller adds
        // to the same query, due to SQL operator precedence.
        $query->where(function (Builder $q) use ($id) {
            $q->where('is_active', true)->when(
                $id !== null,
                fn (Builder $q2) => $q2->orWhere('id', $id),
            );
        });
    }

    public function scopePrincipal(Builder $query): void
    {
        $query->where('is_principal', true);
    }
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --compact --filter="is_principal|principal titles|principal organisations"`
Expected: PASS

- [ ] **Step 7: Run the full suite to confirm no regressions**

Run: `php artisan test --compact`
Expected: PASS — `category` is still present and required by nothing new yet, so every existing test keeps working.

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_07_17_100000_add_is_principal_to_titles_table.php database/migrations/2026_07_17_100001_backfill_is_principal_values.php app/Models/Title.php tests/Feature/Models/TitleTest.php tests/Feature/Migrations/SeedTitlesAndPositionsTest.php
git commit -m "feat: add is_principal flag to organisations"
```

---

### Task 2: `Attendance::groupLabel` accessor

**Files:**
- Modify: `app/Models/Attendance.php`
- Test: `tests/Feature/Models/AttendanceTest.php`

**Interfaces:**
- Consumes: `Title::OTHER_ORGANIZATIONS_LABEL` (Task 1).
- Produces: `Attendance::$groupLabel` (string, via accessor) — read as `$attendance->groupLabel`. Tasks 3-5 render/filter on this instead of `$attendance->category`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/Models/AttendanceTest.php`:

```php
it('derives its group label from a principal titles own name', function () {
    $title = Title::factory()->create(['is_principal' => true]);
    $attendance = Attendance::factory()->create(['title_id' => $title->id]);

    expect($attendance->groupLabel)->toBe($title->name);
});

it('derives its group label as Autres organisations for a non-principal title', function () {
    $title = Title::factory()->create(['is_principal' => false]);
    $attendance = Attendance::factory()->create(['title_id' => $title->id]);

    expect($attendance->groupLabel)->toBe(\App\Models\Title::OTHER_ORGANIZATIONS_LABEL);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter="derives its group label"`
Expected: FAIL — `groupLabel` is not a recognized attribute.

- [ ] **Step 3: Add the accessor**

In `app/Models/Attendance.php`, add this method after `category()` (leave `category()` in place — it's still used until Task 6):

```php
    protected function groupLabel(): Attribute
    {
        return Attribute::get(fn (): string => $this->title->is_principal ? $this->title->name : Title::OTHER_ORGANIZATIONS_LABEL);
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact --filter="derives its group label"`
Expected: PASS

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/Attendance.php tests/Feature/Models/AttendanceTest.php
git commit -m "feat: derive an attendances group label from its organisation"
```

---

### Task 3: Admin UI & validation — `is_principal` checkbox with a 3-organisation cap

**Files:**
- Modify: `app/Http/Requests/StoreTitleRequest.php`
- Modify: `app/Http/Requests/UpdateTitleRequest.php`
- Modify: `app/Http/Controllers/Admin/TitleController.php`
- Modify: `resources/views/admin/titles/create.blade.php`
- Modify: `resources/views/admin/titles/edit.blade.php`
- Modify: `resources/views/admin/titles/index.blade.php`
- Modify: `tests/Feature/Admin/TitleManagementTest.php`

**Interfaces:**
- Consumes: `Title::principal()`, `Title::MAX_PRINCIPAL` (Task 1).
- Produces: `admin.titles.store`/`admin.titles.update` now accept `is_principal` (checkbox, `1`/absent) instead of `category` (select); validation fails with a French message when a 4th organisation would be flagged.

- [ ] **Step 1: Rewrite `TitleManagementTest.php`**

Replace the full contents of `tests/Feature/Admin/TitleManagementTest.php` with:

```php
<?php

use App\Models\Member;
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
    $this->patch(route('admin.titles.toggle-active', $title))->assertRedirect(route('admin.login'));
    $this->delete(route('admin.titles.destroy', $title))->assertRedirect(route('admin.login'));
});

it('lists titles to an authenticated admin', function () {
    Title::factory()->create(['name' => 'Zonta']);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.titles.index'))
        ->assertOk()
        ->assertSee('Zonta');
});

it('creates a title and links the selected positions', function () {
    $president = Position::factory()->create(['name' => 'Représentant']);
    $secretary = Position::factory()->create(['name' => 'Rapporteur']);

    $this->actingAs(User::factory()->create())
        ->post(route('admin.titles.store'), [
            'name' => 'Kiwanis',
            'position_ids' => [$president->id, $secretary->id],
        ])->assertRedirect(route('admin.titles.index'));

    $title = Title::where('name', 'Kiwanis')->sole();
    expect($title->positions()->pluck('id')->sort()->values()->all())
        ->toBe([$president->id, $secretary->id]);
});

it('creates a title flagged as principal', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('admin.titles.store'), ['name' => 'Kiwanis', 'is_principal' => '1'])
        ->assertRedirect(route('admin.titles.index'));

    expect(Title::where('name', 'Kiwanis')->sole()->is_principal)->toBeTrue();
});

it('creates a title without flagging it as principal by default', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('admin.titles.store'), ['name' => 'Kiwanis'])
        ->assertRedirect(route('admin.titles.index'));

    expect(Title::where('name', 'Kiwanis')->sole()->is_principal)->toBeFalse();
});

it('blocks flagging a 4th title as principal', function () {
    Title::factory()->count(3)->create(['is_principal' => true]);

    $response = $this->actingAs(User::factory()->create())
        ->post(route('admin.titles.store'), ['name' => 'Kiwanis', 'is_principal' => '1']);

    $response->assertSessionHasErrors(['is_principal']);
    expect(Title::where('name', 'Kiwanis')->exists())->toBeFalse();
});

it('does not count a title against its own cap when updating it without changing the flag', function () {
    Title::factory()->count(2)->create(['is_principal' => true]);
    $title = Title::factory()->create(['is_principal' => true]);

    $this->actingAs(User::factory()->create())
        ->put(route('admin.titles.update', $title), ['name' => $title->name, 'is_principal' => '1'])
        ->assertRedirect(route('admin.titles.index'));

    expect($title->fresh()->is_principal)->toBeTrue();
});

it('unflags a principal title on update when the checkbox is unchecked', function () {
    $title = Title::factory()->create(['is_principal' => true]);

    $this->actingAs(User::factory()->create())
        ->put(route('admin.titles.update', $title), ['name' => $title->name])
        ->assertRedirect(route('admin.titles.index'));

    expect($title->fresh()->is_principal)->toBeFalse();
});

it('rejects a duplicate title name', function () {
    Title::factory()->create(['name' => 'Zonta']);

    $this->actingAs(User::factory()->create())
        ->post(route('admin.titles.store'), ['name' => 'Zonta'])
        ->assertSessionHasErrors(['name']);
});

it('updates a title and replaces its linked positions', function () {
    $title = Title::factory()->create();
    $oldPosition = Position::factory()->create();
    $newPosition = Position::factory()->create();
    $title->positions()->attach($oldPosition);

    $this->actingAs(User::factory()->create())
        ->put(route('admin.titles.update', $title), [
            'name' => $title->name,
            'position_ids' => [$newPosition->id],
        ])->assertRedirect(route('admin.titles.index'));

    expect($title->positions()->pluck('id')->all())->toBe([$newPosition->id]);
});

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
    $title = Title::factory()->create();
    $inactivePosition = Position::factory()->create(['is_active' => false]);
    $title->positions()->attach($inactivePosition);

    $this->actingAs(User::factory()->create())
        ->put(route('admin.titles.update', $title), [
            'name' => $title->name,
            'position_ids' => [],
        ])->assertRedirect(route('admin.titles.index'));

    expect($title->positions()->count())->toBe(0);
});

it('excludes the Invité title from the admin listing', function () {
    $invite = Title::where('name', Title::GUEST_NAME)->sole();

    $this->actingAs(User::factory()->create())
        ->get(route('admin.titles.index'))
        ->assertOk()
        ->assertDontSee(route('admin.titles.edit', $invite));
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
        ->put(route('admin.titles.update', $invite), ['name' => 'Invité'])
        ->assertNotFound();
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

- [ ] **Step 2: Run the tests to verify the new ones fail**

Run: `php artisan test --compact --filter="TitleManagementTest"`
Expected: FAIL on the new `is_principal`-related tests (route/behavior doesn't exist yet); pre-existing tests still pass since nothing else has changed yet.

- [ ] **Step 3: Update `StoreTitleRequest`**

Replace the full contents of `app/Http/Requests/StoreTitleRequest.php` with:

```php
<?php

namespace App\Http\Requests;

use App\Models\Title;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

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
            'is_principal' => ['boolean', function (string $attribute, mixed $value, Closure $fail): void {
                if (! $value) {
                    return;
                }

                if (Title::principal()->count() >= Title::MAX_PRINCIPAL) {
                    $fail('Maximum '.Title::MAX_PRINCIPAL.' organisations principales — déflaggez-en une avant d\'en ajouter une nouvelle.');
                }
            }],
            'position_ids' => ['array'],
            'position_ids.*' => ['integer', 'exists:positions,id'],
        ];
    }
}
```

- [ ] **Step 4: Update `UpdateTitleRequest`**

Replace the full contents of `app/Http/Requests/UpdateTitleRequest.php` with:

```php
<?php

namespace App\Http\Requests;

use App\Models\Title;
use Closure;
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
            'is_principal' => ['boolean', function (string $attribute, mixed $value, Closure $fail): void {
                if (! $value) {
                    return;
                }

                $alreadyFlagged = Title::principal()->whereKeyNot($this->route('title'))->count();

                if ($alreadyFlagged >= Title::MAX_PRINCIPAL) {
                    $fail('Maximum '.Title::MAX_PRINCIPAL.' organisations principales — déflaggez-en une avant d\'en ajouter une nouvelle.');
                }
            }],
            'position_ids' => ['array'],
            'position_ids.*' => ['integer', 'exists:positions,id'],
        ];
    }
}
```

- [ ] **Step 5: Update `TitleController::store()` and `update()`**

In `app/Http/Controllers/Admin/TitleController.php`, replace the `store` method:

```php
    public function store(StoreTitleRequest $request): RedirectResponse
    {
        $maxOrder = Title::where('name', '!=', Title::GUEST_NAME)->max('order');
        $nextOrder = $maxOrder === null ? 0 : $maxOrder + 1;

        $title = Title::create([
            ...$request->safe()->only(['name']),
            'is_principal' => $request->boolean('is_principal'),
            'order' => $nextOrder,
        ]);
        $title->positions()->sync($request->input('position_ids', []));

        return redirect()->route('admin.titles.index');
    }
```

and the `update` method:

```php
    public function update(UpdateTitleRequest $request, Title $title): RedirectResponse
    {
        abort_if($title->name === Title::GUEST_NAME, 404);

        $title->update([
            ...$request->safe()->only(['name']),
            'is_principal' => $request->boolean('is_principal'),
        ]);
        $title->positions()->sync($request->input('position_ids', []));

        return redirect()->route('admin.titles.index');
    }
```

- [ ] **Step 6: Update `admin/titles/create.blade.php`**

Replace the "Catégorie" field block:

```blade
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
```

with:

```blade
            <div class="flex flex-col gap-1.5">
                <label class="flex items-center gap-2 text-sm font-semibold">
                    <input type="checkbox" name="is_principal" value="1" @checked(old('is_principal'))>
                    Organisation principale (comptée sur le tableau de bord)
                </label>
            </div>
```

- [ ] **Step 7: Update `admin/titles/edit.blade.php`**

Replace the "Catégorie" field block:

```blade
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
```

with:

```blade
            <div class="flex flex-col gap-1.5">
                <label class="flex items-center gap-2 text-sm font-semibold">
                    <input type="checkbox" name="is_principal" value="1" @checked(old('is_principal', $title->is_principal))>
                    Organisation principale (comptée sur le tableau de bord)
                </label>
            </div>
```

- [ ] **Step 8: Update `admin/titles/index.blade.php`**

Replace the `<th>` header:

```blade
                        <th class="py-2 pr-4 font-semibold">Catégorie</th>
```

with:

```blade
                        <th class="py-2 pr-4 font-semibold">Principale</th>
```

Replace the corresponding `<td>`:

```blade
                            <td class="py-3 pr-4">{{ $title->category->label() }}</td>
```

with:

```blade
                            <td class="py-3 pr-4">
                                @if ($title->is_principal)
                                    <span class="rounded-full bg-success-bg px-2 py-1 text-xs font-semibold text-success">Oui</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
```

- [ ] **Step 9: Run the tests to verify they pass**

Run: `php artisan test --compact --filter="TitleManagementTest"`
Expected: PASS

- [ ] **Step 10: Run the full suite to confirm no regressions**

Run: `php artisan test --compact`
Expected: PASS — nothing else reads `category` from the create/edit/index forms anymore, but the column, enum, and every other consumer (dashboard, PDF) are untouched until Tasks 4-6.

- [ ] **Step 11: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Requests/StoreTitleRequest.php app/Http/Requests/UpdateTitleRequest.php app/Http/Controllers/Admin/TitleController.php resources/views/admin/titles/create.blade.php resources/views/admin/titles/edit.blade.php resources/views/admin/titles/index.blade.php tests/Feature/Admin/TitleManagementTest.php
git commit -m "feat: let admins flag organisations as principal, capped at 3"
```

---

### Task 4: Dashboard grouping — stat tiles, filters, roster groups

**Files:**
- Modify: `app/Http/Controllers/Admin/MeetingSessionController.php`
- Modify: `resources/views/admin/sessions/show.blade.php`
- Modify: `resources/js/app.js`
- Test: `tests/Feature/Admin/AttendanceDashboardTest.php`

**Interfaces:**
- Consumes: `Attendance::$groupLabel` (Task 2), `Title::principal()`/`Title::OTHER_ORGANIZATIONS_LABEL` (Task 1). Assumes `resources/js/app.js` and `resources/views/admin/sessions/show.blade.php` already include the position-order-plan's `sortMode`/`sortByPosition`/`flatSorted`/`positionOrder` additions.
- Produces: `MeetingSessionController::principalTitles(): Collection<int, Title>` (private) and `buildGroups(Collection $principalTitles): array<int, array{label: string, colors: array{bg: string, accent: string}}>` (private) — Task 5 reuses `principalTitles()`. The Alpine component `attendanceDashboard(records, groupOrder)` gains a second constructor argument; `activeCategory` is renamed `activeGroup`; each record's `category`/`categoryLabel` fields become a single `groupLabel` field.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/Admin/AttendanceDashboardTest.php`:

```php
it('shows one stat tile per principal organisation plus an Autres organisations tile', function () {
    $meetingSession = MeetingSession::factory()->create();
    $rotary = Title::where('name', 'Rotary')->sole();
    $jci = Title::where('name', 'JCI')->sole();

    Attendance::factory()->for($meetingSession)->create(['title_id' => $rotary->id]);
    Attendance::factory()->for($meetingSession)->create(['title_id' => $jci->id]);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.show', $meetingSession))
        ->assertOk()
        ->assertSee('Rotary')
        ->assertSee('Rotaract')
        ->assertSee(Title::OTHER_ORGANIZATIONS_LABEL);
});

it('exposes group quick-filter buttons instead of the old fixed category buttons', function () {
    $meetingSession = MeetingSession::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.show', $meetingSession))
        ->assertOk()
        ->assertSee('activeGroup = ', false)
        ->assertDontSee('Bureau / Officiels')
        ->assertDontSee('Rotaractiens');
});

it('includes each attendances group label in the roster payload', function () {
    $meetingSession = MeetingSession::factory()->create();
    $rotary = Title::where('name', 'Rotary')->sole();
    $jci = Title::where('name', 'JCI')->sole();

    Attendance::factory()->for($meetingSession)->create(['title_id' => $rotary->id, 'name' => 'Jean Dupont']);
    Attendance::factory()->for($meetingSession)->create(['title_id' => $jci->id, 'name' => 'Awa Bello']);

    $response = $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.show', $meetingSession));

    $response->assertOk();

    preg_match("/attendanceDashboard\(JSON\.parse\('(.+?)'\), JSON\.parse\('(.+?)'\)\)/s", $response->getContent(), $matches);
    $records = collect(json_decode(str_replace(chr(92).'u0022', '"', $matches[1]), true))->keyBy('name');

    expect($records['Jean Dupont']['groupLabel'])->toBe('Rotary')
        ->and($records['Awa Bello']['groupLabel'])->toBe(Title::OTHER_ORGANIZATIONS_LABEL);
});

it('orders roster groups with principal organisations first and Autres organisations last', function () {
    $meetingSession = MeetingSession::factory()->create();
    $expectedPrincipalOrder = Title::principal()->orderBy('order')->orderBy('name')->pluck('name')->all();

    $response = $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.show', $meetingSession));

    $response->assertOk();

    preg_match("/attendanceDashboard\(JSON\.parse\('(.+?)'\), JSON\.parse\('(.+?)'\)\)/s", $response->getContent(), $matches);
    $groupLabels = json_decode(str_replace(chr(92).'u0022', '"', $matches[2]), true);

    expect($groupLabels)->toBe([...$expectedPrincipalOrder, Title::OTHER_ORGANIZATIONS_LABEL]);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter="principal organisation plus|quick-filter buttons instead|group label in the roster payload|principal organisations first"`
Expected: FAIL — the page still renders `AttendanceCategory` tiles/buttons and the payload has no `groupLabel`/second argument.

- [ ] **Step 3: Add `principalTitles()`/`buildGroups()` and update `show()` in `MeetingSessionController`**

In `app/Http/Controllers/Admin/MeetingSessionController.php`, add `use App\Models\Title;` and `use Illuminate\Database\Eloquent\Collection;` to the imports, then replace the `show` method:

```php
    public function show(MeetingSession $meetingSession): View
    {
        return view('admin.sessions.show', [
            'meetingSession' => $meetingSession,
            'attendances' => $meetingSession->attendances()->with(['title', 'position'])->get(),
            'upcomingSessions' => MeetingSession::where('id', '!=', $meetingSession->id)
                ->where('date', '>=', now()->toDateString())
                ->orderBy('date')
                ->get(),
            'groups' => $this->buildGroups($this->principalTitles()),
        ]);
    }
```

and add these two private methods at the bottom of the class (before the closing `}`):

```php
    /**
     * @return Collection<int, Title>
     */
    private function principalTitles(): Collection
    {
        return Title::principal()->orderBy('order')->orderBy('name')->get();
    }

    /**
     * @param  Collection<int, Title>  $principalTitles
     * @return array<int, array{label: string, colors: array{bg: string, accent: string}}>
     */
    private function buildGroups(Collection $principalTitles): array
    {
        $palette = [
            ['bg' => '#EAF1FB', 'accent' => '#17458F'],
            ['bg' => '#E7F5F1', 'accent' => '#0E7C66'],
            ['bg' => '#FDF3E2', 'accent' => '#C77700'],
        ];

        $groups = $principalTitles->values()->map(fn (Title $title, int $index): array => [
            'label' => $title->name,
            'colors' => $palette[$index],
        ])->all();

        $groups[] = ['label' => Title::OTHER_ORGANIZATIONS_LABEL, 'colors' => ['bg' => '#F1EFEA', 'accent' => '#6B6558']];

        return $groups;
    }
```

- [ ] **Step 4: Update the JSON payload and dynamic sections in `sessions/show.blade.php`**

Replace the `x-data` payload (the block starting `x-data="attendanceDashboard(...)"`):

```blade
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

with:

```blade
        x-data="attendanceDashboard(@js($attendances->map(fn ($attendance) => [
            'id' => $attendance->id,
            'name' => $attendance->name,
            'title' => $attendance->title->name,
            'position' => $attendance->position?->name,
            'positionOrder' => $attendance->position?->order,
            'club' => $attendance->club,
            'phone' => $attendance->phone,
            'groupLabel' => $attendance->groupLabel,
            'present' => $attendance->present,
            'isLate' => $attendance->is_late,
            'hasMisc' => $attendance->has_misc,
        ])), @js(collect($groups)->pluck('label')))"
```

Replace the stat tiles loop:

```blade
            @foreach (\App\Enums\AttendanceCategory::cases() as $category)
                @php $categoryCount = $attendances->filter(fn ($attendance) => $attendance->category === $category)->count(); @endphp
                <div class="rounded-lg p-3" style="background-color: {{ $category->colors()['bg'] }}; color: {{ $category->colors()['accent'] }}">
                    <p class="text-lg font-extrabold">{{ $categoryCount }}</p>
                    <p class="text-xs">{{ $category->label() }}</p>
                </div>
            @endforeach
```

with:

```blade
            @foreach ($groups as $group)
                @php $groupCount = $attendances->filter(fn ($attendance) => $attendance->groupLabel === $group['label'])->count(); @endphp
                <div class="rounded-lg p-3" style="background-color: {{ $group['colors']['bg'] }}; color: {{ $group['colors']['accent'] }}">
                    <p class="text-lg font-extrabold">{{ $groupCount }}</p>
                    <p class="text-xs">{{ $group['label'] }}</p>
                </div>
            @endforeach
```

Replace the quick-filter buttons:

```blade
            <button type="button" @click="activeCategory = 'all'"
                :class="activeCategory === 'all' ? 'bg-navy text-white' : 'border border-border text-navy'"
                class="cursor-pointer rounded-full px-3 py-2 text-xs font-semibold md:py-1.5">Tous</button>
            @foreach (\App\Enums\AttendanceCategory::cases() as $category)
                <button type="button" @click="activeCategory = '{{ $category->value }}'"
                    :class="activeCategory === '{{ $category->value }}' ? 'bg-navy text-white' : 'border border-border text-navy'"
                    class="cursor-pointer rounded-full px-3 py-2 text-xs font-semibold md:py-1.5">{{ $category->label() }}</button>
            @endforeach
```

with:

```blade
            <button type="button" @click="activeGroup = 'all'"
                :class="activeGroup === 'all' ? 'bg-navy text-white' : 'border border-border text-navy'"
                class="cursor-pointer rounded-full px-3 py-2 text-xs font-semibold md:py-1.5">Tous</button>
            @foreach ($groups as $group)
                <button type="button" @click="activeGroup = '{{ $group['label'] }}'"
                    :class="activeGroup === '{{ $group['label'] }}' ? 'bg-navy text-white' : 'border border-border text-navy'"
                    class="cursor-pointer rounded-full px-3 py-2 text-xs font-semibold md:py-1.5">{{ $group['label'] }}</button>
            @endforeach
```

Replace the roster group header line (inside the grouped `<template x-for="group in groups">` block):

```blade
                        <p class="mb-2 text-xs font-semibold uppercase text-muted-strong" x-text="group.records[0].categoryLabel + ' (' + group.records.length + ')'"></p>
```

with:

```blade
                        <p class="mb-2 text-xs font-semibold uppercase text-muted-strong" x-text="group.category + ' (' + group.records.length + ')'"></p>
```

- [ ] **Step 5: Update `attendanceDashboard` in `resources/js/app.js`**

Replace the whole `Alpine.data('attendanceDashboard', ...)` block with:

```js
Alpine.data('attendanceDashboard', (records, groupOrder) => ({
    records,
    groupOrder,
    search: '',
    activeGroup: 'all',
    activeTitle: 'all',
    activeMiscFilter: 'all',
    sortMode: 'grouped',
    get titleOptions() {
        return [...new Set(this.records.map((record) => record.title))].sort();
    },
    get filtered() {
        const search = this.search.toLowerCase();

        return this.records.filter((record) => {
            const matchesGroup = this.activeGroup === 'all' || record.groupLabel === this.activeGroup;
            const matchesTitle = this.activeTitle === 'all' || record.title === this.activeTitle;
            const matchesSearch = record.name.toLowerCase().includes(search);
            const matchesMisc = this.activeMiscFilter === 'all' || 
                (this.activeMiscFilter === 'yes' && record.hasMisc) ||
                (this.activeMiscFilter === 'no' && !record.hasMisc);

            return matchesGroup && matchesTitle && matchesSearch && matchesMisc;
        });
    },
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
    sortByPosition(records) {
        return [...records].sort((a, b) => {
            const aOrder = a.positionOrder ?? Infinity;
            const bOrder = b.positionOrder ?? Infinity;

            if (aOrder !== bOrder) return aOrder - bOrder;

            return a.name.localeCompare(b.name);
        });
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
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --compact --filter="principal organisation plus|quick-filter buttons instead|group label in the roster payload|principal organisations first"`
Expected: PASS

- [ ] **Step 7: Run the full suite to confirm no regressions**

Run: `php artisan test --compact`
Expected: PASS — all pre-existing `AttendanceDashboardTest` tests (roster rendering, present toggling, titre filter, poste display) don't reference `category`/`AttendanceCategory` and keep working against the new payload shape.

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Admin/MeetingSessionController.php resources/views/admin/sessions/show.blade.php resources/js/app.js tests/Feature/Admin/AttendanceDashboardTest.php
git commit -m "feat: group the attendance dashboard by principal organisation"
```

---

### Task 5: PDF export grouping

**Files:**
- Modify: `app/Http/Controllers/Admin/MeetingSessionController.php`
- Modify: `resources/views/admin/sessions/pdf.blade.php`
- Test: `tests/Feature/Admin/AttendancePdfExportTest.php`

**Interfaces:**
- Consumes: `MeetingSessionController::principalTitles()` (private, Task 4), `Attendance::$groupLabel` (Task 2).
- Produces: the `admin.sessions.pdf` view now expects a `$groupLabels` array (ordered list of strings) instead of looping `AttendanceCategory::cases()`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Admin/AttendancePdfExportTest.php`:

```php
it('groups the PDF export by principal organisation and Autres organisations', function () {
    $meetingSession = MeetingSession::factory()->create();
    $rotary = Title::where('name', 'Rotary')->sole();
    $jci = Title::where('name', 'JCI')->sole();

    Attendance::factory()->for($meetingSession)->create(['title_id' => $rotary->id, 'name' => 'Jean Dupont']);
    Attendance::factory()->for($meetingSession)->create(['title_id' => $jci->id, 'name' => 'Awa Bello']);

    $html = view('admin.sessions.pdf', [
        'meetingSession' => $meetingSession,
        'attendances' => $meetingSession->attendances()->with(['title', 'position'])->get(),
        'groupLabels' => ['Rotary', 'Rotaract', Title::OTHER_ORGANIZATIONS_LABEL],
    ])->render();

    expect($html)->toContain('<h2>Rotary (1)</h2>')
        ->and($html)->toContain('<h2>'.Title::OTHER_ORGANIZATIONS_LABEL.' (1)</h2>')
        ->and($html)->not->toContain('<h2>Rotaract (')
        ->and(strpos($html, 'Jean Dupont'))->toBeLessThan(strpos($html, 'Awa Bello'));
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter="groups the PDF export"`
Expected: FAIL — `$groupLabels` is undefined in the view / grouping still uses `AttendanceCategory`.

- [ ] **Step 3: Update `MeetingSessionController::exportPdf()`**

Replace the `exportPdf` method in `app/Http/Controllers/Admin/MeetingSessionController.php`:

```php
    public function exportPdf(MeetingSession $meetingSession): Response
    {
        $pdf = Pdf::loadView('admin.sessions.pdf', [
            'meetingSession' => $meetingSession,
            'attendances' => $meetingSession->attendances()->with(['title', 'position'])->get(),
            'groupLabels' => [...$this->principalTitles()->pluck('name')->all(), Title::OTHER_ORGANIZATIONS_LABEL],
        ]);

        $filename = $meetingSession->date->translatedFormat('Y-m-d').' - '.$meetingSession->title.'.pdf';
        $filename = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '', $filename);

        return $pdf->download($filename);
    }
```

- [ ] **Step 4: Update `admin/sessions/pdf.blade.php`**

Replace the loop:

```blade
    @foreach (\App\Enums\AttendanceCategory::cases() as $category)
        @php $categoryAttendances = $attendances->filter(fn ($attendance) => $attendance->category === $category); @endphp
        @if ($categoryAttendances->isNotEmpty())
            <h2>{{ $category->label() }} ({{ $categoryAttendances->count() }})</h2>
            <table>
                <thead>
                    <tr>
                        <th>Nom</th>
                        <th>Organisation</th>
                        <th>Club</th>
                        <th>Téléphone</th>
                        <th>Présent</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($categoryAttendances as $attendance)
                        <tr>
                            <td>{{ $attendance->name }}</td>
                            <td>{{ $attendance->title->name }}{{ $attendance->position ? ' — '.$attendance->position->name : '' }}</td>
                            <td>{{ $attendance->club }}</td>
                            <td>{{ $attendance->phone }}</td>
                            <td>{{ $attendance->present ? 'Oui' : 'Non' }}{{ $attendance->is_late ? ' (retard)' : '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endforeach
```

with:

```blade
    @foreach ($groupLabels as $groupLabel)
        @php $groupAttendances = $attendances->filter(fn ($attendance) => $attendance->groupLabel === $groupLabel); @endphp
        @if ($groupAttendances->isNotEmpty())
            <h2>{{ $groupLabel }} ({{ $groupAttendances->count() }})</h2>
            <table>
                <thead>
                    <tr>
                        <th>Nom</th>
                        <th>Organisation</th>
                        <th>Club</th>
                        <th>Téléphone</th>
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
                            <td>{{ $attendance->present ? 'Oui' : 'Non' }}{{ $attendance->is_late ? ' (retard)' : '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endforeach
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --compact --filter="groups the PDF export"`
Expected: PASS

- [ ] **Step 6: Run the full suite to confirm no regressions**

Run: `php artisan test --compact`
Expected: PASS

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Admin/MeetingSessionController.php resources/views/admin/sessions/pdf.blade.php tests/Feature/Admin/AttendancePdfExportTest.php
git commit -m "feat: group the PDF export by principal organisation"
```

---

### Task 6: Drop `category` and delete `AttendanceCategory`

**Files:**
- Create: `database/migrations/2026_07_17_100002_drop_category_from_titles_table.php`
- Delete: `app/Enums/AttendanceCategory.php`
- Modify: `app/Models/Title.php`
- Modify: `app/Models/Attendance.php`
- Modify: `database/factories/TitleFactory.php`
- Modify: `tests/Feature/Migrations/SeedTitlesAndPositionsTest.php`
- Modify: `tests/Feature/Models/TitleTest.php`
- Modify: `tests/Feature/Models/AttendanceTest.php`

**Interfaces:**
- Consumes: nothing new — this is the "contract" half of Task 1's expand/contract migration; it only removes code that Tasks 2-5 already stopped depending on.
- Produces: `titles.category` no longer exists; `App\Enums\AttendanceCategory` no longer exists.

- [ ] **Step 1: Confirm nothing outside the files listed above still references `category`/`AttendanceCategory`**

Run: `grep -rn "AttendanceCategory\|->category" app/ resources/ database/factories/ routes/`
Expected: only hits inside `app/Models/Title.php` (the cast), `app/Models/Attendance.php` (the `category()` accessor), and `database/factories/TitleFactory.php` — the three files this task edits next. If anything else shows up, stop and update it first (it means an earlier task missed a call site).

- [ ] **Step 2: Create the drop-column migration**

`database/migrations/2026_07_17_100002_drop_category_from_titles_table.php`:

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
            $table->dropColumn('category');
        });
    }

    public function down(): void
    {
        Schema::table('titles', function (Blueprint $table) {
            $table->string('category')->nullable()->after('name');
        });
    }
};
```

- [ ] **Step 3: Delete the enum**

```bash
rm app/Enums/AttendanceCategory.php
```

- [ ] **Step 4: Remove `category` from the `Title` model**

Replace the full contents of `app/Models/Title.php` with:

```php
<?php

namespace App\Models;

use Database\Factories\TitleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Title extends Model
{
    /** @use HasFactory<TitleFactory> */
    use HasFactory;

    public const GUEST_NAME = 'Invité';

    public const OTHER_ORGANIZATIONS_LABEL = 'Autres organisations';

    public const MAX_PRINCIPAL = 3;

    protected $fillable = ['name', 'is_principal', 'is_active', 'order'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_principal' => 'boolean',
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
        // Grouped in a nested where() — an ungrouped top-level orWhere()
        // here would leak across any other where() clause a caller adds
        // to the same query, due to SQL operator precedence.
        $query->where(function (Builder $q) use ($id) {
            $q->where('is_active', true)->when(
                $id !== null,
                fn (Builder $q2) => $q2->orWhere('id', $id),
            );
        });
    }

    public function scopePrincipal(Builder $query): void
    {
        $query->where('is_principal', true);
    }
}
```

- [ ] **Step 5: Remove the `category` accessor from `Attendance`**

In `app/Models/Attendance.php`, remove the `use App\Enums\AttendanceCategory;` import and delete the `category()` method:

```php
    protected function category(): Attribute
    {
        return Attribute::get(fn (): AttendanceCategory => $this->title->category);
    }
```

(leave `groupLabel()`, added in Task 2, in place.)

- [ ] **Step 6: Remove `category` from `TitleFactory`**

Replace the full contents of `database/factories/TitleFactory.php` with:

```php
<?php

namespace Database\Factories;

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
            'is_active' => true,
        ];
    }
}
```

- [ ] **Step 7: Remove the now-invalid `category` tests**

In `tests/Feature/Migrations/SeedTitlesAndPositionsTest.php`, delete the `it('seeds the starter titles with their categories', ...)` test (the `flags Rotary and Rotaract as principal organisations` test added in Task 1 already covers this seed data).

In `tests/Feature/Models/TitleTest.php`, delete the `it('casts category to the AttendanceCategory enum', ...)` test.

In `tests/Feature/Models/AttendanceTest.php`, delete the `it('derives its category from its title', ...)` test (the two `groupLabel` tests added in Task 2 already cover this behavior).

- [ ] **Step 8: Run the full suite**

Run: `php artisan test --compact`
Expected: PASS, no failures, no leftover references.

- [ ] **Step 9: Re-run the grep check**

Run: `grep -rn "AttendanceCategory" app/ resources/ tests/ database/ routes/`
Expected: no results.

- [ ] **Step 10: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "refactor: drop the category column and AttendanceCategory enum"
```

- [ ] **Step 11: Manual browser verification**

Run `npm run build` (or ask the user to run `npm run dev`/`composer run dev`), then in the admin:
- Create/edit an organisation, confirm the "Organisation principale" checkbox works and the cap error shows in French when trying to flag a 4th.
- Open a meeting session's roster: confirm the stat tiles show Rotary, Rotaract (or whichever organisations are flagged), and "Autres organisations", in that order, with correct counts.
- Toggle the quick-filter buttons and the "Trier par poste" toggle (from the position-order plan) together — confirm both still work.
- Export the session PDF and confirm the same grouping/order appears in the downloaded file.

---

## Self-Review Notes

- **Spec coverage:** §1 (data model) → Task 1 + Task 6 (contract half). §2 (admin UI & validation, 3-cap) → Task 3. §3 (attendance grouping) → Task 2. §4 (dashboard: tiles, filters, roster order) → Task 4. §5 (PDF export) → Task 5. §6 (factory) → Task 6. All spec sections have a corresponding task.
- **Placeholder scan:** no TBD/TODO; every step has complete, runnable code. The expand/contract migration (nullable `category` in Task 1, dropped in Task 6) is a standard incremental-schema pattern, not a leftover shim — Task 6 removes every trace of it.
- **Type consistency:** `Title::OTHER_ORGANIZATIONS_LABEL`/`Title::MAX_PRINCIPAL`/`Title::principal()` (defined Task 1) are used with identical names in Tasks 2-6. `Attendance::$groupLabel` (Task 2) is the field name used consistently in Tasks 4-5's Blade/JS payloads. `MeetingSessionController::principalTitles()`/`buildGroups()` (Task 4) are reused by name in Task 5. `activeGroup`/`groupOrder`/`sortByPosition`/`flatSorted` naming in `app.js` (Task 4) matches between the JS file and the Blade payload that constructs its arguments.
