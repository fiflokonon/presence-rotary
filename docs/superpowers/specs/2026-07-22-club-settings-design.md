# Configurable club identity (logo, colors, contact info) — spec

Date: 2026-07-22

## Context

Every branded surface in the app hardcodes the same club identity: "RC Cotonou
Ife", "District 9103", the logo at `public/assets/ife-logo.png`, and a blue
gradient (`#17A8E5` → `#0B73C5`/`#0A5CA6`, header background `#0073C4`, gold
accent `#F2B94D`). It appears independently in:

- `resources/views/components/layouts/admin.blade.php` (sidebar/topbar logo + name)
- `resources/views/attendance/show.blade.php` (public check-in header, with a
  pre-existing duplicate name line at lines 8 and 10)
- `resources/views/admin/sessions/pdf.blade.php` (PDF header subtitle)
- `resources/views/mail/attendance-thank-you.blade.php`,
  `resources/views/mail/new-admin-credentials.blade.php`,
  `resources/views/mail/mail-setting-test.blade.php` (identical header markup,
  copy-pasted three times)

There is no way to change any of this without editing Blade templates. The
end goal is to eventually make this app multi-tenant (SaaS), but that is a
separate, much larger project (tenant isolation, billing, per-tenant auth).
This spec deliberately stays single-tenant: one global, admin-editable
"club identity" record, following the exact singleton-settings pattern
already established by `App\Models\CheckinSetting` and `App\Models\MailSetting`
(`::current()` static accessor, get-or-create in the controller, no factory).

Note on naming: the app already uses "Organisation" for something else — the
`Title` model (nav entry "Organisations", route `admin.titles.*`) represents
the clubs/affiliations a *member* belongs to. To avoid confusion, the new
concept introduced here is called **"club identity"** (model `ClubSetting`,
nav entry "Identité du club") — it represents the club that operates this
installation, not a member's affiliation.

## Goal

Let an admin configure, from one settings page:

- Name, optional tagline (replaces hardcoded "RC Cotonou Ife" / "District 9103")
- Logo upload
- Two brand colors (primary/secondary), used for the header/badge gradient
  currently hardcoded in blue
- Contact info: address, phone, email
- Links: website, Facebook, Instagram

...and have every one of the six branded views/templates read from this
record instead of hardcoded strings/colors, with contact info and links
additionally shown in the PDF export footer and the email footers.

## Design

### 1. Data model & migrations

New model `App\Models\ClubSetting`, table `club_settings`. Single-row
("singleton") table, same shape of problem as `MailSetting`.

```php
Schema::create('club_settings', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('tagline')->nullable();
    $table->string('logo_path')->nullable();
    $table->string('primary_color', 7);
    $table->string('secondary_color', 7);
    $table->string('address')->nullable();
    $table->string('phone')->nullable();
    $table->string('email')->nullable();
    $table->string('website')->nullable();
    $table->string('facebook_url')->nullable();
    $table->string('instagram_url')->nullable();
    $table->timestamps();
});
```

```php
class ClubSetting extends Model
{
    protected $fillable = [
        'name', 'tagline', 'logo_path', 'primary_color', 'secondary_color',
        'address', 'phone', 'email', 'website', 'facebook_url', 'instagram_url',
    ];

    public static function current(): ?self
    {
        return static::query()->first();
    }

    public function logoUrl(): string
    {
        return $this->logo_path !== null
            ? Storage::disk('public')->url($this->logo_path)
            : asset('assets/ife-logo.png');
    }

    public function hasSocialLinks(): bool
    {
        return $this->website !== null || $this->facebook_url !== null || $this->instagram_url !== null;
    }

    public function hasContactInfo(): bool
    {
        return $this->address !== null || $this->phone !== null || $this->email !== null;
    }
}
```

A second, data-only migration seeds the single row with today's real values,
matching this codebase's existing convention for data backfills (e.g.
`2026_07_17_100001_backfill_is_principal_values.php`):

```php
DB::table('club_settings')->insert([
    'name' => 'RC Cotonou Ife',
    'tagline' => 'District 9103',
    'logo_path' => null, // keeps falling back to asset('assets/ife-logo.png')
    'primary_color' => '#0B73C5',
    'secondary_color' => '#17A8E5',
    'created_at' => now(),
    'updated_at' => now(),
]);
```

`logo_path` stays `null` in the seed — `logoUrl()` already falls back to the
existing static asset, so no binary file needs to be copied into storage by
the migration. Every other view change below is safe from the moment this
migration runs: nothing visually changes until an admin edits the form.

No factory — consistent with `CheckinSetting`/`MailSetting`, which don't have
one either.

### 2. Admin UI & routes

New `Admin\ClubSettingController`:

```php
public function edit(): View
{
    return view('admin.club-settings.edit', ['clubSetting' => ClubSetting::current()]);
}

public function update(UpdateClubSettingRequest $request): RedirectResponse
{
    $data = $request->safe()->except('logo');
    $clubSetting = ClubSetting::current();

    if ($request->hasFile('logo')) {
        if ($clubSetting?->logo_path) {
            Storage::disk('public')->delete($clubSetting->logo_path);
        }
        $data['logo_path'] = $request->file('logo')->store('club', 'public');
    }

    $clubSetting?->update($data) ?? ClubSetting::create($data);

    return redirect()->route('admin.club-settings.edit')->with('status', 'Identité du club enregistrée.');
}
```

