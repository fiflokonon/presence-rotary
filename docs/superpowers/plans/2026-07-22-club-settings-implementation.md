# Configurable Club Identity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace every hardcoded "RC Cotonou Ife" branding value (name, tagline, logo, brand colors, contact info) across the admin layout, public check-in page, PDF export, and email templates with values read from a new admin-editable `ClubSetting` singleton.

**Architecture:** One singleton model `App\Models\ClubSetting` (table `club_settings`, one row), following the exact `::current()` pattern already used by `App\Models\CheckinSetting` and `App\Models\MailSetting`. A migration seeds the single row with today's real hardcoded values so behavior is unchanged until an admin edits the new "Identité du club" settings page. Every consuming Blade view calls `\App\Models\ClubSetting::current()` directly (matching how `CheckinSetting::guestOptionEnabled()` is called directly wherever needed) rather than threading the value through every controller — this keeps existing tests that render views directly (e.g. `AttendancePdfExportTest`) working unmodified.

**Tech Stack:** Laravel 13, PHP 8.4, Blade + Tailwind v4 + Alpine.js, Pest v4, SQLite (tests), Dompdf (`barryvdh/laravel-dompdf`).

## Global Constraints

- PHP 8.4 / Laravel 13 / Pest v4 — this repo, no new dependencies.
- Follow the existing singleton-settings pattern (`CheckinSetting`, `MailSetting`): `::current(): ?self`, controller does get-or-create on `update()`, **no factory** (matches both siblings).
- Always use curly braces for control structures; explicit return types and param type hints on all new PHP methods.
- Run `vendor/bin/pint --dirty --format agent` after any PHP file changes, before considering a task done.
- Run tests with `php artisan test --compact --filter=<TestName>` per task, and the full suite (`php artisan test --compact`) at the end.
- Do not touch the app's own design tokens in `resources/css/app.css` (`navy`/`cream`/`gold`/etc.) — those are the app's fixed visual language, out of scope per the spec.
- Do not create Unit tests — this codebase only uses `tests/Feature` (see `tests/Pest.php`); all new tests go under `tests/Feature`.

---

## File Structure

| File | Responsibility |
|---|---|
| `database/migrations/2026_07_22_120000_create_club_settings_table.php` | Creates `club_settings` table |
| `database/migrations/2026_07_22_120001_seed_club_settings_table.php` | Inserts the single seed row with today's real branding values |
| `app/Models/ClubSetting.php` | Singleton model: `current()`, `logoUrl()`, `hasContactInfo()`, `hasSocialLinks()` |
| `app/Http/Requests/UpdateClubSettingRequest.php` | Validation rules for the settings form |
| `app/Http/Controllers/Admin/ClubSettingController.php` | `edit()` / `update()` — logo upload/replace logic lives here |
| `resources/views/admin/club-settings/edit.blade.php` | Settings form |
| `resources/views/components/layouts/admin.blade.php` | Admin sidebar/topbar logo, name, gradient — made dynamic; new nav entry |
| `resources/views/components/layouts/app.blade.php` | Dead-code title fallback made dynamic (never actually hit today — both callers pass an explicit title) |
| `resources/views/attendance/show.blade.php` | Public check-in header made dynamic; drops a pre-existing duplicated name line |
| `resources/views/admin/sessions/pdf.blade.php` | Dynamic subtitle; new contact-info footer |
| `resources/views/components/mail/header.blade.php` | Shared branded header `<td>` for the 3 email templates |
| `resources/views/components/mail/footer.blade.php` | Shared contact-info/social-links footer `<tr>` for the 3 email templates |
| `resources/views/mail/attendance-thank-you.blade.php`, `mail/new-admin-credentials.blade.php`, `mail/mail-setting-test.blade.php` | Use the two shared components instead of duplicated markup |
| `app/Mail/AttendanceThankYouMail.php`, `NewAdminCredentialsMail.php`, `MailSettingTestMail.php` | Dynamic subject line |
| `routes/web.php` | Two new routes |
| `docker/entrypoint.sh` | Adds `php artisan storage:link` so uploaded logos are served in production |

---

### Task 1: `ClubSetting` model, migrations, and seed data

**Files:**
- Create: `database/migrations/2026_07_22_120000_create_club_settings_table.php`
- Create: `database/migrations/2026_07_22_120001_seed_club_settings_table.php`
- Create: `app/Models/ClubSetting.php`
- Test: `tests/Feature/ClubSettingTest.php`

**Interfaces:**
- Produces: `App\Models\ClubSetting::current(): ?self`, `->logoUrl(): string`, `->hasContactInfo(): bool`, `->hasSocialLinks(): bool`, and fillable columns `name, tagline, logo_path, primary_color, secondary_color, address, phone, email, website, facebook_url, instagram_url`. Every later task depends on these exact names.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\ClubSetting;

it('seeds a single club setting row with the current branding defaults', function () {
    $clubSetting = ClubSetting::current();

    expect($clubSetting)->not->toBeNull()
        ->and($clubSetting->name)->toBe('RC Cotonou Ife')
        ->and($clubSetting->tagline)->toBe('District 9103')
        ->and($clubSetting->logo_path)->toBeNull()
        ->and($clubSetting->logoUrl())->toContain('ife-logo.png')
        ->and($clubSetting->primary_color)->toBe('#0B73C5')
        ->and($clubSetting->secondary_color)->toBe('#17A8E5')
        ->and($clubSetting->hasContactInfo())->toBeFalse()
        ->and($clubSetting->hasSocialLinks())->toBeFalse();
});
```

Save this as `tests/Feature/ClubSettingTest.php`.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=ClubSettingTest`
Expected: FAIL — `Class "App\Models\ClubSetting" not found`.

