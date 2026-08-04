# Optional "Nom de club" field for guests — Design

Date: 2026-08-04

## Context

On the public check-in form, selecting the "Invité" organisation ([[2026-07-16-terminology-and-guest-invite-design]]) currently still requires the same "Nom de votre club*" field as regular members. Guests attending a Rotary meeting frequently don't belong to any club, so this field should be hidden and optional for them by default — while giving the admin the ability to turn it back on for clubs/events where they do want to collect it.

This follows the same single-row-settings pattern already used by `CheckinSetting::show_guest_option`.

## Admin setting

Add a second boolean column to the existing `checkin_settings` table (no new table — this is another flag on the same single-row settings record):

- `show_club_field_for_guests` (boolean, default `false`)
- `CheckinSetting::clubFieldEnabledForGuests(): bool`, mirroring the existing `guestOptionEnabled()` (`static::current()?->show_club_field_for_guests ?? false`)
- `UpdateCheckinSettingRequest`: add `show_club_field_for_guests` to `prepareForValidation()` (boolean coercion) and to `rules()`
- `resources/views/admin/checkin-settings/edit.blade.php`: second checkbox, unchecked by default — "Afficher le champ « Nom de club » pour les invités"

## Public check-in form

`AttendanceFormController::attendanceFormData()` passes a new `clubFieldEnabledForGuests` boolean to the view (same shape as `guestTitleId` today).

`attendance-form.blade.php`: the club field's visibility/required state becomes derived, alongside the existing `isGuest` Alpine getter:

```js
get clubRequired() { return !this.isGuest || clubFieldEnabledForGuests }
```

(`clubFieldEnabledForGuests` injected into the `x-data` object from the server-side flag.)

- Wrap the club field's container in `x-show="clubRequired"` (matching the existing pattern used for the position field and the "Invité par" field)
- Bind `:required="clubRequired"` on the `<input name="club">`, replacing the current static `required`
- The label's trailing `*` only makes sense when the field is required; since the field is hidden whenever it isn't required, no dynamic label text is needed — the `*` can stay static in the markup.

## Server-side validation & storage

`club` moves from unconditionally required to conditionally required:

- `StoreAttendanceRequest::rules()`: `'club' => ['nullable', 'string', 'max:255', Rule::requiredIf(fn () => $this->clubRequiredForSubmission())]`
- New private helper `clubRequiredForSubmission()`: `true` unless the submitted `title_id` resolves to the "Invité" title (`Title::GUEST_NAME`) **and** `CheckinSetting::clubFieldEnabledForGuests()` is `false` — mirrors the `positionBelongsToTitle` rule's approach of resolving `Title::find($this->input('title_id'))`.

This requires `club` to become nullable in the database on both tables it's currently stored on:

- Migration: `attendances.club` → nullable (currently `string` NOT NULL, `2026_07_10_140112_create_attendances_table.php`)
- Migration: `members.club` → nullable (currently `string` NOT NULL, `2026_07_14_213248_create_members_table.php`)

`AttendanceFormController::store()` needs no change — it already passes validated data straight through via `$request->safe()->only([...])` and `$request->validated()`.

**Out of scope:** `StoreMemberRequest` / `UpdateMemberRequest` (admin-side member management forms) keep `club` required as-is. This change is specific to the public guest check-in path; an admin manually creating/editing a member record still must supply a club.

## Side effect: JS "null" rendering bug

`resources/views/components/attendance-row.blade.php:7` builds a display string by concatenating `record.club` directly (`... + ' · ' + record.club`), where `record.club` comes from `@js($attendance->club)` in `admin/sessions/show.blade.php`. With `club = null`, JS string concatenation renders the literal text "null" in the admin attendance list. Fix: fall back to an empty string (e.g. `(record.club ?? '')`) so a null club renders as nothing, consistent with how it already displays in `admin/members/index.blade.php` and `sessions/pdf.blade.php` (plain Blade `{{ }}` already renders null as empty).

## Testing

- Feature test: guest submission without `club` succeeds when `show_club_field_for_guests` is `false` (default)
- Feature test: guest submission without `club` fails validation when `show_club_field_for_guests` is `true`
- Feature test: non-guest submission without `club` still fails validation regardless of the setting
- Feature test: admin can update `show_club_field_for_guests` via `Admin\CheckinSettingController::update`

## Out of scope

- No change to admin member CRUD forms (`StoreMemberRequest`/`UpdateMemberRequest`) — club stays required there.
- No per-title generalization (e.g. a `requires_club` flag on `titles`) — this is specific to the "Invité" title, matching the existing `show_guest_option` precedent of a single-purpose settings flag rather than a generic configuration system (YAGNI).
- No backfill/cleanup of existing `club` data — the columns simply become nullable; existing rows are untouched.