Routes, in the existing `admin.` group under `auth` middleware
(`routes/web.php`):

```php
Route::get('club-settings', [ClubSettingController::class, 'edit'])->name('club-settings.edit');
Route::put('club-settings', [ClubSettingController::class, 'update'])->name('club-settings.update');
```

New sidebar entry in `resources/views/components/layouts/admin.blade.php`,
placed near "Paramètres mail"/"Paramètres formulaire":

```blade
<a href="{{ route('admin.club-settings.edit') }}" @click="close()"
    class="cursor-pointer rounded-lg px-3 py-2 text-sm font-semibold {{ request()->routeIs('admin.club-settings.*') ? 'bg-navy text-white' : 'text-navy hover:bg-cream' }}">
    Identité du club
</a>
```

New view `resources/views/admin/club-settings/edit.blade.php`
(`<x-layouts.admin>`), following the form conventions in
`resources/views/admin/mail-settings/edit.blade.php`: text inputs for name
(required), tagline, address, phone, email, website, Facebook, Instagram;
`<input type="color">` paired with a text hex fallback for primary/secondary;
a file input for the logo with a preview of the current `logoUrl()`;
"Enregistrer" submit button; `enctype="multipart/form-data"` on the form.

`UpdateClubSettingRequest`:

```php
public function rules(): array
{
    return [
        'name' => ['required', 'string', 'max:255'],
        'tagline' => ['nullable', 'string', 'max:255'],
        'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,svg', 'max:2048'],
        'primary_color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        'secondary_color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        'address' => ['nullable', 'string', 'max:255'],
        'phone' => ['nullable', 'string', 'max:50'],
        'email' => ['nullable', 'email', 'max:255'],
        'website' => ['nullable', 'url', 'max:255'],
        'facebook_url' => ['nullable', 'url', 'max:255'],
        'instagram_url' => ['nullable', 'url', 'max:255'],
    ];
}
```

### 3. Shared mail components

`attendance-thank-you.blade.php`, `new-admin-credentials.blade.php`, and
`mail-setting-test.blade.php` currently duplicate the exact same header
markup (logo, name, tagline, colored background) byte-for-byte. Extracting it
now avoids updating the same dynamic branding logic in three places (and
keeps them in sync if branding changes again later):

`resources/views/components/mail/header.blade.php`:

```blade
@props(['clubSetting'])
<td style="background-color:{{ $clubSetting->primary_color }}; padding:24px; text-align:center;">
    <img src="{{ $clubSetting->logoUrl() }}" alt="{{ $clubSetting->name }}" width="140" style="display:block; height:auto; width:140px; margin:0 auto;">
    <p style="margin:16px 0 0; color:#ffffff; font-size:16px; font-weight:bold;">{{ $clubSetting->name }}</p>
    @if ($clubSetting->tagline)
        <p style="margin:4px 0 0; color:#F2B94D; font-size:11px; letter-spacing:0.05em; text-transform:uppercase;">{{ $clubSetting->tagline }}</p>
    @endif
</td>
```

`resources/views/components/mail/footer.blade.php`:

```blade
@props(['clubSetting'])
@if ($clubSetting->hasContactInfo() || $clubSetting->hasSocialLinks())
    <tr>
        <td style="padding:16px 24px; text-align:center; font-size:11px; color:#6B6558; border-top:1px solid #EDEAE2;">
            @if ($clubSetting->address) {{ $clubSetting->address }}<br> @endif
            @if ($clubSetting->phone) {{ $clubSetting->phone }} @endif
            @if ($clubSetting->phone && $clubSetting->email) &middot; @endif
            @if ($clubSetting->email) {{ $clubSetting->email }} @endif
            @if ($clubSetting->hasSocialLinks())
                <br>
                @if ($clubSetting->website) <a href="{{ $clubSetting->website }}" style="color:#6B6558;">{{ $clubSetting->website }}</a> @endif
                @if ($clubSetting->facebook_url) <a href="{{ $clubSetting->facebook_url }}" style="color:#6B6558;">Facebook</a> @endif
                @if ($clubSetting->instagram_url) <a href="{{ $clubSetting->instagram_url }}" style="color:#6B6558;">Instagram</a> @endif
            @endif
        </td>
    </tr>
@endif
```

Each of the three mail Mailables passes `clubSetting: ClubSetting::current()`
into its view data; each template swaps its inline header markup for
`<x-mail.header :club-setting="$clubSetting" />` and adds
`<x-mail.footer :club-setting="$clubSetting" />` as the last row of the outer
table.

### 4. Other branded views

