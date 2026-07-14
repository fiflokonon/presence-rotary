# Admin-configurable SMTP mail settings — spec

Date: 2026-07-14

## Context

`config/mail.php` is 100% environment-driven today (`.env.example` defaults
`MAIL_MAILER=log`, so mail currently just logs instead of actually sending in
most environments). Two `ShouldQueue` Mailables already depend on real SMTP
working: `app/Mail/NewAdminCredentialsMail.php` (sent from
`Admin\UserController::store` when a new admin is created,
`app/Http/Controllers/Admin/UserController.php:37`) and
`app/Mail/AttendanceThankYouMail.php` (sent when a session is closed with the
thank-you option checked). There is no database-backed settings mechanism
anywhere in the app — no `Setting` model, no `spatie/laravel-settings`.

The admin panel (`resources/views/admin/*`, controllers in
`app/Http/Controllers/Admin/`) is plain Blade + Tailwind v4 + Alpine.js, no
Livewire/Filament. Authorization is flat — any row in `users` can access every
`/admin/*` route via the `auth` middleware group in `routes/web.php`; there is
no role system, so this new page needs no extra gating beyond that.

## Goal

Let an admin configure the SMTP credentials used to send mail (host, port,
username, password, encryption, from address/name) from a page in the admin
panel, instead of editing `.env` on the server. Once saved, these settings
override the app's runtime mail config automatically — both `NewAdminCredentialsMail`
and `AttendanceThankYouMail` pick them up with no changes to those classes.
Include a way to send a test email using the saved settings, to confirm they
actually work.

## Design

### 1. Data model & migration

New model `App\Models\MailSetting`, table `mail_settings`. Single-row
("singleton") table — the app only ever needs one SMTP configuration.

```php
Schema::create('mail_settings', function (Blueprint $table) {
    $table->id();
    $table->string('host');
    $table->unsignedSmallInteger('port');
    $table->string('username')->nullable();
    $table->text('password')->nullable();
    $table->string('encryption')->nullable(); // 'tls', 'ssl', or null
    $table->string('from_address');
    $table->string('from_name');
    $table->timestamps();
});
```

`password` is `text` (not `string`) to leave headroom for Laravel's
`encrypted` cast, which grows the stored value. `App\Models\MailSetting`
follows `User`'s newer Laravel 13 attribute style since it also holds a
secret:

```php
#[Fillable(['host', 'port', 'username', 'password', 'encryption', 'from_address', 'from_name'])]
#[Hidden(['password'])]
class MailSetting extends Model
{
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

No factory/seeder — this model isn't used in attendance-flow tests and has
exactly one real row, created through the admin form.

### 2. Runtime config override

In `App\Providers\AppServiceProvider::boot()`, after existing boot logic:

```php
$mailSetting = MailSetting::current();

