# Terminology rename (Titre/Poste) & guest "invited by" field — Design

Date: 2026-07-16

## Context

Three UI/behavior changes requested:

1. Rename the user-facing labels "Titre" → "Organisation" and "Poste" → "Titre/Qualité" throughout the app.
2. When a person picks "Invité" instead of an organisation on the public check-in form, show an optional field to enter the name of the person who invited them.
3. When a "titre/qualité" (position) is inactive, it must not appear on the client-facing check-in form.

Investigation found requirement #3 is **already fully implemented** by prior work (commits `493d54d`, `31d8233`): `AttendanceFormController` uses `Title::activeOrId()` / `Position::activeOrId()`, which exclude inactive organisations/titres-qualité from the public form's options, except for whatever value is already assigned to the member being edited (shown suffixed `(inactif)`). No code change is needed for #3 — only the terminology rename from part 1 applies to its labels.

## Part 1 — Terminology rename (UI strings only)

Scope: **only the displayed strings** change. The underlying model classes (`Title`, `Position`), database tables, routes (`admin.titles.*`, `admin.positions.*`), and FK column names (`title_id`, `position_id`) are unchanged. This keeps the change low-risk (no migrations, no route renames, no risk to tests referencing class/route names).

**"Titre" → "Organisation"**, in:
- `resources/views/components/attendance-form.blade.php` — `title_id` select label
- `resources/views/components/layouts/admin.blade.php` — sidebar nav link
- `resources/views/admin/titles/index.blade.php`, `create.blade.php`, `edit.blade.php` — page titles, headings, buttons, confirm dialogs
- `resources/views/admin/sessions/pdf.blade.php` — PDF export column header
- `resources/views/admin/sessions/show.blade.php` — the "Tous les titres" filter option (this one filters attendees by their organisation name, despite living in the sessions view)
- `resources/views/admin/members/show.blade.php`, `edit.blade.php` — member detail/edit labels
- `app/Http/Controllers/Admin/TitleController.php` — delete-blocked error message

**Explicitly NOT renamed:** `MeetingSession->title` (the meeting/session's own name, e.g. "Réunion du 20/07") in `resources/views/admin/sessions/index.blade.php`, `pdf.blade.php`, `attendance/show.blade.php` — unrelated same-named column, stays "Titre".

**"Poste" → "Titre/Qualité"**, in:
- `resources/views/components/attendance-form.blade.php` — `position_id` select label + JS `(inactif)` suffix text
- `resources/views/components/layouts/admin.blade.php` — sidebar nav link
- `resources/views/admin/positions/index.blade.php`, `create.blade.php` — page titles, headings, buttons, confirm dialogs
- `resources/views/admin/titles/create.blade.php`, `edit.blade.php` — "Postes liés" checkbox group header
- `resources/views/admin/members/edit.blade.php` — label
- `app/Http/Requests/StoreAttendanceRequest.php`, `UpdateMemberRequest.php` — validation messages
- `app/Http/Controllers/Admin/PositionController.php` — delete-blocked error message

## Part 2 — Guest ("Invité") flow

### Remove "Invité" from the admin Organisation CRUD

The `Title` row named "Invité" stays in the database (preserves history for existing members/attendances referencing it) but is excluded from `TitleController@index`, `@create`, `@edit` — an admin can no longer view, rename, deactivate, or delete it via the UI. It's identified by its exact name (`Title::where('name', 'Invité')`); since it's no longer editable via admin, this name becomes a reliable constant to reference in code (e.g. a `Title::GUEST_NAME` constant or a `Title::guest()` helper).

### New global setting: "show guest option"

Following the existing single-row-table pattern used for `MailSetting`:
- Migration: `checkin_settings` table (single row), boolean column `show_guest_option` (default `false`).
- Model: `CheckinSetting`, with a `current()` static helper mirroring `MailSetting::current()`.
- Controller: `Admin\CheckinSettingController` (`edit`, `update`).
- View: `resources/views/admin/checkin-settings/edit.blade.php` — a single checkbox.
- Admin sidebar: new nav link.

### Public check-in form behavior

The "Organisation" select lists active organisations as today (with "Invité" excluded from this normal list). If `CheckinSetting::current()->show_guest_option` is true, an extra "Invité" option is appended at the end of the select, internally pointing at the existing "Invité" `Title` row's id.

When "Invité" is selected:
- The "Titre/Qualité" select stays hidden (existing behavior: "Invité" has zero linked positions).
- A new optional text input "Invité par" appears (Alpine `x-show`), for the name of the person who invited the guest.

### Storage

New nullable `invited_by` (string) column on `attendances` only (not on `members` — this is per-visit information, not part of the member's profile). `StoreAttendanceRequest` validates `invited_by` as `nullable|string|max:255`.

## Out of scope

- No changes to `Position`/`Title` model/table/route names.
- No changes to the admin member-edit form's organisation/titre-qualité selects (guest flow is public-check-in-form only).
- No generic key-value settings system — `checkin_settings` is a single-purpose table matching the existing `mail_settings` precedent (YAGNI: only one boolean is needed today).
