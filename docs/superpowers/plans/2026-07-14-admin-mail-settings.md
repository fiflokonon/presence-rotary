# Admin Mail Settings Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an admin configure SMTP mail credentials from the admin panel (instead of `.env`), have those credentials automatically override the app's runtime mail config, and let the admin send a test email using the saved config.

**Architecture:** A single-row `MailSetting` Eloquent model (encrypted password) is the source of truth. `AppServiceProvider::boot()` reads it and overrides `config('mail.*')` at runtime if a row exists, falling back to `.env` untouched otherwise. A new `Admin\MailSettingController` (edit/update/sendTest) exposes a Blade form under the existing `admin.` route group. A dedicated non-queued Mailable (`MailSettingTestMail`) is sent synchronously so the test button gives immediate pass/fail feedback.

**Tech Stack:** Laravel 13, PHP 8.4, Pest 4, Blade + Tailwind v4 + Alpine.js (no Livewire/Filament), SQLite in-memory for tests.

## Global Constraints

- Follow existing admin conventions exactly: routes under `Route::prefix('admin')->name('admin.')->middleware('auth')` group in `routes/web.php`, controllers in `app/Http/Controllers/Admin/`, Form Requests with `authorize(): true`, Pest feature tests in `tests/Feature/Admin/` using plain `it(...)` (no `describe`), `RefreshDatabase` is auto-applied via `tests/Pest.php`.
- `MailSetting` uses PHP 8 attributes `#[Fillable(...)]` / `#[Hidden(...)]` (matching `app/Models/User.php`), not `protected $fillable`.
- `password` column is `text`, cast `encrypted`. Never render the plaintext password back into HTML.
- No role/permission system exists — any authenticated admin can access this page, same as `sessions`/`users`.
- Single SMTP config only (no multi-mailer, no non-SMTP transports) — out of scope per spec.
- Run `vendor/bin/pint --dirty --format agent` after PHP changes in each task, before committing.
- Spec: `docs/superpowers/specs/2026-07-14-admin-mail-settings-design.md`.

---

### Task 1: `MailSetting` model + migration

**Files:**
- Create: `database/migrations/xxxx_xx_xx_xxxxxx_create_mail_settings_table.php` (via `make:migration`)
- Create: `app/Models/MailSetting.php` (via `make:model`)
- Test: `tests/Feature/MailSettingTest.php` (via `make:test --pest`)

**Interfaces:**
- Consumes: nothing (first task).
- Produces: `App\Models\MailSetting` with fillable `host`, `port`, `username`, `password`, `encryption`, `from_address`, `from_name`; `password` cast `encrypted`; static method `MailSetting::current(): ?self` returning the single row (or `null`). Later tasks call `MailSetting::current()` and `MailSetting::create()`/`->update()`.

- [ ] **Step 1: Generate the model and migration**

```bash
php artisan make:model MailSetting -m --no-interaction
```

- [ ] **Step 2: Write the migration**

Edit the generated `database/migrations/xxxx_xx_xx_xxxxxx_create_mail_settings_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('mail_settings', function (Blueprint $table) {
            $table->id();
            $table->string('host');
            $table->unsignedSmallInteger('port');
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            $table->string('encryption')->nullable();
            $table->string('from_address');
            $table->string('from_name');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mail_settings');
    }
};
```

- [ ] **Step 3: Write the model**

Replace the generated `app/Models/MailSetting.php` with:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['host', 'port', 'username', 'password', 'encryption', 'from_address', 'from_name'])]
#[Hidden(['password'])]
class MailSetting extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
        ];
    }

    public static function current(): ?self
    {
        return static::query()->first();
    }
}
```

- [ ] **Step 4: Write the failing test**

```bash
php artisan make:test --pest MailSettingTest --no-interaction
```

Replace `tests/Feature/MailSettingTest.php` with:

```php
<?php

use App\Models\MailSetting;

it('returns null from current() when no row exists', function () {
    expect(MailSetting::current())->toBeNull();
});

