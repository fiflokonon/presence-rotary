# Titre/Poste activation & deletion — spec

Date: 2026-07-15

## Context

`App\Models\Title` and `App\Models\Position` (introduced by the earlier
"titre/poste management" feature — see
`2026-07-15-title-position-management-design.md`) currently have no
lifecycle beyond create/edit: `Admin\TitleController` and
`Admin\PositionController` have `index`/`create`/`store`/`edit`/`update`
only, no way to retire a titre/poste that's no longer wanted, and no
`destroy` action at all. `title_id` (on both `members` and `attendances`)
is a required, `restrictOnDelete` foreign key; `position_id` is the same
but nullable. `Title`/`Position` are linked many-to-many via the
`position_title` pivot (`cascadeOnDelete` on both sides of the pivot
itself).

The public check-in form (`AttendanceFormController::show()`/`lookup()`)
and the admin member-edit form (`MemberController::edit()`) each build a
titre select plus a client-side Alpine cascade of linked postes from
`Title::with('positions')->orderBy('name')->get()`. The Title admin
create/edit form (`TitleController::create()`/`edit()`) builds a checkbox
list of every `Position` to manage the many-to-many pivot via
`$title->positions()->sync(...)`.

## Goal

Let an admin retire a titre/poste without breaking anything already
referencing it. Two independent capabilities:

- **Deactivate/reactivate** (reversible, always allowed): hides the
  titre/poste from every *future* selection — the public check-in form,
  the admin member-edit form, and (for postes) new pivot links on the
  Title admin form — while anything that already references it keeps
  displaying and working exactly as before.
- **Delete** (permanent): only succeeds when nothing references it;
  otherwise blocked with a clear message pointing at deactivation instead.

## Design

### 1. Data model

```php
Schema::table('titles', function (Blueprint $table) {
    $table->boolean('is_active')->default(true)->after('category');
});
Schema::table('positions', function (Blueprint $table) {
    $table->boolean('is_active')->default(true)->after('name');
});
```

`Title`/`Position` gain `is_active` in `$fillable` and cast to `boolean`,
plus two local scopes each, used throughout the codebase instead of
repeating the OR-condition:

```php
// Title (Position gets the identical pair)
public function scopeActive(Builder $query): void
{
    $query->where('is_active', true);
}

public function scopeActiveOrId(Builder $query, ?int $id): void
{
    $query->where('is_active', true)->when($id !== null, fn ($q) => $q->orWhere('id', $id));
}
```

`scopeActiveOrId` is the piece that makes the pre-fill edge case in §3
work: "active, or specifically this one regardless of its flag."

No cascading between the two models' active flags — deactivating a titre
never touches its linked postes' `is_active`, and vice versa.

### 2. Admin UI — toggle & delete

New routes, inside the existing `admin.` / `auth`-protected group, added
next to the existing `titles.*`/`positions.*` routes:

```php
Route::patch('titles/{title}/toggle-active', [TitleController::class, 'toggleActive'])->name('titles.toggle-active');
Route::delete('titles/{title}', [TitleController::class, 'destroy'])->name('titles.destroy');
Route::patch('positions/{position}/toggle-active', [PositionController::class, 'toggleActive'])->name('positions.toggle-active');
Route::delete('positions/{position}', [PositionController::class, 'destroy'])->name('positions.destroy');
```

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

(`PositionController` gets the identical pair, with "Ce poste" in the
message.) No pre-emptive existence check — the `restrictOnDelete`
constraint already in place is the source of truth; the controller just
translates its failure into a flashed French message. A poste's pivot
rows in `position_title` cascade-delete automatically (that's just
unlinking, not blocked); only a live `Member`/`Attendance` reference
blocks the delete.