- [ ] **Step 3: Create the schema migration**

```bash
php artisan make:migration create_club_settings_table --no-interaction
```

Replace the generated file's contents (rename it to
`2026_07_22_120000_create_club_settings_table.php` if the generated timestamp
sorts differently — it must run before the seed migration below):

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('club_settings');
    }
};
```

- [ ] **Step 4: Create the seed migration**

```bash
php artisan make:migration seed_club_settings_table --no-interaction
```

Rename it to `2026_07_22_120001_seed_club_settings_table.php` (must sort after
the schema migration) and replace its contents:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('club_settings')->insert([
            'name' => 'RC Cotonou Ife',
            'tagline' => 'District 9103',
            'logo_path' => null,
            'primary_color' => '#0B73C5',
            'secondary_color' => '#17A8E5',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('club_settings')->where('name', 'RC Cotonou Ife')->delete();
    }
};
```

- [ ] **Step 5: Create the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

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

    public function hasContactInfo(): bool
    {
        return $this->address !== null || $this->phone !== null || $this->email !== null;
    }

    public function hasSocialLinks(): bool
    {
        return $this->website !== null || $this->facebook_url !== null || $this->instagram_url !== null;
    }
}
```

Save as `app/Models/ClubSetting.php`.

- [ ] **Step 6: Apply the migrations to the local dev database**

Run: `php artisan migrate`
Expected: both new migrations run and report `DONE`.

- [ ] **Step 7: Run the test to verify it passes**

Run: `php artisan test --compact --filter=ClubSettingTest`
Expected: PASS (1 test)

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_07_22_120000_create_club_settings_table.php \
        database/migrations/2026_07_22_120001_seed_club_settings_table.php \
        app/Models/ClubSetting.php \
        tests/Feature/ClubSettingTest.php
git commit -m "feat: add ClubSetting singleton with seeded branding defaults"
```

---

### Task 2: Admin settings page (request, controller, routes, nav, view, logo upload)

**Files:**
- Create: `app/Http/Requests/UpdateClubSettingRequest.php`
- Create: `app/Http/Controllers/Admin/ClubSettingController.php`
- Create: `resources/views/admin/club-settings/edit.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/components/layouts/admin.blade.php:57-64` (nav entry only — the branding edits to this file happen in Task 3)
- Modify: `docker/entrypoint.sh`
- Test: `tests/Feature/Admin/ClubSettingManagementTest.php`

**Interfaces:**
- Consumes: `App\Models\ClubSetting::current()`, `->logo_path`, `->update()`, `::create()` from Task 1.
- Produces: routes `admin.club-settings.edit` (GET), `admin.club-settings.update` (PUT). Later tasks don't depend on this controller directly, but the nav entry and route names are referenced by nothing else in this plan.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Models\ClubSetting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('redirects guests to login on edit', function () {
    $this->get(route('admin.club-settings.edit'))->assertRedirect(route('admin.login'));
});

it('redirects guests to login on update', function () {
    $this->put(route('admin.club-settings.update'), [])->assertRedirect(route('admin.login'));
});

it('shows the form pre-filled with the seeded club identity', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.club-settings.edit'))
        ->assertOk()
        ->assertSee('RC Cotonou Ife')
        ->assertSee('District 9103');
});

it('updates the club settings row', function () {
    $this->actingAs(User::factory()->create())
        ->put(route('admin.club-settings.update'), [
            'name' => 'RC Nouveau Nom',
            'tagline' => 'District 9999',
            'primary_color' => '#123456',
            'secondary_color' => '#abcdef',
            'address' => '10 rue du Club',
            'phone' => '+229 00 00 00 00',
            'email' => 'contact@club.test',
            'website' => 'https://club.test',
            'facebook_url' => 'https://facebook.com/club',
            'instagram_url' => 'https://instagram.com/club',
        ])->assertRedirect(route('admin.club-settings.edit'));

    $clubSetting = ClubSetting::current();

    expect($clubSetting->name)->toBe('RC Nouveau Nom')
        ->and($clubSetting->tagline)->toBe('District 9999')
        ->and($clubSetting->primary_color)->toBe('#123456')
        ->and($clubSetting->address)->toBe('10 rue du Club')
        ->and($clubSetting->website)->toBe('https://club.test');
});

it('rejects an invalid payload', function () {
    $this->actingAs(User::factory()->create())
        ->put(route('admin.club-settings.update'), [
            'name' => '',
            'primary_color' => 'not-a-color',
            'secondary_color' => 'not-a-color',
            'email' => 'not-an-email',
            'website' => 'not-a-url',
        ])->assertSessionHasErrors(['name', 'primary_color', 'secondary_color', 'email', 'website']);
});

it('uploads and stores a new logo, replacing the previous file', function () {
    Storage::fake('public');

    $clubSetting = ClubSetting::current();
    $clubSetting->update(['logo_path' => 'club/old-logo.png']);
    Storage::disk('public')->put('club/old-logo.png', 'fake-image-content');

    $this->actingAs(User::factory()->create())
        ->put(route('admin.club-settings.update'), [
            'name' => $clubSetting->name,
            'primary_color' => $clubSetting->primary_color,
            'secondary_color' => $clubSetting->secondary_color,
            'logo' => UploadedFile::fake()->image('logo.png'),
        ])->assertRedirect(route('admin.club-settings.edit'));

    Storage::disk('public')->assertMissing('club/old-logo.png');

    $newPath = ClubSetting::current()->logo_path;

    expect($newPath)->not->toBeNull();
    Storage::disk('public')->assertExists($newPath);
});
```

Save as `tests/Feature/Admin/ClubSettingManagementTest.php`.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=ClubSettingManagementTest`
Expected: FAIL — route `admin.club-settings.edit` not defined.

