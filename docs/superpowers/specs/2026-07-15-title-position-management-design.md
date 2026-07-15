# Titre + Poste management — spec

Date: 2026-07-15

## Context

`App\Enums\AttendanceTitle` is a hardcoded, string-backed PHP enum with 17
cases (`PDG, DG, DGE, DGN, AdG, PAdG, Past Président, Président, Président
Elu, Président Nommé, Secrétaire, Trésorier, Protocole, Président de
Commission, Rotarien, Rotaractien, Invité`). It already conflates two
different concepts: some cases are **roles** held by an official (Président,
Secrétaire, Trésorier, Protocole, PDG, DG...), others are **membership
types** (Rotarien, Rotaractien, Invité). `AttendanceTitle::category()` maps
each case to one of four `AttendanceCategory` values (Officials, Members,
Rotaractors, Guests) used purely for dashboard grouping/coloring.

Both `Member` and `Attendance` store this as a single `title` string column
cast to the enum. The public check-in form
(`resources/views/components/attendance-form.blade.php`) renders one select
labelled "Titre / Qualité*" from `AttendanceTitle::cases()`; the admin member
edit form does the same. `StoreAttendanceRequest`/`UpdateMemberRequest`
validate it with `Rule::enum(AttendanceTitle::class)`.

There is no existing DB-backed lookup table with admin CRUD anywhere in this
app — every admin resource (`MemberController`, `UserController`,
`MeetingSessionController`) manages a "real" domain record, not a
configuration/reference value. This feature introduces that pattern for the
first time.

The app has no Livewire/Vue — Alpine.js is used only for simple
`x-show`/`x-data` toggles (e.g. the late-check-in mode toggle on
`attendance/show.blade.php`). There is no AJAX anywhere.

## Goal

Split the single "Titre / Qualité" field into two: **Titre** (organizational
affiliation — Rotary, Rotaract, JCI, Lions, Inner Wheel, RRD, Invité) and
**Poste** (role within that affiliation — Président, Secrétaire, Trésorier,
etc.), both managed by the admin from now on. A poste can be linked to
several titres (e.g. "Président" applies to Rotary, JCI, Lions...), so the
relationship is many-to-many, configured by the admin. The check-in form
shows the poste options for whichever titre was just selected, with no poste
field at all if the chosen titre has none (e.g. "Invité").

Per-attendee official/member distinction (the current `Officials` vs
`Members` category split, based on individual role) is intentionally
dropped: category becomes purely a property of the titre going forward. A
Rotary Président and a plain Rotary member both fall under whatever category
the "Rotary" titre is assigned (Members, by default in the seed data below).

## Design

### 1. Data model & migrations

Two new models/tables plus a pivot, replacing `AttendanceTitle` entirely:

```php
Schema::create('titles', function (Blueprint $table) {
    $table->id();
    $table->string('name')->unique();
    $table->string('category'); // AttendanceCategory value
    $table->timestamps();
});

Schema::create('positions', function (Blueprint $table) {
    $table->id();
    $table->string('name')->unique();
    $table->timestamps();
});

Schema::create('position_title', function (Blueprint $table) {
    $table->foreignId('title_id')->constrained()->cascadeOnDelete();
    $table->foreignId('position_id')->constrained()->cascadeOnDelete();
    $table->primary(['title_id', 'position_id']);
});
```

`App\Models\Title`:

```php
class Title extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'category'];

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

`App\Models\Position`:

```php
class Position extends Model
{
    use HasFactory;

    protected $fillable = ['name'];