- **`components/layouts/admin.blade.php`**: both logo badges (`md:hidden`
  topbar and desktop sidebar) read `ClubSetting::current()`; the gradient
  `linear-gradient(135deg,#17A8E5_0%,#0B73C5_55%,#0A5CA6_100%)` becomes an
  inline `style="background: linear-gradient(135deg, {{ $clubSetting->secondary_color }} 0%, {{ $clubSetting->primary_color }} 100%)"`
  (two-stop gradient, since we only store two colors); `RC Cotonou Ife` text
  becomes `{{ $clubSetting->name }}`. The `@props(['title' => ...])` default
  in this file and in `layouts/app.blade.php` reads
  `ClubSetting::current()?->name` for its fallback title suffix.
- **`attendance/show.blade.php`**: header badge uses the same gradient
  treatment; name/tagline become dynamic. The duplicated name paragraph
  (lines 8 and 10 both render the same text) is collapsed to one — tagline
  keeps its own line.
- **`admin/sessions/pdf.blade.php`**: the subtitle line
  (`RC Cotonou Ife, District 9103`) becomes
  `{{ $clubSetting->name }}{{ $clubSetting->tagline ? ', '.$clubSetting->tagline : '' }}`;
  a footer block is added after the last group table, showing address/phone/
  email/website/socials (same "only render what's set" pattern as the mail
  footer, plain inline CSS matching the rest of the PDF template).
  `MeetingSessionController::exportPdf` passes `clubSetting: ClubSetting::current()`
  into the view.

Colors are scoped narrowly: `primary_color`/`secondary_color` only replace
the blue gradient/header-background hex values identified above. The app's
own design tokens (`navy`/`cream`/`gold`/etc. in `resources/css/app.css`) are
the app's fixed visual language, not the club's brand, and are out of scope.

### 5. Files touched

- `database/migrations/2026_07_22_xxxxxx_create_club_settings_table.php` (new)
- `database/migrations/2026_07_22_xxxxxx_seed_club_settings.php` (new, data-only)
- `app/Models/ClubSetting.php` (new)
- `app/Http/Controllers/Admin/ClubSettingController.php` (new)
- `app/Http/Requests/UpdateClubSettingRequest.php` (new)
- `resources/views/admin/club-settings/edit.blade.php` (new)
- `resources/views/components/mail/header.blade.php` (new)
- `resources/views/components/mail/footer.blade.php` (new)
- `resources/views/components/layouts/admin.blade.php` (dynamic logo/name/gradient, nav entry)
- `resources/views/components/layouts/app.blade.php` (dynamic title fallback)
- `resources/views/attendance/show.blade.php` (dynamic header, drop duplicate name line)
- `resources/views/admin/sessions/pdf.blade.php` (dynamic subtitle, new footer)
- `resources/views/mail/attendance-thank-you.blade.php` (use shared components)
- `resources/views/mail/new-admin-credentials.blade.php` (use shared components)
- `resources/views/mail/mail-setting-test.blade.php` (use shared components)
- `app/Mail/AttendanceThankYouMail.php`, `app/Mail/NewAdminCredentialsMail.php`, `app/Mail/MailSettingTestMail.php` (pass `clubSetting` into view data)
- `app/Http/Controllers/Admin/MeetingSessionController.php` (pass `clubSetting` into the PDF view)
- `routes/web.php` (two new routes)

## Testing

New `tests/Feature/Admin/ClubSettingManagementTest.php`, mirroring
`MailSettingManagementTest`:

- Unauthenticated request to `admin.club-settings.edit`/`update` redirects to login.
- Submitting valid data creates the single `ClubSetting` row; submitting
  again updates it in place (no duplicate rows).
- Invalid hex color / invalid URL / oversized or wrong-mimetype logo are
  rejected with validation errors.
- Uploading a new logo deletes the previously stored file from the `public`
  disk (`Storage::fake('public')` + `assertMissing`/`assertExists`).

Feature-level assertions that consuming views actually reflect the
configuration:

- `attendance.show` (public check-in) renders the configured name/tagline
  instead of hardcoded text once a `ClubSetting` exists.
- `admin.sessions.export-pdf` output contains the configured name and, when
  contact fields are set, the footer text (PDF text extraction, matching
  however existing PDF tests in this suite assert on Dompdf output).
- `AttendanceThankYouMail` rendered view contains the configured name and,
  when contact fields are set, the footer links (`Mail::fake()` +
  asserting on the rendered HTML, or a Mailable render test — matching
  existing conventions in `tests/Feature/*Mail*` if any exist).

Run `vendor/bin/pint --dirty --format agent` before finishing.

## Out of scope

- Multi-tenancy: this remains one global `ClubSetting` row for the whole
  app, not a per-tenant/organisation record. Turning this into a true SaaS
  (separate orgs, isolated data, billing, onboarding) is a distinct, much
  larger project to be scoped separately.
- Full theming (arbitrary number of colors, font choice, layout customization):
  only `primary_color`/`secondary_color` are configurable, matching the two
  colors actually used in the current gradient.
- Role/permission gating on who can edit club settings — every admin user
  can, consistent with every other `/admin/*` route today.
- Editing hardcoded branding in places not listed above (e.g. the app's
  `<title>` browser tab default beyond the fallback described in section 4,
  favicon, `.env` `APP_NAME`) — not requested and not part of the four
  surfaces (admin layout, public check-in, PDF export, emails) the user
  asked for.