- [ ] **Step 3: Create the form request**

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateClubSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
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
}
```

Save as `app/Http/Requests/UpdateClubSettingRequest.php`.

- [ ] **Step 4: Create the controller**

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateClubSettingRequest;
use App\Models\ClubSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class ClubSettingController extends Controller
{
    public function edit(): View
    {
        return view('admin.club-settings.edit', [
            'clubSetting' => ClubSetting::current(),
        ]);
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

        if ($clubSetting !== null) {
            $clubSetting->update($data);
        } else {
            ClubSetting::create($data);
        }

        return redirect()->route('admin.club-settings.edit')->with('status', 'Identité du club enregistrée.');
    }
}
```

Save as `app/Http/Controllers/Admin/ClubSettingController.php`.

- [ ] **Step 5: Create the edit view**

```blade
<x-layouts.admin title="Identité du club — Administration">
    <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)] md:p-8">
        <h1 class="font-display text-xl font-extrabold text-navy">Identité du club</h1>
        <p class="mt-1 text-sm text-muted">
            Ces informations apparaissent sur le formulaire de présence, l'export PDF et les emails envoyés.
        </p>

        @if (session('status'))
            <div class="mt-4 rounded-lg bg-success-bg px-4 py-3 text-sm text-success">
                {{ session('status') }}
            </div>
        @endif

        <form method="POST" action="{{ route('admin.club-settings.update') }}" enctype="multipart/form-data" class="mt-4 flex max-w-md flex-col gap-3">
            @csrf
            @method('PUT')

            <div class="flex flex-col gap-1.5">
                <label for="name" class="text-sm font-semibold">Nom</label>
                <input type="text" id="name" name="name" value="{{ old('name', $clubSetting?->name) }}" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="tagline" class="text-sm font-semibold">Sous-titre</label>
                <input type="text" id="tagline" name="tagline" value="{{ old('tagline', $clubSetting?->tagline) }}"
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="logo" class="text-sm font-semibold">Logo</label>
                @if ($clubSetting)
                    <img src="{{ $clubSetting->logoUrl() }}" alt="Logo actuel" class="h-16 w-auto object-contain">
                @endif
                <input type="file" id="logo" name="logo" accept="image/png,image/jpeg,image/svg+xml" class="text-sm">
            </div>

            <div class="flex flex-col gap-1.5" x-data="{ color: '{{ old('primary_color', $clubSetting?->primary_color ?? '#0B73C5') }}' }">
                <label for="primary_color" class="text-sm font-semibold">Couleur primaire</label>
                <div class="flex items-center gap-2">
                    <input type="color" x-model="color" class="h-9 w-12 cursor-pointer rounded border border-border">
                    <input type="text" id="primary_color" name="primary_color" x-model="color" required
                        class="w-28 rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
                </div>
            </div>

            <div class="flex flex-col gap-1.5" x-data="{ color: '{{ old('secondary_color', $clubSetting?->secondary_color ?? '#17A8E5') }}' }">
                <label for="secondary_color" class="text-sm font-semibold">Couleur secondaire</label>
                <div class="flex items-center gap-2">
                    <input type="color" x-model="color" class="h-9 w-12 cursor-pointer rounded border border-border">
                    <input type="text" id="secondary_color" name="secondary_color" x-model="color" required
                        class="w-28 rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
                </div>
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="address" class="text-sm font-semibold">Adresse</label>
                <input type="text" id="address" name="address" value="{{ old('address', $clubSetting?->address) }}"
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="phone" class="text-sm font-semibold">Téléphone</label>
                <input type="text" id="phone" name="phone" value="{{ old('phone', $clubSetting?->phone) }}"
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="email" class="text-sm font-semibold">Email</label>
                <input type="email" id="email" name="email" value="{{ old('email', $clubSetting?->email) }}"
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="website" class="text-sm font-semibold">Site web</label>
                <input type="url" id="website" name="website" value="{{ old('website', $clubSetting?->website) }}"
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="facebook_url" class="text-sm font-semibold">Facebook</label>
                <input type="url" id="facebook_url" name="facebook_url" value="{{ old('facebook_url', $clubSetting?->facebook_url) }}"
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="instagram_url" class="text-sm font-semibold">Instagram</label>
                <input type="url" id="instagram_url" name="instagram_url" value="{{ old('instagram_url', $clubSetting?->instagram_url) }}"
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>

            <button type="submit"
                class="mt-2 cursor-pointer self-start rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                Enregistrer
            </button>
        </form>

        @if ($errors->any())
            <div class="mt-4 rounded-lg bg-error-bg px-4 py-3 text-sm text-error">
                {{ $errors->first() }}
            </div>
        @endif
    </div>
</x-layouts.admin>
```

Save as `resources/views/admin/club-settings/edit.blade.php`.

- [ ] **Step 6: Add the routes**

In `routes/web.php`, add the import alphabetically after `CheckinSettingController`:

```php
use App\Http\Controllers\Admin\CheckinSettingController;
use App\Http\Controllers\Admin\ClubSettingController;
use App\Http\Controllers\Admin\MailSettingController;
```

Then, at the end of the `auth` middleware group, right after the two
`checkin-settings` routes:

```php
        Route::get('checkin-settings', [CheckinSettingController::class, 'edit'])->name('checkin-settings.edit');
        Route::put('checkin-settings', [CheckinSettingController::class, 'update'])->name('checkin-settings.update');
        Route::get('club-settings', [ClubSettingController::class, 'edit'])->name('club-settings.edit');
        Route::put('club-settings', [ClubSettingController::class, 'update'])->name('club-settings.update');
    });
});
```