`admin/titles/index.blade.php` / `admin/positions/index.blade.php` each
gain, per row: a status badge ("Actif" / "Inactif"), a toggle button (form
posting to `toggle-active`, no confirmation — it's reversible), and a
delete button (form posting to `destroy`, with a JS `confirm()` prompt —
this app has no existing confirmation modal to reuse, so a plain
`onclick="return confirm('...')"` matches the simplicity of the rest of
the admin UI). Both index views also render a flashed `session('error')`
banner (new — no existing admin view currently displays one, since this is
the first `destroy` action in the app).

No "Actif" field on the create/edit forms — toggling only happens from the
index list, matching how `MeetingSessionController::toggleOpen()` keeps
`is_open` a separate action rather than an edit-form field.

### 3. Active-or-current filtering across the three form-building call sites

All three places that build a titre/poste select apply the same rule:
**active items, plus whatever the record being edited/pre-filled already
has** — so nobody's existing (now-inactive) titre/poste silently vanishes
from their own form.

**`AttendanceFormController::lookup()`** (email matched an existing
`$member`) and **`MemberController::edit()`** (always has a `$member`)
build the same query:

```php
Title::query()
    ->activeOrId($member?->title_id)
    ->with(['positions' => fn ($q) => $q->activeOrId($member?->position_id)])
    ->orderBy('name')
    ->get()
```

**`AttendanceFormController::show()`** (unknown email — no `$member`
resolved yet, or none found) passes `$member = null`, so `activeOrId(null)`
degrades to plain `active()` — no exception, since there's nothing to
preserve.

**`TitleController::create()`** offers active positions only (no title
exists yet, nothing to preserve):

```php
Position::active()->orderBy('name')->get()
```

**`TitleController::edit()`** offers active positions **or** positions
already linked to *this* title, regardless of their own active state:

```php
Position::query()
    ->where('is_active', true)
    ->orWhereIn('id', $title->positions()->pluck('positions.id'))
    ->orderBy('name')
    ->get()
```

The edit view appends " (inactif)" to the label of any position in that
set whose `is_active` is `false`, but renders the checkbox as a normal,
non-disabled input — the query already guarantees an inactive-and-unlinked
position never appears as an option, so there's no risk of it being newly
selected; nothing needs to be disabled to enforce that. Checking/unchecking
it behaves exactly like any other checkbox (detach on uncheck, matches
`sync()`'s existing behavior). Same treatment applies to the member-edit
and check-in forms' select: an inactive-but-currently-assigned titre/poste
gets " (inactif)" appended to its `<option>` label, still fully selectable
(so an admin can deliberately keep it, or pick something else) — never
`disabled`, since relying on disabled-option submission semantics across
browsers is fragile and the query scoping alone already guarantees no
*other* inactive item can be picked.

### 4. Files touched

- `database/migrations/*_add_is_active_to_titles_and_positions_tables.php` (new)
- `app/Models/Title.php`, `app/Models/Position.php` (`is_active` fillable/cast, `active()`/`activeOrId()` scopes)
- `app/Http/Controllers/Admin/TitleController.php`, `PositionController.php` (`toggleActive`, `destroy`; `create()`/`edit()` query changes)
- `app/Http/Controllers/AttendanceFormController.php` (`show()`/`lookup()` query changes)
- `app/Http/Controllers/Admin/MemberController.php` (`edit()` query change)
- `resources/views/admin/titles/index.blade.php`, `resources/views/admin/titles/edit.blade.php`
- `resources/views/admin/positions/index.blade.php`, `resources/views/admin/positions/edit.blade.php`
- `resources/views/components/attendance-form.blade.php`, `resources/views/admin/members/edit.blade.php` (append "(inactif)" to stale-but-assigned options)
- `routes/web.php` (4 new routes)

## Testing

- Toggle flips `is_active` and redirects; repeatable both directions.
- Delete succeeds when the titre/poste has no `Member`/`Attendance`
  references; deleting a poste also removes its `position_title` rows.
- Delete is blocked with the flashed French error when a `Member` or
  `Attendance` references the titre/poste; nothing is deleted.
- An inactive titre doesn't appear in the public check-in form when the
  email is unknown (nothing to preserve, so plain `active()` applies).
- An inactive titre doesn't appear as a *newly selectable* option in
  `MemberController::edit()` for a member whose current title is
  something else.
- A returning member (`AttendanceFormController::lookup()`) whose stored
  `title_id`/`position_id` is now inactive still sees it pre-filled and
  selected, appended with "(inactif)".
- `TitleController::edit()`'s checkbox list shows an inactive-but-linked
  poste (checked, "(inactif)" suffix) while omitting an inactive-and-unlinked
  one entirely; unchecking the former and saving detaches it.
- `TitleController::create()`'s checkbox list never offers an inactive
  poste.

## Out of scope

- Cascading deactivation between linked titres/postes.
- Any confirmation modal beyond a plain JS `confirm()` for delete — no
  existing pattern in this app to build on, and the destructive action is
  already gated by the FK-based safety net.
- Reassigning existing `Member`/`Attendance` rows away from a titre/poste
  before deletion — deletion simply stays blocked until the admin either
  reassigns them manually (via the member/attendance edit screens that
  already exist) or leaves the titre/poste deactivated instead.
