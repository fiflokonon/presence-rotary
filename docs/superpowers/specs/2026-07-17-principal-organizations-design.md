# Organisations principales & suppression des catégories — spec

Date: 2026-07-17

## Context

`App\Models\Title` (labelled "Organisation" in the UI) currently has a
required `category` column, cast to `App\Enums\AttendanceCategory`
(`Officials`, `Members`, `Rotaractors`, `Guests` — each with a French
`label()` and a fixed `colors()` pair). Seed data assigns `Rotary` →
`members`, `Rotaract` → `rotaractors`, and everything else (`JCI`,
`Lions`, `Inner Wheel`, `RRD`, `Invité`) → `guests`; `Officials` is never
actually assigned to any seeded title.

`App\Models\Attendance::category` derives from `title->category`. This
drives:

- the stat tiles and category quick-filter buttons at the top of
  `admin.sessions.show`,
- the grouping of the roster list on that same page (client-side, via
  `attendanceDashboard` in `resources/js/app.js`, using a hardcoded
  `['officials', 'members', 'rotaractors', 'guests']` group order),
- the section headers in the PDF export (`admin/sessions/pdf.blade.php`),
- the "Catégorie" select on `admin/titles/create.blade.php` and
  `edit.blade.php`.

This four-category scheme is being replaced: the admin should be able to
flag *any* organisation as "principale" (counted individually on the
dashboard), with everything else collapsing into a single "Autres
organisations" bucket. A cap of 3 simultaneously-flagged organisations
keeps the dashboard from getting crowded.

## Goal

Replace the fixed `category` enum with an admin-controlled `is_principal`
boolean on `Title`, capped at 3 flagged organisations at a time. The
roster/dashboard/PDF group by organisation name for principal
organisations, and by a fixed "Autres organisations" label for the rest,
always last.

## Design

### 1. Data model

```php
Schema::table('titles', function (Blueprint $table) {
    $table->boolean('is_principal')->default(false)->after('name');
});
```

A follow-up data migration sets `is_principal = true` for the existing
`Rotary` and `Rotaract` titles (preserving today's effective grouping —
these are the two organisations the dashboard currently highlights via
`members`/`rotaractors`), then drops `category`:

```php
Schema::table('titles', function (Blueprint $table) {
    $table->dropColumn('category');
});
```

`App\Enums\AttendanceCategory` is deleted entirely — nothing references
it once this migration lands.

`Title`:

```php
protected $fillable = ['name', 'is_principal', 'is_active', 'order'];

public const OTHER_ORGANIZATIONS_LABEL = 'Autres organisations';
public const MAX_PRINCIPAL = 3;

public function scopePrincipal(Builder $query): void
{
    $query->where('is_principal', true);
}
```

### 2. Admin UI & validation — `StoreTitleRequest`/`UpdateTitleRequest`

The "Catégorie" `<select>` on `admin/titles/create.blade.php` and
`edit.blade.php` is replaced with a single checkbox:

```html
<label class="flex items-center gap-2 text-sm">
    <input type="checkbox" name="is_principal" value="1" @checked(old('is_principal', $title->is_principal ?? false))>
    Organisation principale (comptée sur le tableau de bord)
</label>
```

Both form requests validate `is_principal` as boolean and add a custom
rule blocking a 4th flag:

```php
'is_principal' => ['boolean', function (string $attribute, mixed $value, Closure $fail) {
    if (! $value) {
        return;
    }

    $alreadyFlagged = Title::principal()
        ->when($this->route('title'), fn ($q, $title) => $q->whereKeyNot($title))
        ->count();

    if ($alreadyFlagged >= Title::MAX_PRINCIPAL) {
        $fail('Maximum '.Title::MAX_PRINCIPAL.' organisations principales — déflaggez-en une avant d\'en ajouter une nouvelle.');
    }
}],
```

(`StoreTitleRequest` has no `title` route parameter, so
`$this->route('title')` is `null` and every existing flagged title
counts — correct, since the one being created is new.)

`TitleController::store()`/`update()` swap `'category'` for
`'is_principal'` in the `only([...])` allow-list; no other controller
change needed.

### 3. Attendance grouping

```php
protected function groupLabel(): Attribute
{
    return Attribute::get(fn (): string => $this->title->is_principal ? $this->title->name : Title::OTHER_ORGANIZATIONS_LABEL);
}
```

replaces the `category` accessor on `App\Models\Attendance`.

### 4. Dashboard — `admin.sessions.show`

The controller builds the ordered list of principal titles once:

```php
$principalTitles = Title::principal()->orderBy('order')->orderBy('name')->get();
```

and a fixed 3-slot color palette (reusing the existing `AttendanceCategory`
palette values so the dashboard's look doesn't change) plus one neutral
pair for "Autres organisations":

```php
$palette = [
    ['bg' => '#EAF1FB', 'accent' => '#17458F'],
    ['bg' => '#E7F5F1', 'accent' => '#0E7C66'],
    ['bg' => '#FDF3E2', 'accent' => '#C77700'],
];
$othersColors = ['bg' => '#F1EFEA', 'accent' => '#6B6558'];

$groups = $principalTitles->values()->map(fn ($title, $i) => [
    'label' => $title->name,
    'colors' => $palette[$i],
])->push(['label' => Title::OTHER_ORGANIZATIONS_LABEL, 'colors' => $othersColors]);
```