- [ ] **Step 7: Add the sidebar nav entry**

In `resources/views/components/layouts/admin.blade.php`, insert a new link
right before the existing "Paramètres mail" link:

```blade
                <a href="{{ route('admin.club-settings.edit') }}" @click="close()"
                    class="cursor-pointer rounded-lg px-3 py-2 text-sm font-semibold {{ request()->routeIs('admin.club-settings.*') ? 'bg-navy text-white' : 'text-navy hover:bg-cream' }}">
                    Identité du club
                </a>
                <a href="{{ route('admin.mail-settings.edit') }}" @click="close()"
                    class="cursor-pointer rounded-lg px-3 py-2 text-sm font-semibold {{ request()->routeIs('admin.mail-settings.*') ? 'bg-navy text-white' : 'text-navy hover:bg-cream' }}">
                    Paramètres mail
                </a>
```

- [ ] **Step 8: Make uploaded logos servable — local dev**

Run: `php artisan storage:link`
Expected: `The [public/storage] link has been connected to [storage/app/public].`

- [ ] **Step 9: Make uploaded logos servable — production container**

In `docker/entrypoint.sh`, add the link step after seeding:

```sh
php artisan migrate --force
php artisan db:seed --force
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

- [ ] **Step 10: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=ClubSettingManagementTest`
Expected: PASS (6 tests)

- [ ] **Step 11: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Requests/UpdateClubSettingRequest.php \
        app/Http/Controllers/Admin/ClubSettingController.php \
        resources/views/admin/club-settings/edit.blade.php \
        resources/views/components/layouts/admin.blade.php \
        routes/web.php \
        docker/entrypoint.sh \
        tests/Feature/Admin/ClubSettingManagementTest.php
git commit -m "feat: add admin page to configure the club identity"
```

---

### Task 3: Dynamic branding in the admin layout

**Files:**
- Modify: `resources/views/components/layouts/admin.blade.php`
- Test: `tests/Feature/Admin/AdminLayoutBrandingTest.php`

**Interfaces:**
- Consumes: `ClubSetting::current()`, `->name`, `->logoUrl()`, `->primary_color`, `->secondary_color` from Task 1.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\ClubSetting;
use App\Models\User;

it('renders the configured club name and brand colors in the admin layout', function () {
    ClubSetting::current()->update([
        'name' => 'Club Admin Test',
        'primary_color' => '#111111',
        'secondary_color' => '#222222',
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.index'))
        ->assertOk()
        ->assertSee('Club Admin Test')
        ->assertSee('#111111', false)
        ->assertSee('#222222', false);
});
```

Save as `tests/Feature/Admin/AdminLayoutBrandingTest.php`.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=AdminLayoutBrandingTest`
Expected: FAIL — sees "RC Cotonou Ife" instead of "Club Admin Test", hardcoded hex colors instead of the configured ones.

- [ ] **Step 3: Make the layout dynamic**

In `resources/views/components/layouts/admin.blade.php`, replace the first line:

```blade
@props(['title' => 'Administration — RC Cotonou Ife'])
```

with:

```blade
@props(['title' => 'Administration — '.(\App\Models\ClubSetting::current()?->name ?? 'RC Cotonou Ife')])
@php $clubSetting = \App\Models\ClubSetting::current(); @endphp
```

Replace the mobile topbar logo block:

```blade
        <div class="flex items-center justify-between border-b border-divider bg-white px-4 py-3 md:hidden">
            <div class="flex items-center gap-2">
                <div class="inline-flex items-center justify-center rounded-lg bg-[linear-gradient(135deg,#17A8E5_0%,#0B73C5_55%,#0A5CA6_100%)] p-1 shadow-[0_6px_14px_rgba(10,92,166,.3)]">
                    <img src="{{ asset('assets/ife-logo.png') }}" alt="RC Cotonou Ife" class="h-8 w-8 object-contain">
                </div>
                <span class="text-sm font-semibold text-navy">RC Cotonou Ife</span>
            </div>
```

with:

```blade
        <div class="flex items-center justify-between border-b border-divider bg-white px-4 py-3 md:hidden">
            <div class="flex items-center gap-2">
                <div class="inline-flex items-center justify-center rounded-lg p-1 shadow-[0_6px_14px_rgba(10,92,166,.3)]" style="background: linear-gradient(135deg, {{ $clubSetting->secondary_color }} 0%, {{ $clubSetting->primary_color }} 100%);">
                    <img src="{{ $clubSetting->logoUrl() }}" alt="{{ $clubSetting->name }}" class="h-8 w-8 object-contain">
                </div>
                <span class="text-sm font-semibold text-navy">{{ $clubSetting->name }}</span>
            </div>
```

Replace the desktop sidebar logo block:

```blade
            <div class="hidden items-center gap-2 px-2 md:flex">
                <div class="inline-flex items-center justify-center rounded-lg bg-[linear-gradient(135deg,#17A8E5_0%,#0B73C5_55%,#0A5CA6_100%)] p-1 shadow-[0_6px_14px_rgba(10,92,166,.3)]">
                    <img src="{{ asset('assets/ife-logo.png') }}" alt="RC Cotonou Ife" class="h-10 w-10 object-contain">
                </div>
                <span class="text-sm font-semibold text-navy">RC Cotonou Ife</span>
            </div>
