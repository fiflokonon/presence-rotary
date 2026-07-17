# Ordre hiérarchique des titres/qualités (Position) — spec

Date: 2026-07-17

## Context

`App\Models\Title` (labelled "Organisation" in the UI) already has a
hierarchical `order` column, a `moveOrder()` action on
`Admin\TitleController`, and up/down arrows on
`admin/titles/index.blade.php` (see `2026-07-16` order work). `App\Models\Position`
(labelled "Titre/Qualité" in the UI) has no such concept — `positions.name`
is unique but there's no ordering, and `Admin\PositionController::index()`
simply sorts `Position::orderBy('name')->get()`.

On the admin attendance roster (`admin.sessions.show`), each attendance
carries a `position_id` (nullable) rendered next to the person's name and
organisation. Today, within a roster group, records have no defined order
beyond insertion order.

## Goal

Let an admin define a hierarchical display order for postes (Président
before Secrétaire before Membre, etc.), and use that order to sort the
attendance roster — both within its existing category groups, and,
optionally, across the whole roster ignoring grouping.

## Design

### 1. Data model

```php
Schema::table('positions', function (Blueprint $table) {
    $table->unsignedInteger('order')->nullable()->after('name');
});
```

A backfill migration mirrors `2026_07_16_114608_initialize_title_order_values.php`:
assign every position without an `order` a sequential value based on
current alphabetical order. No name-based exclusion is needed (unlike
`Title::GUEST_NAME`) — every `Position` row is a normal, reorderable
poste.

### 2. Admin UI — `Admin\PositionController`

Mirrors `TitleController` exactly:

```php
public function index(): View
{
    return view('admin.positions.index', [
        'positions' => Position::orderBy('order')->orderBy('name')->get(),
    ]);
}

public function store(StorePositionRequest $request): RedirectResponse
{
    $maxOrder = Position::max('order');
    $nextOrder = $maxOrder === null ? 0 : $maxOrder + 1;

    Position::create([...$request->validated(), 'order' => $nextOrder]);

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

    $swapWith = $direction === 'up'
        ? Position::where('order', '<', $position->order)->orderByDesc('order')->first()
        : Position::where('order', '>', $position->order)->orderBy('order')->first();

    if ($swapWith !== null) {
        $tempOrder = $position->order;
        $position->update(['order' => $swapWith->order]);
        $swapWith->update(['order' => $tempOrder]);
    }

    return redirect()->route('admin.positions.index');
}
```

New route, next to the existing `positions.*` group:

```php
Route::patch('positions/{position}/move-order/{direction}', [PositionController::class, 'moveOrder'])->name('positions.move-order');
```

`admin/positions/index.blade.php` gains an "Ordre" column with the same
↑/↓ form pair as `admin/titles/index.blade.php` (lines 35-52 of that
view), placed between "Nom" and "Statut".

### 3. Attendance roster — `admin.sessions.show`

The controller passes each attendance's `position->order` (nullable int)
into the JSON fed to the `attendanceDashboard` Alpine component, alongside
the existing `position` name.

`resources/js/app.js`'s `attendanceDashboard`:

- New reactive flag `sortMode: 'grouped'` (the other value: `'position'`).
- A shared comparator sorts by `positionOrder` ascending, `null` sorted
  last, ties broken by `name`:
  ```js
  sortRecords(records) {
      return [...records].sort((a, b) => {
          const aOrder = a.positionOrder ?? Infinity;
          const bOrder = b.positionOrder ?? Infinity;
          if (aOrder !== bOrder) return aOrder - bOrder;
          return a.name.localeCompare(b.name);
      });
  }
  ```
- `groups` getter (grouped mode) applies `sortRecords()` to each group's
  `records` after filtering, instead of leaving filter order untouched.
- New `flatSorted` getter (position mode): `sortRecords(this.filtered)`,
  no grouping.
- The roster template (`sessions/show.blade.php`) gains a toggle button
  ("Grouper par organisation" / "Trier par poste") that flips `sortMode`,
  and conditionally renders either the existing `<template x-for="group in
  groups">` block or a new flat `<template x-for="record in
  flatSorted">` block reusing the same row markup.

## Files touched

- `database/migrations/*_add_order_to_positions_table.php` (new)
- `database/migrations/*_initialize_position_order_values.php` (new)
- `app/Models/Position.php` (`order` fillable)
- `app/Http/Controllers/Admin/PositionController.php` (`index`, `store`, new `moveOrder`)
- `resources/views/admin/positions/index.blade.php` (Ordre column + arrows)
- `resources/views/admin/sessions/show.blade.php` (sort-mode toggle, flat-list block, `positionOrder` in the JSON payload)
- `resources/js/app.js` (`attendanceDashboard`: `sortMode`, `sortRecords()`, `flatSorted`)
- `routes/web.php` (1 new route)

## Testing

- `moveOrder('up')`/`moveOrder('down')` swap two adjacent positions'
  `order` and redirect; a position with `order === null` is assigned one
  on first move rather than erroring.
- Creating a new position appends it after the current max `order`.
- `admin/positions/index.blade.php` renders positions sorted by `order`,
  with working ↑/↓ forms.
- On `admin.sessions.show`, within a group, attendances render sorted by
  their position's order (a "Président" record appears before a "Membre"
  record in the same organisation group).
- An attendance whose `position_id` is `null` sorts after all
  positioned attendances in its group.
- Toggling to "Trier par poste" flattens the roster into a single list
  ordered purely by position, spanning multiple organisations.

## Out of scope

- Reordering positions from the roster page itself — ordering is only
  managed from `admin/positions`.
- Any change to how postes are linked to organisations (the
  `position_title` pivot) — this spec only adds ordering and its effect
  on sort, not on which postes are selectable per organisation.