if ($mailSetting !== null) {
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
```

Guarded by a check that the `mail_settings` table exists (mirroring how
Laravel apps typically guard optional-table boot logic), so a fresh
`migrate`-less environment (e.g. running `artisan` before migrations) doesn't
break:

```php
if (Schema::hasTable('mail_settings')) {
    // ... the above
}
```

If no row exists yet, behavior is byte-for-byte unchanged — everything still
falls back to `.env`/`config/mail.php`, exactly as today. This runs for both
web requests and queue workers, since `queue:listen` boots the full
application per job — no changes needed to the existing Mailables.

### 3. Admin UI & routes

New `Admin\MailSettingController`:

- `edit(): View` — loads `MailSetting::current()` (or `null`), renders the
  form. The `password` field is always rendered **blank**; leaving it blank on
  submit means "keep the existing password" (see validation below) — the
  plaintext value is never echoed back into the HTML.
- `update(StoreMailSettingRequest $request): RedirectResponse` — validates,
  then:
  ```php
  $data = $request->validated();

  if (blank($data['password'] ?? null)) {
      unset($data['password']);
  }

  MailSetting::query()->first()?->update($data) ?? MailSetting::create($data);
  ```
- `sendTest(SendMailSettingTestRequest $request): RedirectResponse` — see
  section 4.

Routes, in the existing `admin.` group under `auth` middleware
(`routes/web.php`), alongside `sessions`/`users`:

```php
Route::get('mail-settings', [MailSettingController::class, 'edit'])->name('mail-settings.edit');
Route::put('mail-settings', [MailSettingController::class, 'update'])->name('mail-settings.update');
Route::post('mail-settings/test', [MailSettingController::class, 'sendTest'])->name('mail-settings.test');
```

New sidebar entry in `resources/views/components/layouts/admin.blade.php`,
matching the existing `Séances`/`Administrateurs` links:

```blade
<a href="{{ route('admin.mail-settings.edit') }}" @click="close()"
    class="cursor-pointer rounded-lg px-3 py-2 text-sm font-semibold {{ request()->routeIs('admin.mail-settings.*') ? 'bg-navy text-white' : 'text-navy hover:bg-cream' }}">
    Paramètres mail
</a>
```

New view `resources/views/admin/mail-settings/edit.blade.php` (`<x-layouts.admin>`),
following the form conventions in `resources/views/admin/users/create.blade.php`:
fields for host, port, username, password (blank, with helper text "Laisser
vide pour conserver le mot de passe actuel"), encryption (`<select>`:
"Aucun" / "TLS" / "SSL"), from address, from name. A "Enregistrer" submit
button. The "send test" control (section 4) only renders when
`MailSetting::current()` is not null.

`StoreMailSettingRequest`:

```php
public function authorize(): bool
{
    return true;
}

protected function prepareForValidation(): void
{
    // The "Aucun" <select> option submits an empty string; normalize it to
    // null so the `in:tls,ssl` rule doesn't reject it.
    $this->merge(['encryption' => $this->filled('encryption') ? $this->input('encryption') : null]);
}

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
```

### 4. Test email

Only shown once a `MailSetting` row already exists (per the user's call: test
only ever uses saved, persisted settings — not unsaved form input). A small
form next to the main settings form: one `email` input ("Destinataire du
test") + "Envoyer un mail de test" submit button, posting to
`admin.mail-settings.test`.

`SendMailSettingTestRequest`:

```php
public function rules(): array
{
    return ['test_email' => ['required', 'email']];
}
```

`MailSettingController::sendTest`:

```php
public function sendTest(SendMailSettingTestRequest $request): RedirectResponse
{
    if (MailSetting::current() === null) {
        return back()->withErrors(['test_email' => 'Enregistrez d\'abord une configuration.']);
    }

    try {
        Mail::to($request->validated('test_email'))->send(new MailSettingTestMail());
    } catch (\Throwable $e) {
        return back()->withErrors(['test_email' => 'Échec de l\'envoi : '.$e->getMessage()]);
    }

    return back()->with('status', 'Mail de test envoyé.');
}
```

Sent synchronously (`send()`, not `queue()`) — the whole point is immediate
pass/fail feedback, so it must not disappear into the queue worker.

New `app/Mail/MailSettingTestMail.php` (not `ShouldQueue`):

```php
class MailSettingTestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Test de configuration mail — RC Cotonou Ife');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.mail-setting-test');
    }
}
```

New `resources/views/mail/mail-setting-test.blade.php` — minimal plain HTML
(inline styles, no Tailwind, matching `mail/new-admin-credentials.blade.php`
conventions): "Ceci est un mail de test envoyé depuis les paramètres mail de
l'administration RC Cotonou Ife. Si vous le recevez, la configuration
fonctionne."

### 5. Files touched

- `database/migrations/2026_07_14_xxxxxx_create_mail_settings_table.php` (new)
- `app/Models/MailSetting.php` (new)
- `app/Providers/AppServiceProvider.php` (boot-time config override)
- `app/Http/Controllers/Admin/MailSettingController.php` (new)
- `app/Http/Requests/StoreMailSettingRequest.php` (new)
- `app/Http/Requests/SendMailSettingTestRequest.php` (new)
- `app/Mail/MailSettingTestMail.php` (new)
- `resources/views/admin/mail-settings/edit.blade.php` (new)
- `resources/views/mail/mail-setting-test.blade.php` (new)
- `resources/views/components/layouts/admin.blade.php` (sidebar entry)
- `routes/web.php` (three new routes)

## Testing

Feature tests, new `tests/Feature/Admin/MailSettingManagementTest.php`:

- Unauthenticated request to `admin.mail-settings.edit`/`update`/`test`
  redirects to login, consistent with other admin routes.
- Submitting valid settings creates the single `MailSetting` row; the
  `password` column in the database is not the plaintext value (encrypted
  cast round-trips correctly).
- Submitting again with the password field blank keeps the previously saved
  password unchanged; submitting a new password overwrites it.
- The edit page never renders the plaintext password in the HTML response.
- `admin.mail-settings.test` with no `MailSetting` row yet returns a
  validation-style error and does not attempt to send.
- `admin.mail-settings.test` with a saved row sends `MailSettingTestMail`
  synchronously (`Mail::fake()` + `assertSent`), and is not queued.

Unit/feature test for the boot override: with a `MailSetting` row present,
`config('mail.mailers.smtp.host')` reflects the DB value after a fresh
request; with no row, `config('mail.mailers.smtp.host')` matches
`.env`/`config/mail.php`'s default — confirming the fallback path is
untouched.

## Out of scope

- Multiple mail configurations / per-mailer profiles (only one SMTP config,
  matching the single-club scope of this app).
- Non-SMTP transports (Mailgun, SES, Postmark, Resend) — SMTP only, matching
  what `.env.example` already assumes.
- Any role/permission distinction restricting who can see this page — every
  admin user can, consistent with every other `/admin/*` route today.
- Editing `NewAdminCredentialsMail`/`AttendanceThankYouMail` — they need no
  changes; they inherit the override transparently via `config()`.