```

with:

```blade
            <div class="hidden items-center gap-2 px-2 md:flex">
                <div class="inline-flex items-center justify-center rounded-lg p-1 shadow-[0_6px_14px_rgba(10,92,166,.3)]" style="background: linear-gradient(135deg, {{ $clubSetting->secondary_color }} 0%, {{ $clubSetting->primary_color }} 100%);">
                    <img src="{{ $clubSetting->logoUrl() }}" alt="{{ $clubSetting->name }}" class="h-10 w-10 object-contain">
                </div>
                <span class="text-sm font-semibold text-navy">{{ $clubSetting->name }}</span>
            </div>
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact --filter=AdminLayoutBrandingTest`
Expected: PASS

- [ ] **Step 5: Run the full existing admin test suite to check for regressions**

Run: `php artisan test --compact --filter=Admin`
Expected: PASS (no existing admin test asserts on the old hardcoded gradient/logo markup)

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/components/layouts/admin.blade.php tests/Feature/Admin/AdminLayoutBrandingTest.php
git commit -m "feat: make the admin layout logo, name and colors configurable"
```

---

### Task 4: Dynamic branding on the public check-in page

**Files:**
- Modify: `resources/views/attendance/show.blade.php`
- Modify: `resources/views/components/layouts/app.blade.php`
- Test: `tests/Feature/AttendanceCheckInBrandingTest.php`

**Interfaces:**
- Consumes: `ClubSetting::current()`, `->name`, `->tagline`, `->logoUrl()`, `->primary_color`, `->secondary_color` from Task 1.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\ClubSetting;

it('renders the configured club name, tagline and colors on the check-in page', function () {
    ClubSetting::current()->update([
        'name' => 'Club Test',
        'tagline' => 'Zone 42',
        'primary_color' => '#123456',
        'secondary_color' => '#abcdef',
    ]);

    $this->get(route('attendance.show'))
        ->assertOk()
        ->assertSee('Club Test')
        ->assertSee('Zone 42')
        ->assertSee('#123456', false)
        ->assertSee('#abcdef', false);
});
```

Save as `tests/Feature/AttendanceCheckInBrandingTest.php`.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=AttendanceCheckInBrandingTest`
Expected: FAIL

- [ ] **Step 3: Make the check-in header dynamic**

In `resources/views/attendance/show.blade.php`, replace the first line:

```blade
<x-layouts.app :title="'Liste de présence' . ($meetingSession ? ' — ' . $meetingSession->title : '')">
```

with:

```blade
@php $clubSetting = \App\Models\ClubSetting::current(); @endphp
<x-layouts.app :title="'Liste de présence' . ($meetingSession ? ' — ' . $meetingSession->title : '')">
```

Then replace the header block (this also fixes a pre-existing bug where the
club name was rendered twice — once at line 8 and again, identically, at
line 10):

```blade
            <div class="flex flex-col items-center bg-[#12213D] px-6 pb-[18px] pt-[22px] text-center">
                <div class="inline-flex items-center justify-center rounded-xl bg-[linear-gradient(135deg,#17A8E5_0%,#0B73C5_55%,#0A5CA6_100%)] px-4 py-2 shadow-[0_8px_20px_rgba(10,92,166,.35)]">
                    <img src="{{ asset('assets/ife-logo.png') }}" alt="RC Cotonou Ife" class="h-12 w-auto object-contain">
                </div>
                <p class="mt-3 font-display text-lg font-extrabold text-white">RC Cotonou Ife</p>
                <p class="mt-2 text-[10px] font-semibold uppercase tracking-wide text-[#F2B94D]">District 9103</p>
                <p class="font-display text-[15px] font-bold text-white">RC Cotonou Ife</p>
            </div>
```

with:

```blade
            <div class="flex flex-col items-center bg-[#12213D] px-6 pb-[18px] pt-[22px] text-center">
                <div class="inline-flex items-center justify-center rounded-xl px-4 py-2 shadow-[0_8px_20px_rgba(10,92,166,.35)]" style="background: linear-gradient(135deg, {{ $clubSetting->secondary_color }} 0%, {{ $clubSetting->primary_color }} 100%);">
                    <img src="{{ $clubSetting->logoUrl() }}" alt="{{ $clubSetting->name }}" class="h-12 w-auto object-contain">
                </div>
                <p class="mt-3 font-display text-lg font-extrabold text-white">{{ $clubSetting->name }}</p>
                @if ($clubSetting->tagline)
                    <p class="mt-2 text-[10px] font-semibold uppercase tracking-wide text-[#F2B94D]">{{ $clubSetting->tagline }}</p>
                @endif
            </div>
```

- [ ] **Step 4: Clean up the now-dead hardcoded fallback in the shared app layout**

`resources/views/components/layouts/app.blade.php`'s `title` prop default is
never actually used (both of its callers, this page and the admin login
page, always pass an explicit `title`), but it still hardcodes the old name.
Replace:

```blade
@props(['title' => 'Liste de présence — RC Cotonou Ife'])
```

with:

```blade
@props(['title' => 'Liste de présence — '.(\App\Models\ClubSetting::current()?->name ?? 'RC Cotonou Ife')])
```

No test for this line specifically — it's unreachable today, this just
avoids leaving a stale hardcoded name behind.

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --compact --filter=AttendanceCheckInBrandingTest`
Expected: PASS

- [ ] **Step 6: Run the full attendance-form suite to check for regressions**

Run: `php artisan test --compact --filter=AttendanceFormTest`
Run: `php artisan test --compact --filter=AttendanceMemberCheckInTest`
Expected: both PASS unchanged (they assert on `Member`/`Attendance` `club`
field values, unrelated to this page's branding markup)

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/attendance/show.blade.php \
        resources/views/components/layouts/app.blade.php \
        tests/Feature/AttendanceCheckInBrandingTest.php
git commit -m "feat: make the public check-in page branding configurable"
```

---