    public function titles(): BelongsToMany
    {
        return $this->belongsToMany(Title::class);
    }
}
```

`members` and `attendances` each get `title_id` (required) and `position_id`
(nullable — a titre may have no linked positions), added as their own
migration per table, nullable at first for backfill:

```php
Schema::table('members', function (Blueprint $table) {
    $table->foreignId('title_id')->nullable()->after('id')
        ->constrained()->restrictOnDelete();
    $table->foreignId('position_id')->nullable()->after('title_id')
        ->constrained()->restrictOnDelete();
});
```

(same shape for `attendances`, positioned after `member_id`). `restrictOnDelete`
is used (not `nullOnDelete`, unlike `member_id`) because title/position are
core identifying data, not optional history-linking — an admin must reassign
records before deleting a titre/poste still in use. There are no `destroy`
routes in this design (see §2), so this is a DB-level safety net rather than
something the app needs to handle gracefully day-to-day.

**Seed migration** (data migration, following the existing
`backfill_members_from_attendances` convention of writing data migrations by
hand rather than via seeders):

Titles:

| name | category |
|---|---|
| Rotary | members |
| Rotaract | rotaractors |
| JCI | guests |
| Lions | guests |
| Inner Wheel | guests |
| RRD | guests |
| Invité | guests |

Positions: `PDG, DG, DGE, DGN, AdG, PAdG, Past Président, Président,
Président Elu, Président Nommé, Secrétaire, Trésorier, Protocole, Président
de Commission, Vice-Président, Membre` (all 15 district/club-hierarchy
values from the old enum, plus a generic "Membre" for plain members and a
"Vice-Président" not previously represented).

Pivot (title → positions):

- **Rotary** → all 16 positions.
- **Rotaract**, **JCI**, **Lions**, **Inner Wheel**, **RRD** → `Président,
  Vice-Président, Secrétaire, Trésorier, Membre`.
- **Invité** → none.

This is a starting point the admin can freely add to, rename, or reassign
afterward via the Titre/Poste admin screens (§2) — it does not need to be
exhaustive.

**Backfill migration** — every one of the 17 old `AttendanceTitle` values has
an exact, lossless mapping to a `(title, position)` pair, so no manual
reconciliation is needed for historical data:

| old `title` value | new titre | new poste |
|---|---|---|
| PDG, DG, DGE, DGN, AdG, PAdG, Past Président, Président, Président Elu, Président Nommé, Secrétaire, Trésorier, Protocole, Président de Commission | Rotary | (same name) |
| Rotarien | Rotary | Membre |
| Rotaractien | Rotaract | Membre |
| Invité | Invité | *(null)* |

Implemented with the query builder directly (matching the existing
`backfill_members_from_attendances.php` style): read distinct old `title`
values from `members`/`attendances`, resolve each to a `title_id`/
`position_id` pair via the table above, then `UPDATE ... SET title_id = ?,
position_id = ?` per matching group.

**Finalize migration**: once backfilled, set `title_id` `NOT NULL` on both
tables and drop the old `title` string column on both tables.

`Member`/`Attendance` model changes: `title` (string, cast to
`AttendanceTitle`) replaced by `title_id`/`position_id` in `$fillable`, with
`title(): BelongsTo` / `position(): BelongsTo` relations. `AttendanceTitle`
enum is deleted. `Attendance::category` becomes:

```php
protected function category(): Attribute
{
    return Attribute::get(fn (): AttendanceCategory => $this->title->category);
}
```

(property access, since `category` is now a plain cast column on `Title`,
not a computed enum method). `AttendanceCategory` itself is unchanged.

### 2. Admin management

Two new resources, following the exact shape of existing admin controllers
(thin controller + FormRequest + Blade views under
`resources/views/admin/<resource>/`, routes under the `admin.` prefix inside
the existing `auth`-protected route group):

`Admin\TitleController` — `index` (list with category badge and poste
count), `create`/`store`, `edit`/`update`. The create/edit form is where the
many-to-many linkage is managed: a checkbox list of every `Position`, saved
via `$title->positions()->sync($request->input('position_ids', []))`. No
`destroy` (matches `MemberController`, which also has none).

`Admin\PositionController` — plain name-only CRUD: `index`, `create`/
`store`, `edit`/`update`. Positions are created here, then attached to
titles from the Title edit screen.

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

`StoreTitleRequest`/`UpdateTitleRequest`: `name` (`required|string|max:255`,
unique ignoring self on update), `category` (`required`,
`Rule::enum(AttendanceCategory::class)`), `position_ids` (`array`),
`position_ids.*` (`integer|exists:positions,id`).

`StorePositionRequest`/`UpdatePositionRequest`: `name`
(`required|string|max:255`, unique ignoring self on update).

New sidebar entries "Titres" and "Postes" in
`resources/views/components/layouts/admin.blade.php`, same pattern as the
existing `Séances`/`Administrateurs`/`Membres` links.

`UpdateMemberRequest` and `admin/members/edit.blade.php` switch from the
`AttendanceTitle::cases()` select to a `title_id`/`position_id` pair with the
same Alpine cascading behavior as the public form (§3).

### 3. Public check-in form

`AttendanceFormController::lookup()`/`show()` pass `titles: Title::with('positions')->orderBy('name')->get()`
to the view (in addition to the existing `email`/`member` data).

`attendance-form.blade.php`: the "Titre / Qualité*" select is replaced by two
fields — "Titre*" (from `$titles`) and, conditionally, "Poste / Qualité*".
Cascading is done entirely client-side with Alpine (no AJAX, per the earlier
decision — the dataset is a few dozen rows at most):

```blade
<div x-data="{
        titleId: '{{ old('title_id', $member?->title_id) }}',
        positionId: '{{ old('position_id', $member?->position_id) }}',
        positionsByTitle: {{ Js::from($titles->mapWithKeys(fn ($t) => [
            $t->id => $t->positions->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values(),
        ])) }},
        get availablePositions() { return this.positionsByTitle[this.titleId] ?? [] },
    }"
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