it('returns the single row from current()', function () {
    MailSetting::create([
        'host' => 'smtp.example.com',
        'port' => 587,
        'username' => 'bot@example.com',
        'password' => 'secret-password',
        'encryption' => 'tls',
        'from_address' => 'no-reply@example.com',
        'from_name' => 'RC Cotonou Ife',
    ]);

    expect(MailSetting::current()->host)->toBe('smtp.example.com');
});

it('encrypts the password at rest and decrypts it back transparently', function () {
    $mailSetting = MailSetting::create([
        'host' => 'smtp.example.com',
        'port' => 587,
        'username' => 'bot@example.com',
        'password' => 'secret-password',
        'encryption' => 'tls',
        'from_address' => 'no-reply@example.com',
        'from_name' => 'RC Cotonou Ife',
    ]);

    $rawColumn = \DB::table('mail_settings')->where('id', $mailSetting->id)->value('password');

    expect($rawColumn)->not->toBe('secret-password')
        ->and($mailSetting->fresh()->password)->toBe('secret-password');
});
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=MailSettingTest`
Expected: PASS (3 passed) — the migration already creates the table via `RefreshDatabase`, and the model/casts are already correct, so this should pass on first run. If it fails, re-check the migration filename matches `create_mail_settings_table` (Laravel autoloads all `database/migrations/*.php` regardless of name, so a mismatch would only matter if you hand-typed the file — using `make:model -m` avoids this).

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/MailSetting.php database/migrations/*_create_mail_settings_table.php tests/Feature/MailSettingTest.php
git commit -m "Add MailSetting model and migration"
```

---

### Task 2: Runtime mail config override in `AppServiceProvider`

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/MailSettingConfigOverrideTest.php` (via `make:test --pest`)

**Interfaces:**
- Consumes: `App\Models\MailSetting::current(): ?MailSetting` (Task 1).
- Produces: nothing new consumed by later tasks — this is a leaf effect (mutates `config('mail.*')` at boot). Later tasks (3, 4) don't call anything from this task directly; they rely on it running automatically before any `Mail::` call.

- [ ] **Step 1: Write the failing test**

```bash
php artisan make:test --pest MailSettingConfigOverrideTest --no-interaction
```

Replace `tests/Feature/MailSettingConfigOverrideTest.php` with:

```php
<?php

use App\Models\MailSetting;

it('leaves the default mail config untouched when no MailSetting row exists', function () {
    $this->get('/');

    // Values come from .env, unaffected by phpunit.xml's MAIL_MAILER=array override.
    expect(config('mail.default'))->toBe('array')
        ->and(config('mail.mailers.smtp.host'))->toBe('127.0.0.1')
        ->and(config('mail.mailers.smtp.port'))->toBe(2525)
        ->and(config('mail.from.address'))->toBe('hello@example.com')
        ->and(MailSetting::current())->toBeNull();
});

it('overrides the runtime mail config when a MailSetting row exists', function () {
    MailSetting::create([
        'host' => 'smtp.custom.test',
        'port' => 2526,
        'username' => 'custom-user',
        'password' => 'custom-pass',
        'encryption' => 'ssl',
        'from_address' => 'custom@example.com',
        'from_name' => 'Custom Sender',
    ]);

    $this->get('/');

    expect(config('mail.default'))->toBe('smtp')
        ->and(config('mail.mailers.smtp.host'))->toBe('smtp.custom.test')
        ->and(config('mail.mailers.smtp.port'))->toBe(2526)
        ->and(config('mail.mailers.smtp.username'))->toBe('custom-user')
        ->and(config('mail.mailers.smtp.password'))->toBe('custom-pass')
        ->and(config('mail.mailers.smtp.encryption'))->toBe('ssl')
        ->and(config('mail.from.address'))->toBe('custom@example.com')
        ->and(config('mail.from.name'))->toBe('Custom Sender');
});
```

- [ ] **Step 2: Run test to verify the second case fails**

Run: `php artisan test --compact --filter=MailSettingConfigOverrideTest`
Expected: first test PASSes (nothing to override yet), second test FAILs because `config('mail.default')` is still `'log'`/`'array'`, not `'smtp'`.

- [ ] **Step 3: Implement the override**

Replace `app/Providers/AppServiceProvider.php` with:

```php
<?php

namespace App\Providers;

use App\Models\MailSetting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        $this->overrideMailConfigFromDatabase();
    }

    private function overrideMailConfigFromDatabase(): void
    {
        if (! Schema::hasTable('mail_settings')) {
            return;
        }

        $mailSetting = MailSetting::current();

        if ($mailSetting === null) {
            return;
        }

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => $mailSetting->host,
            'mail.mailers.smtp.port' => $mailSetting->port,
            'mail.mailers.smtp.username' => $mailSetting->username,
            'mail.mailers.smtp.password' => $mailSetting->password,
            'mail.mailers.smtp.encryption' => $mailSetting->encryption,
            'mail.from.address' => $mailSetting->from_address,
            'mail.from.name' => $mailSetting->from_name,
        ]);
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=MailSettingConfigOverrideTest`
Expected: PASS (2 passed)

- [ ] **Step 5: Run the full test suite to check for regressions**

Run: `php artisan test --compact`
Expected: all tests PASS (no existing test relies on `mail.default` staying `'array'`, since `Mail::fake()` intercepts sends regardless of the configured mailer).

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Providers/AppServiceProvider.php tests/Feature/MailSettingConfigOverrideTest.php
git commit -m "Override runtime mail config from saved MailSetting"
```

---

### Task 3: Admin edit/update UI for mail settings

**Files:**
- Create: `app/Http/Requests/StoreMailSettingRequest.php` (via `make:request`)
- Create: `app/Http/Controllers/Admin/MailSettingController.php` (via `make:controller`)
- Create: `resources/views/admin/mail-settings/edit.blade.php`
- Modify: `resources/views/components/layouts/admin.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Admin/MailSettingManagementTest.php` (via `make:test --pest`)

**Interfaces:**
- Consumes: `App\Models\MailSetting::current(): ?MailSetting`, `MailSetting::create(array $data)`, `$mailSetting->update(array $data)` (Task 1).
- Produces: routes `admin.mail-settings.edit` (GET), `admin.mail-settings.update` (PUT). Task 4 adds `admin.mail-settings.test` to the same controller and extends this same Blade view — this task must leave `edit.blade.php` structured so a "test email" section can be appended without restructuring (a `<div>` per form, not one shared `<form>`).

- [ ] **Step 1: Generate the request, controller, and test**

```bash
php artisan make:request StoreMailSettingRequest --no-interaction
php artisan make:controller Admin/MailSettingController --no-interaction
php artisan make:test --pest Admin/MailSettingManagementTest --no-interaction
```

- [ ] **Step 2: Write the failing tests**

Replace `tests/Feature/Admin/MailSettingManagementTest.php` with:

```php
<?php

use App\Models\MailSetting;
use App\Models\User;

it('redirects guests to login on edit', function () {
    $this->get(route('admin.mail-settings.edit'))->assertRedirect(route('admin.login'));
});

it('redirects guests to login on update', function () {
    $this->put(route('admin.mail-settings.update'), [])->assertRedirect(route('admin.login'));
});

it('shows an empty form when no settings are saved yet', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.mail-settings.edit'))
        ->assertOk()
        ->assertSee('Paramètres mail');
});

it('creates the mail settings row on first save', function () {
    $this->actingAs(User::factory()->create())
        ->put(route('admin.mail-settings.update'), [
            'host' => 'smtp.example.com',
            'port' => 587,
            'username' => 'bot@example.com',
            'password' => 'secret-password',
            'encryption' => 'tls',
            'from_address' => 'no-reply@example.com',
            'from_name' => 'RC Cotonou Ife',
        ])->assertRedirect(route('admin.mail-settings.edit'));

    $mailSetting = MailSetting::current();

    expect($mailSetting->host)->toBe('smtp.example.com')
        ->and($mailSetting->password)->toBe('secret-password');
});

it('does not render the plaintext password back into the edit page', function () {
    MailSetting::create([
        'host' => 'smtp.example.com',
        'port' => 587,
        'username' => 'bot@example.com',
        'password' => 'super-secret-value',
        'encryption' => 'tls',
        'from_address' => 'no-reply@example.com',
        'from_name' => 'RC Cotonou Ife',
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.mail-settings.edit'))
        ->assertOk()
        ->assertDontSee('super-secret-value');
});

it('keeps the existing password when the password field is left blank on update', function () {
    $mailSetting = MailSetting::create([
        'host' => 'smtp.example.com',
        'port' => 587,
        'username' => 'bot@example.com',
        'password' => 'original-password',
        'encryption' => 'tls',
        'from_address' => 'no-reply@example.com',
        'from_name' => 'RC Cotonou Ife',
    ]);

    $this->actingAs(User::factory()->create())
        ->put(route('admin.mail-settings.update'), [
            'host' => 'smtp.changed.com',
            'port' => 2525,
            'username' => 'bot@example.com',
            'password' => '',
            'encryption' => '',
            'from_address' => 'no-reply@example.com',
            'from_name' => 'RC Cotonou Ife',
        ])->assertRedirect(route('admin.mail-settings.edit'));

    expect($mailSetting->fresh()->password)->toBe('original-password')
        ->and($mailSetting->fresh()->host)->toBe('smtp.changed.com')
        ->and($mailSetting->fresh()->encryption)->toBeNull();
});

it('overwrites the password when a new one is submitted', function () {
    $mailSetting = MailSetting::create([
        'host' => 'smtp.example.com',
        'port' => 587,
        'username' => 'bot@example.com',
        'password' => 'original-password',
        'encryption' => 'tls',
        'from_address' => 'no-reply@example.com',
        'from_name' => 'RC Cotonou Ife',
    ]);

    $this->actingAs(User::factory()->create())
        ->put(route('admin.mail-settings.update'), [
            'host' => 'smtp.example.com',
            'port' => 587,
            'username' => 'bot@example.com',
            'password' => 'brand-new-password',
            'encryption' => 'tls',
            'from_address' => 'no-reply@example.com',
            'from_name' => 'RC Cotonou Ife',
        ])->assertRedirect(route('admin.mail-settings.edit'));

    expect($mailSetting->fresh()->password)->toBe('brand-new-password');
});

it('rejects an invalid payload', function () {
    $this->actingAs(User::factory()->create())
        ->put(route('admin.mail-settings.update'), [
            'host' => '',
            'port' => 'not-a-number',
            'from_address' => 'not-an-email',
            'from_name' => '',
        ])->assertSessionHasErrors(['host', 'port', 'from_address', 'from_name']);
});
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `php artisan test --compact --filter=MailSettingManagementTest`
Expected: FAIL — routes `admin.mail-settings.edit`/`admin.mail-settings.update` don't exist yet.

- [ ] **Step 4: Write the validation request**

Replace `app/Http/Requests/StoreMailSettingRequest.php` with:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMailSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'encryption' => $this->filled('encryption') ? $this->input('encryption') : null,
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'encryption' => ['nullable', 'in:tls,ssl'],
            'from_address' => ['required', 'string', 'email', 'max:255'],
            'from_name' => ['required', 'string', 'max:255'],
        ];
    }
}
```

- [ ] **Step 5: Write the controller**

Replace `app/Http/Controllers/Admin/MailSettingController.php` with:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMailSettingRequest;
use App\Models\MailSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class MailSettingController extends Controller
{
    public function edit(): View
    {
        return view('admin.mail-settings.edit', [
            'mailSetting' => MailSetting::current(),
        ]);
    }

    public function update(StoreMailSettingRequest $request): RedirectResponse
    {
        $data = $request->validated();

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $mailSetting = MailSetting::current();

        if ($mailSetting !== null) {
            $mailSetting->update($data);
        } else {
            MailSetting::create($data);
        }

        return redirect()->route('admin.mail-settings.edit')->with('status', 'Paramètres mail enregistrés.');
    }
}
```

- [ ] **Step 6: Add routes**

Edit `routes/web.php` — add the import and the two routes inside the existing `auth` middleware group:

```php
use App\Http\Controllers\Admin\MailSettingController;
```

```php
        Route::get('mail-settings', [MailSettingController::class, 'edit'])->name('mail-settings.edit');
        Route::put('mail-settings', [MailSettingController::class, 'update'])->name('mail-settings.update');
```

(placed right after the `users` routes, before the closing `});` of the `auth` middleware group)

- [ ] **Step 7: Add the sidebar nav entry**

Edit `resources/views/components/layouts/admin.blade.php`, adding this link right after the "Administrateurs" `<a>` (before the closing `</nav>`):

```blade
                <a href="{{ route('admin.mail-settings.edit') }}" @click="close()"
                    class="cursor-pointer rounded-lg px-3 py-2 text-sm font-semibold {{ request()->routeIs('admin.mail-settings.*') ? 'bg-navy text-white' : 'text-navy hover:bg-cream' }}">
                    Paramètres mail
                </a>
```

- [ ] **Step 8: Write the edit view**

Create `resources/views/admin/mail-settings/edit.blade.php`:

```blade
<x-layouts.admin title="Paramètres mail — Administration">
    <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)] md:p-8">
        <h1 class="font-display text-xl font-extrabold text-navy">Paramètres mail</h1>
        <p class="mt-1 text-sm text-muted">
            Configurez le serveur SMTP utilisé pour envoyer les emails (identifiants d'admin, remerciements de présence).
        </p>

        @if (session('status'))
            <div class="mt-4 rounded-lg bg-success-bg px-4 py-3 text-sm text-success">
                {{ session('status') }}
            </div>
        @endif

        <form method="POST" action="{{ route('admin.mail-settings.update') }}" class="mt-4 flex max-w-md flex-col gap-3">
            @csrf
            @method('PUT')
            <div class="flex flex-col gap-1.5">
                <label for="host" class="text-sm font-semibold">Hôte SMTP</label>
                <input type="text" id="host" name="host" value="{{ old('host', $mailSetting?->host) }}" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="port" class="text-sm font-semibold">Port</label>
                <input type="number" id="port" name="port" value="{{ old('port', $mailSetting?->port) }}" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="username" class="text-sm font-semibold">Utilisateur</label>
                <input type="text" id="username" name="username" value="{{ old('username', $mailSetting?->username) }}"
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="password" class="text-sm font-semibold">Mot de passe</label>
                <input type="password" id="password" name="password" value=""
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
                <p class="text-xs text-muted">Laisser vide pour conserver le mot de passe actuel.</p>
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="encryption" class="text-sm font-semibold">Chiffrement</label>
                <select id="encryption" name="encryption"
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
                    <option value="" {{ old('encryption', $mailSetting?->encryption) === null ? 'selected' : '' }}>Aucun</option>
                    <option value="tls" {{ old('encryption', $mailSetting?->encryption) === 'tls' ? 'selected' : '' }}>TLS</option>
                    <option value="ssl" {{ old('encryption', $mailSetting?->encryption) === 'ssl' ? 'selected' : '' }}>SSL</option>
                </select>
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="from_address" class="text-sm font-semibold">Adresse d'expédition</label>
                <input type="email" id="from_address" name="from_address" value="{{ old('from_address', $mailSetting?->from_address) }}" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="from_name" class="text-sm font-semibold">Nom d'expédition</label>
                <input type="text" id="from_name" name="from_name" value="{{ old('from_name', $mailSetting?->from_name) }}" required
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

- [ ] **Step 9: Run tests to verify they pass**

Run: `php artisan test --compact --filter=MailSettingManagementTest`
Expected: PASS (8 passed)

- [ ] **Step 10: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Requests/StoreMailSettingRequest.php app/Http/Controllers/Admin/MailSettingController.php resources/views/admin/mail-settings/edit.blade.php resources/views/components/layouts/admin.blade.php routes/web.php tests/Feature/Admin/MailSettingManagementTest.php
git commit -m "Add admin edit/update UI for mail settings"
```

---

### Task 4: Test-email flow

**Files:**
- Create: `app/Http/Requests/SendMailSettingTestRequest.php` (via `make:request`)
- Create: `app/Mail/MailSettingTestMail.php` (via `make:mail`)
- Create: `resources/views/mail/mail-setting-test.blade.php`
- Modify: `app/Http/Controllers/Admin/MailSettingController.php` (add `sendTest`)
- Modify: `resources/views/admin/mail-settings/edit.blade.php` (add test-email form section)
- Modify: `routes/web.php` (add `mail-settings/test` route)
- Test: extend `tests/Feature/Admin/MailSettingManagementTest.php`

**Interfaces:**
- Consumes: `App\Models\MailSetting::current(): ?MailSetting` (Task 1); the `edit.blade.php` structure from Task 3 (appends a second `<form>`, doesn't touch the first).
- Produces: route `admin.mail-settings.test` (POST); `App\Mail\MailSettingTestMail` (no constructor args, not `ShouldQueue`).

- [ ] **Step 1: Generate the request and mailable**

```bash
php artisan make:request SendMailSettingTestRequest --no-interaction
php artisan make:mail MailSettingTestMail --no-interaction
```

- [ ] **Step 2: Write the failing tests**

Append to `tests/Feature/Admin/MailSettingManagementTest.php`:

```php
use App\Mail\MailSettingTestMail;
use Illuminate\Support\Facades\Mail;

it('rejects a test-email request when no settings are saved yet', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('admin.mail-settings.test'), ['test_email' => 'someone@example.com'])
        ->assertSessionHasErrors(['test_email']);
});

it('sends a test email synchronously using the saved settings', function () {
    Mail::fake();

    MailSetting::create([
        'host' => 'smtp.example.com',
        'port' => 587,
        'username' => 'bot@example.com',
        'password' => 'secret-password',
        'encryption' => 'tls',
        'from_address' => 'no-reply@example.com',
        'from_name' => 'RC Cotonou Ife',
    ]);

    $this->actingAs(User::factory()->create())
        ->post(route('admin.mail-settings.test'), ['test_email' => 'someone@example.com'])
        ->assertRedirect();

    Mail::assertSent(MailSettingTestMail::class, function ($mail) {
        return $mail->hasTo('someone@example.com');
    });

    Mail::assertNotQueued(MailSettingTestMail::class);
});

it('rejects an invalid test-email address', function () {
    MailSetting::create([
        'host' => 'smtp.example.com',
        'port' => 587,
        'username' => 'bot@example.com',
        'password' => 'secret-password',
        'encryption' => 'tls',
        'from_address' => 'no-reply@example.com',
        'from_name' => 'RC Cotonou Ife',
    ]);

    $this->actingAs(User::factory()->create())
        ->post(route('admin.mail-settings.test'), ['test_email' => 'not-an-email'])
        ->assertSessionHasErrors(['test_email']);
});
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `php artisan test --compact --filter=MailSettingManagementTest`
Expected: FAIL — route `admin.mail-settings.test` doesn't exist yet.

- [ ] **Step 4: Write the validation request**

Replace `app/Http/Requests/SendMailSettingTestRequest.php` with:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendMailSettingTestRequest extends FormRequest
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
            'test_email' => ['required', 'string', 'email'],
        ];
    }
}
```

- [ ] **Step 5: Write the mailable**

Replace `app/Mail/MailSettingTestMail.php` with:

```php
<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class MailSettingTestMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Test de configuration mail — RC Cotonou Ife',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.mail-setting-test',
        );
    }
}
```

- [ ] **Step 6: Write the mail view**

Create `resources/views/mail/mail-setting-test.blade.php`:

```blade
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
                        <td style="background-color:#12213D; padding:24px; text-align:center;">
                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto; background-color:#ffffff; border-radius:12px;">
                                <tr>
                                    <td style="padding:8px 16px;">
                                        <img src="{{ asset('assets/ife-logo.png') }}" alt="RC Cotonou Ife" width="180" style="display:block; height:auto; width:180px;">
                                    </td>
                                </tr>
                            </table>
                            <p style="margin:16px 0 0; color:#ffffff; font-size:16px; font-weight:bold;">RC Cotonou Ife</p>
                            <p style="margin:4px 0 0; color:#F2B94D; font-size:11px; letter-spacing:0.05em; text-transform:uppercase;">District 9103</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px 24px;">
                            <p style="margin:0 0 16px; font-size:15px; line-height:1.6;">
                                Ceci est un mail de test envoyé depuis les paramètres mail de l'administration RC Cotonou Ife.
                            </p>
                            <p style="margin:0; font-size:15px; line-height:1.6;">
                                Si vous le recevez, la configuration fonctionne.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
```

- [ ] **Step 7: Add `sendTest` to the controller**

Edit `app/Http/Controllers/Admin/MailSettingController.php` — add imports and the new method:

```php
use App\Http\Requests\SendMailSettingTestRequest;
use App\Mail\MailSettingTestMail;
use Illuminate\Support\Facades\Mail;
use Throwable;
```

```php
    public function sendTest(SendMailSettingTestRequest $request): RedirectResponse
    {
        if (MailSetting::current() === null) {
            return back()->withErrors(['test_email' => "Enregistrez d'abord une configuration."]);
        }

        try {
            Mail::to($request->validated('test_email'))->send(new MailSettingTestMail);
        } catch (Throwable $e) {
            return back()->withErrors(['test_email' => 'Échec de l\'envoi : '.$e->getMessage()]);
        }

        return back()->with('status', 'Mail de test envoyé.');
    }
```

- [ ] **Step 8: Add the route**

Edit `routes/web.php` — add right after the `mail-settings.update` route:

```php
        Route::post('mail-settings/test', [MailSettingController::class, 'sendTest'])->name('mail-settings.test');
```

- [ ] **Step 9: Add the test-email form to the edit view**

Edit `resources/views/admin/mail-settings/edit.blade.php` — insert this new block right after the closing `</form>` of the settings form and before the `@if ($errors->any())` block:

```blade
        @if ($mailSetting)
            <form method="POST" action="{{ route('admin.mail-settings.test') }}" class="mt-6 flex max-w-md flex-col gap-3 border-t border-border pt-6">
                @csrf
                <label for="test_email" class="text-sm font-semibold">Envoyer un mail de test</label>
                <div class="flex gap-2">
                    <input type="email" id="test_email" name="test_email" placeholder="destinataire@example.com" required
                        class="flex-1 rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
                    <button type="submit"
                        class="cursor-pointer rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                        Envoyer
                    </button>
                </div>
            </form>
        @endif
```

- [ ] **Step 10: Run tests to verify they pass**

Run: `php artisan test --compact --filter=MailSettingManagementTest`
Expected: PASS (11 passed)

- [ ] **Step 11: Run the full test suite**

Run: `php artisan test --compact`
Expected: all tests PASS.

- [ ] **Step 12: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Requests/SendMailSettingTestRequest.php app/Mail/MailSettingTestMail.php resources/views/mail/mail-setting-test.blade.php app/Http/Controllers/Admin/MailSettingController.php resources/views/admin/mail-settings/edit.blade.php routes/web.php tests/Feature/Admin/MailSettingManagementTest.php
git commit -m "Add saved-config test-email flow to mail settings"
```