### Task 5: Dynamic branding and contact footer in the PDF export

**Files:**
- Modify: `resources/views/admin/sessions/pdf.blade.php`
- Modify: `tests/Feature/Admin/AttendancePdfExportTest.php`

**Interfaces:**
- Consumes: `ClubSetting::current()`, `->name`, `->tagline`, `->hasContactInfo()`, `->hasSocialLinks()`, `->address`, `->phone`, `->email`, `->website`, `->facebook_url`, `->instagram_url` from Task 1.

- [ ] **Step 1: Write the failing tests**

In `tests/Feature/Admin/AttendancePdfExportTest.php`, replace the `use`
block at the top:

```php
use App\Models\Attendance;
use App\Models\MeetingSession;
use App\Models\Title;
use App\Models\User;
```

with:

```php
use App\Models\Attendance;
use App\Models\ClubSetting;
use App\Models\MeetingSession;
use App\Models\Title;
use App\Models\User;
```

Then append these three tests to the end of the file:

```php
it('shows the configured club name and tagline in the PDF subtitle', function () {
    ClubSetting::current()->update(['name' => 'Club PDF Test', 'tagline' => 'Zone PDF']);

    $meetingSession = MeetingSession::factory()->create();

    $html = view('admin.sessions.pdf', [
        'meetingSession' => $meetingSession,
        'attendances' => $meetingSession->attendances()->with(['title', 'position'])->get(),
        'groupLabels' => [Title::OTHER_ORGANIZATIONS_LABEL],
    ])->render();

    expect($html)->toContain('Club PDF Test, Zone PDF');
});

it('includes the club contact info in the PDF footer when configured', function () {
    ClubSetting::current()->update([
        'address' => '12 avenue du Club',
        'phone' => '+229 22 22 22 22',
        'website' => 'https://club.test',
    ]);

    $meetingSession = MeetingSession::factory()->create();

    $html = view('admin.sessions.pdf', [
        'meetingSession' => $meetingSession,
        'attendances' => $meetingSession->attendances()->with(['title', 'position'])->get(),
        'groupLabels' => [Title::OTHER_ORGANIZATIONS_LABEL],
    ])->render();

    expect($html)->toContain('12 avenue du Club')
        ->and($html)->toContain('+229 22 22 22 22')
        ->and($html)->toContain('https://club.test');
});

it('omits the footer block when no contact info is configured', function () {
    $meetingSession = MeetingSession::factory()->create();

    $html = view('admin.sessions.pdf', [
        'meetingSession' => $meetingSession,
        'attendances' => $meetingSession->attendances()->with(['title', 'position'])->get(),
        'groupLabels' => [Title::OTHER_ORGANIZATIONS_LABEL],
    ])->render();

    expect($html)->not->toContain('class="footer"');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=AttendancePdfExportTest`