`StoreAttendanceRequest` rules:

```php
'title_id' => ['required', 'integer', 'exists:titles,id'],
'position_id' => [
    'nullable', 'integer', 'exists:positions,id',
    function (string $attribute, mixed $value, \Closure $fail): void {
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
    },
],
```

This enforces server-side, independent of what the client filtered, both
"poste required when the titre has any" and "poste must actually belong to
the submitted titre" — the client-side filtering is UX only, never trusted
for validation.

### 4. Files touched

- `database/migrations/2026_07_15_*_create_titles_table.php` (new)
- `database/migrations/2026_07_15_*_create_positions_table.php` (new)
- `database/migrations/2026_07_15_*_create_position_title_table.php` (new)
- `database/migrations/2026_07_15_*_seed_titles_and_positions.php` (new, data migration)
- `database/migrations/2026_07_15_*_add_title_and_position_to_members_table.php` (new)
- `database/migrations/2026_07_15_*_add_title_and_position_to_attendances_table.php` (new)
- `database/migrations/2026_07_15_*_backfill_title_and_position_ids.php` (new, data migration)
- `database/migrations/2026_07_15_*_finalize_title_and_position_columns.php` (new — NOT NULL + drop old `title` column)
- `app/Models/Title.php`, `app/Models/Position.php` (new)
- `app/Models/Member.php`, `app/Models/Attendance.php` (relations, fillable, category attribute)
- `app/Enums/AttendanceTitle.php` (deleted)
- `database/factories/TitleFactory.php`, `database/factories/PositionFactory.php` (new)
- `database/factories/MemberFactory.php`, `database/factories/AttendanceFactory.php` (use `Title::factory()`/`Position::factory()` instead of `AttendanceTitle::cases()`)
- `app/Http/Controllers/Admin/TitleController.php`, `app/Http/Controllers/Admin/PositionController.php` (new)
- `app/Http/Requests/StoreTitleRequest.php`, `UpdateTitleRequest.php`, `StorePositionRequest.php`, `UpdatePositionRequest.php` (new)
- `app/Http/Requests/StoreAttendanceRequest.php`, `UpdateMemberRequest.php` (rules rewritten)
- `app/Http/Controllers/AttendanceFormController.php` (pass `$titles` to the view)
- `resources/views/admin/titles/{index,create,edit}.blade.php`, `resources/views/admin/positions/{index,create,edit}.blade.php` (new)
- `resources/views/components/layouts/admin.blade.php` (sidebar entries)
- `resources/views/components/attendance-form.blade.php` (titre/poste fields + Alpine cascade)
- `resources/views/admin/members/edit.blade.php` (same field/cascade change)
- `routes/web.php` (10 new `admin.titles.*`/`admin.positions.*` routes)

## Testing

New `tests/Feature/Admin/TitleManagementTest.php` and
`PositionManagementTest.php`:

- Unauthenticated requests redirect to login.
- `store`/`update` persist `name`/`category` and sync the `position_ids`
  pivot correctly (attach, detach, replace).
- Validation rejects a duplicate `name`.

Updated `tests/Feature/AttendanceMemberCheckInTest.php` (and any other test
touching the check-in flow or `AttendanceTitle`):

- Submitting a titre with linked positions but no `position_id` fails
  validation.
- Submitting a titre with no linked positions (e.g. Invité) and no
  `position_id` succeeds.
- Submitting a `position_id` that isn't linked to the submitted `title_id`
  fails validation.
- `Attendance::category` resolves via `title->category` correctly for a
  Rotary/Officials-style poste and a plain Rotary "Membre" poste alike (both
  now land in the same category, per the dropped distinction).

New migration test seeding rows across several of the old 17
`AttendanceTitle` string values, running the backfill, and asserting every
row resolves to the exact `(title, position)` pair from the mapping table in
§1 — with particular attention to `Rotarien`/`Rotaractien`/`Invité`, since
those are the three cases where the mapping isn't a simple 1:1 name match.

All existing factories/tests referencing `AttendanceTitle::cases()` or a
plain string `title` attribute need updating to the new `title_id`/
`Title::factory()` shape — this touches most existing feature tests that
create a `Member` or `Attendance`.

## Out of scope

- `destroy` actions for Title/Position — matches the existing
  `MemberController` convention of never deleting; `restrictOnDelete` at the
  DB level is the only safety net.
- Restoring any officials/members distinction in the dashboard category —
  explicitly dropped per the design discussion; a titre's category is now
  the sole source of truth.
- An AJAX/API endpoint for fetching a titre's positions — the full dataset
  is embedded once per page load via Alpine, since it's small (a few dozen
  rows).
- Reordering titres/positions in the select lists beyond the existing
  `orderBy('name')` — no explicit sort-order field.