`$groups` (label + colors, in display order — principal titles first in
their `order`, "Autres organisations" always last) is passed to the view
and JSON-encoded into the Alpine payload, replacing the current
`\App\Enums\AttendanceCategory::cases()` loops:

- **Stat tiles**: one per `$groups` entry (was: one per enum case),
  counting `$attendances->where('groupLabel', $group['label'])`.
- **Quick-filter buttons**: same swap, `activeCategory` renamed
  `activeGroup` in `app.js` for clarity, comparing against
  `record.groupLabel`.
- **Roster grouping**: `attendanceDashboard`'s hardcoded `order` array
  (`['officials', 'members', 'rotaractors', 'guests']`) becomes a
  constructor parameter — the same `$groups` label list — instead of a
  fixed constant. The `groups` getter maps over that parameter instead of
  the hardcoded array, so "Autres organisations" naturally sorts last
  simply by being the last entry.

Each attendance record in the JSON payload gains `groupLabel` (replacing
`category`) and `groupColors` is looked up from `$groups` client-side by
label rather than embedded per-record, keeping the payload small.

### 5. PDF export

`admin/sessions/pdf.blade.php`'s `@foreach (\App\Enums\AttendanceCategory::cases() as $category)`
loop is replaced with a loop over the same ordered label list (passed
from the controller, mirroring §4):

```blade
@foreach ($groupLabels as $label)
    @php $groupAttendances = $attendances->filter(fn ($attendance) => $attendance->groupLabel === $label); @endphp
    @if ($groupAttendances->isNotEmpty())
        <h2>{{ $label }} ({{ $groupAttendances->count() }})</h2>
        {{-- unchanged table markup, using $groupAttendances instead of $categoryAttendances --}}
    @endif
@endforeach
```

### 6. Factory

`TitleFactory::definition()` drops `'category' => fake()->randomElement(...)`
and adds `'is_principal' => false`. No new factory state is needed for a
single boolean — tests that need a principal title just do
`Title::factory()->create(['is_principal' => true])`.

## Files touched

- `database/migrations/*_add_is_principal_to_titles_table.php` (new)
- `database/migrations/*_backfill_is_principal_and_drop_category.php` (new)
- `app/Enums/AttendanceCategory.php` (deleted)
- `app/Models/Title.php` (`is_principal` fillable, `OTHER_ORGANIZATIONS_LABEL`/`MAX_PRINCIPAL` constants, `scopePrincipal`; `category` cast removed)
- `app/Models/Attendance.php` (`category` accessor → `groupLabel`)
- `app/Http/Requests/StoreTitleRequest.php`, `UpdateTitleRequest.php` (`is_principal` validation + max-3 rule)
- `app/Http/Controllers/Admin/TitleController.php` (`store`/`update` field swap)
- `app/Http/Controllers/Admin/MeetingSessionController.php` (`show`/`exportPdf` gain the `$groups`/`$groupLabels` computation from §4)
- `resources/views/admin/titles/create.blade.php`, `edit.blade.php` (checkbox instead of select)
- `resources/views/admin/titles/index.blade.php` (column swap: "Catégorie" → "Principale" badge)
- `resources/views/admin/sessions/show.blade.php` (dynamic tiles/filters/groups, `groupLabel` in payload)
- `resources/views/admin/sessions/pdf.blade.php` (loop over `$groupLabels`)
- `resources/js/app.js` (`attendanceDashboard`: `order` param instead of hardcoded array, `activeCategory` → `activeGroup`, `record.category` → `record.groupLabel`)
- `database/factories/TitleFactory.php` (`is_principal` instead of `category`)

## Testing

- Flagging a title `is_principal = true` succeeds while fewer than 3
  others are flagged; the 4th attempt (store or update) fails validation
  with the French cap message, and no title's flag changes.
- Editing an already-principal title (without changing the flag, or
  unflagging it) never counts itself against the cap.
- `admin.sessions.show` renders one stat tile per principal title (in
  `order`) plus a final "Autres organisations" tile; an attendance whose
  title isn't flagged principal is counted under "Autres organisations".
- The roster groups render principal organisations first (in `order`),
  "Autres organisations" last, matching the stat tile order.
- PDF export produces the same grouping/order as the roster.
- `admin/titles/index.blade.php` shows a "Principale" badge instead of a
  category label.
- Existing `TitleManagementTest`, `SeedTitlesAndPositionsTest`,
  `AttendanceTest`, `TitleTest`, `AttendancePdfExportTest`,
  `AttendanceDashboardTest`, `OrganisationLabelTest` are updated to drop
  every `category`/`AttendanceCategory` reference and assert the new
  `is_principal`/`groupLabel` behavior instead.

## Out of scope

- Any UI to reorder which principal organisation shows first beyond the
  existing `Title.order` field (design 1/2's position-order spec) — the
  same `order` column already on `titles` is reused, no new ordering
  mechanism.
- Automatically unflagging the oldest principal organisation when the cap
  is hit — blocked with a validation error instead, per the earlier
  design discussion.
- Any change to the seeded `Invité`/guest-organisation special-casing
  (`Title::GUEST_NAME`) beyond it defaulting to `is_principal = false`
  like any other non-Rotary/Rotaract organisation.