Expected: the 3 new tests FAIL (subtitle still says "RC Cotonou Ife, District
9103"; no footer markup exists yet)

- [ ] **Step 3: Update the PDF template**

Replace the full contents of `resources/views/admin/sessions/pdf.blade.php`:

```blade
@php
    $clubSetting = \App\Models\ClubSetting::current();
@endphp
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; color: #12213D; }
        h1 { font-size: 18px; margin-bottom: 2px; }
        p.subtitle { color: #6B6558; margin-top: 0; }
        h2 { font-size: 13px; margin-top: 18px; margin-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 4px 6px; border-bottom: 1px solid #EDEAE2; font-size: 11px; }
        .footer { margin-top: 18px; font-size: 10px; color: #6B6558; border-top: 1px solid #EDEAE2; padding-top: 8px; }
    </style>
</head>
<body>
    <h1>{{ $meetingSession->title }}</h1>
    <p class="subtitle">{{ $meetingSession->date->translatedFormat('d F Y') }} — {{ $clubSetting->name }}{{ $clubSetting->tagline ? ', '.$clubSetting->tagline : '' }}</p>

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

    @if ($clubSetting->hasContactInfo() || $clubSetting->hasSocialLinks())
        <div class="footer">
            @if ($clubSetting->address){{ $clubSetting->address }}@endif
            @if ($clubSetting->address && ($clubSetting->phone || $clubSetting->email)) &middot; @endif
            {{ collect([$clubSetting->phone, $clubSetting->email])->filter()->join(' · ') }}
            @if ($clubSetting->hasSocialLinks())
                <br>
                {{ collect([$clubSetting->website, $clubSetting->facebook_url, $clubSetting->instagram_url])->filter()->join(' · ') }}
            @endif
        </div>
    @endif
</body>
</html>
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=AttendancePdfExportTest`
Expected: PASS (all tests in the file, old and new)

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/admin/sessions/pdf.blade.php tests/Feature/Admin/AttendancePdfExportTest.php
git commit -m "feat: make the PDF export header and contact footer configurable"
```

---

### Task 6: Shared mail components, dynamic email branding, dynamic subjects

**Files:**
- Create: `resources/views/components/mail/header.blade.php`
- Create: `resources/views/components/mail/footer.blade.php`
- Modify: `resources/views/mail/attendance-thank-you.blade.php`
- Modify: `resources/views/mail/new-admin-credentials.blade.php`
- Modify: `resources/views/mail/mail-setting-test.blade.php`
- Modify: `app/Mail/AttendanceThankYouMail.php`
- Modify: `app/Mail/NewAdminCredentialsMail.php`
- Modify: `app/Mail/MailSettingTestMail.php`
- Test: `tests/Feature/Mail/BrandedEmailsTest.php`

**Interfaces:**
- Consumes: `ClubSetting::current()`, `->name`, `->tagline`, `->logoUrl()`, `->primary_color`, `->address`, `->phone`, `->email`, `->hasContactInfo()`, `->hasSocialLinks()`, `->website`, `->facebook_url`, `->instagram_url` from Task 1.
- Produces: Blade components `<x-mail.header :club-setting="$clubSetting" />` and `<x-mail.footer :club-setting="$clubSetting" />`, consumed by all three mail templates in this task.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Mail\AttendanceThankYouMail;
use App\Mail\MailSettingTestMail;
use App\Mail\NewAdminCredentialsMail;
use App\Models\Attendance;
use App\Models\ClubSetting;
use App\Models\MeetingSession;
use App\Models\User;

beforeEach(function () {
    ClubSetting::current()->update([
        'name' => 'Club Branding Test',
        'tagline' => 'Zone Test',
        'primary_color' => '#654321',
        'address' => '1 rue Exemple',
        'phone' => '+229 11 11 11 11',
        'email' => 'contact@example.test',
        'website' => 'https://example.test',
        'facebook_url' => 'https://facebook.com/example',
        'instagram_url' => 'https://instagram.com/example',
    ]);
});

it('renders the configured branding and footer in the thank-you email', function () {
    $meetingSession = MeetingSession::factory()->create();
    $attendance = Attendance::factory()->for($meetingSession)->create();

    $mailable = new AttendanceThankYouMail($attendance, $meetingSession);

    $mailable->assertHasSubject('Merci pour votre présence — Club Branding Test');
    $mailable->assertSeeInHtml('Club Branding Test');
    $mailable->assertSeeInHtml('Zone Test');
    $mailable->assertSeeInHtml('1 rue Exemple');
    $mailable->assertSeeInHtml('+229 11 11 11 11');
    $mailable->assertSeeInHtml('Facebook');
    $mailable->assertSeeInHtml('Instagram');
});

it('renders the configured branding in the new admin credentials email', function () {
    $mailable = new NewAdminCredentialsMail(User::factory()->create(['name' => 'Awa Bello']), 'temp-password');

    $mailable->assertHasSubject('Vos identifiants d\'administration — Club Branding Test');
    $mailable->assertSeeInHtml('Club Branding Test');
    $mailable->assertSeeInHtml('1 rue Exemple');
});

it('renders the configured branding in the mail test email', function () {
    $mailable = new MailSettingTestMail;

    $mailable->assertHasSubject('Test de configuration mail — Club Branding Test');
    $mailable->assertSeeInHtml('Club Branding Test');
});
```

Save as `tests/Feature/Mail/BrandedEmailsTest.php`.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=BrandedEmailsTest`
Expected: FAIL — subjects still say "RC Cotonou Ife", no address/social text
in the rendered HTML.

- [ ] **Step 3: Create the shared header component**

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

Save as `resources/views/components/mail/header.blade.php`.

- [ ] **Step 4: Create the shared footer component**

```blade
@props(['clubSetting'])
@if ($clubSetting->hasContactInfo() || $clubSetting->hasSocialLinks())
    <tr>
        <td style="padding:16px 24px; text-align:center; font-size:11px; color:#6B6558; border-top:1px solid #EDEAE2;">
            @if ($clubSetting->address)
                {{ $clubSetting->address }}<br>
            @endif
            @if ($clubSetting->phone || $clubSetting->email)
                {{ collect([$clubSetting->phone, $clubSetting->email])->filter()->join(' · ') }}
            @endif
            @if ($clubSetting->hasSocialLinks())
                <br>
                @php
                    $links = collect([
                        $clubSetting->website ? ['label' => $clubSetting->website, 'url' => $clubSetting->website] : null,
                        $clubSetting->facebook_url ? ['label' => 'Facebook', 'url' => $clubSetting->facebook_url] : null,
                        $clubSetting->instagram_url ? ['label' => 'Instagram', 'url' => $clubSetting->instagram_url] : null,
                    ])->filter();
                @endphp
                @foreach ($links as $link)
                    <a href="{{ $link['url'] }}" style="color:#6B6558;">{{ $link['label'] }}</a>@if (! $loop->last) &middot; @endif
                @endforeach
            @endif
        </td>
    </tr>
@endif
```

Save as `resources/views/components/mail/footer.blade.php`.

- [ ] **Step 5: Update `attendance-thank-you.blade.php`**

Replace the full contents:

```blade
@php $clubSetting = \App\Models\ClubSetting::current(); @endphp
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Merci pour votre présence</title>
</head>
<body style="margin:0; padding:0; background-color:#F5F3EE; font-family: Arial, Helvetica, sans-serif; color:#12213D;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#F5F3EE; padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px; background-color:#ffffff; border-radius:12px; overflow:hidden;">
                    <tr>
                        <x-mail.header :club-setting="$clubSetting" />
                    </tr>
                    <tr>
                        <td style="padding:32px 24px;">
                            <p style="margin:0 0 16px; font-size:16px;">Bonjour {{ $attendance->name }},</p>
                            <p style="margin:0 0 16px; font-size:15px; line-height:1.6;">
                                Merci pour votre présence à <strong>{{ $meetingSession->title }}</strong>
                                du {{ $meetingSession->date->translatedFormat('d F Y') }}.
                            </p>
                            <p style="margin:0 0 16px; font-size:15px; line-height:1.6;">
                                Au plaisir de vous revoir lors de notre prochaine réunion !
                            </p>
                            @if ($nextSessionDate)
                                <p style="margin:0 0 16px; font-size:15px; line-height:1.6; padding:12px 16px; background-color:#F5F3EE; border-radius:8px;">
                                    @if ($nextSessionTitle)
                                        Notre prochaine séance, <strong>{{ $nextSessionTitle }}</strong>, aura lieu le
                                        {{ $nextSessionDate->translatedFormat('d F Y') }}.
                                    @else
                                        Notre prochaine séance aura lieu le {{ $nextSessionDate->translatedFormat('d F Y') }}.
                                    @endif
                                </p>
                            @endif
                            <p style="margin:24px 0 0; font-size:15px;">À bientôt,<br>{{ $clubSetting->name }}</p>
                        </td>
                    </tr>
                    <x-mail.footer :club-setting="$clubSetting" />
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
```

- [ ] **Step 6: Update `new-admin-credentials.blade.php`**

Replace the full contents:

```blade
@php $clubSetting = \App\Models\ClubSetting::current(); @endphp
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Vos identifiants d'administration</title>
</head>
<body style="margin:0; padding:0; background-color:#F5F3EE; font-family: Arial, Helvetica, sans-serif; color:#12213D;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#F5F3EE; padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px; background-color:#ffffff; border-radius:12px; overflow:hidden;">
                    <tr>
                        <x-mail.header :club-setting="$clubSetting" />
                    </tr>
                    <tr>
                        <td style="padding:32px 24px;">
                            <p style="margin:0 0 16px; font-size:16px;">Bonjour {{ $user->name }},</p>
                            <p style="margin:0 0 16px; font-size:15px; line-height:1.6;">
                                Un compte administrateur vient d'être créé pour vous sur l'espace d'administration {{ $clubSetting->name }}.
                            </p>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 16px; background-color:#F5F3EE; border-radius:8px;">
                                <tr>
                                    <td style="padding:12px 16px; font-size:15px; line-height:1.8;">
                                        <strong>Email :</strong> {{ $user->email }}<br>
                                        <strong>Mot de passe :</strong> {{ $password }}
                                    </td>
                                </tr>
                            </table>
                            <p style="margin:0 0 16px; font-size:15px; line-height:1.6;">
                                Conservez ce mot de passe en lieu sûr.
                            </p>
                            <p style="margin:24px 0 0; font-size:15px;">À bientôt,<br>{{ $clubSetting->name }}</p>
                        </td>
                    </tr>
                    <x-mail.footer :club-setting="$clubSetting" />
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
```

- [ ] **Step 7: Update `mail-setting-test.blade.php`**

Replace the full contents:

```blade
@php $clubSetting = \App\Models\ClubSetting::current(); @endphp
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Test de configuration mail</title>
</head>
<body style="margin:0; padding:0; background-color:#F5F3EE; font-family: Arial, Helvetica, sans-serif; color:#12213D;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#F5F3EE; padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px; background-color:#ffffff; border-radius:12px; overflow:hidden;">
                    <tr>
                        <x-mail.header :club-setting="$clubSetting" />
                    </tr>
                    <tr>
                        <td style="padding:32px 24px;">
                            <p style="margin:0 0 16px; font-size:15px; line-height:1.6;">
                                Ceci est un mail de test envoyé depuis les paramètres mail de l'administration {{ $clubSetting->name }}.
                            </p>
                            <p style="margin:0; font-size:15px; line-height:1.6;">
                                Si vous le recevez, la configuration fonctionne.
                            </p>
                        </td>
                    </tr>
                    <x-mail.footer :club-setting="$clubSetting" />
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
```

- [ ] **Step 8: Make the mailable subjects dynamic**

In `app/Mail/AttendanceThankYouMail.php`, add the import
`use App\Models\ClubSetting;` and replace:

```php
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Merci pour votre présence — RC Cotonou Ife',
        );
    }
```

with:

```php
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Merci pour votre présence — '.(ClubSetting::current()?->name ?? 'RC Cotonou Ife'),
        );
    }
```

In `app/Mail/NewAdminCredentialsMail.php`, add the same import and replace:

```php
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Vos identifiants d\'administration — RC Cotonou Ife',
        );
    }
```

with:

```php
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Vos identifiants d\'administration — '.(ClubSetting::current()?->name ?? 'RC Cotonou Ife'),
        );
    }
```

In `app/Mail/MailSettingTestMail.php`, add the same import and replace:

```php
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Test de configuration mail — RC Cotonou Ife',
        );
    }
```

with:

```php
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Test de configuration mail — '.(ClubSetting::current()?->name ?? 'RC Cotonou Ife'),
        );
    }
```

- [ ] **Step 9: Run the new tests to verify they pass**

Run: `php artisan test --compact --filter=BrandedEmailsTest`
Expected: PASS (3 tests)

- [ ] **Step 10: Run existing mail-related tests to check for regressions**

Run: `php artisan test --compact --filter=AttendanceThankYouMailTest`
Run: `php artisan test --compact --filter=AttendanceThankYouEmailTest`
Run: `php artisan test --compact --filter=UserManagementTest`
Expected: all PASS unchanged — the seeded `ClubSetting` name
("RC Cotonou Ife") matches every hardcoded string these tests already assert
on.

- [ ] **Step 11: Run the complete test suite**

Run: `php artisan test --compact`
Expected: PASS, 0 failures.

- [ ] **Step 12: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/components/mail/header.blade.php \
        resources/views/components/mail/footer.blade.php \
        resources/views/mail/attendance-thank-you.blade.php \
        resources/views/mail/new-admin-credentials.blade.php \
        resources/views/mail/mail-setting-test.blade.php \
        app/Mail/AttendanceThankYouMail.php \
        app/Mail/NewAdminCredentialsMail.php \
        app/Mail/MailSettingTestMail.php \
        tests/Feature/Mail/BrandedEmailsTest.php
git commit -m "feat: make email branding, footer and subjects configurable"
```
