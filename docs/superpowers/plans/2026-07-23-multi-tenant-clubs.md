# Multi-tenant club deployment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Serve multiple clubs (tenants) from the single existing Docker stack, each isolated in its own SQLite database and reachable on its own subdomain, plus a super-admin panel to provision tenants, view any club's admin panel, and see aggregated stats.

**Architecture:** A new `central` SQLite connection holds a `tenants` registry and `super_admins`. A `TenantContext` service is the single place that switches the existing `sqlite` connection's underlying file to the current tenant's database. A `ResolveTenant` middleware sets the tenant per request (by `Host` header, or from session when running under the super-admin domain in "view as" mode). Two queued mailables are refactored into tenant-scalar-carrying jobs so a queue worker always sends mail against the correct tenant's data.

**Tech Stack:** Laravel 13, PHP 8.4, SQLite (per-tenant files + one central file), Pest 4, Blade + Tailwind.

## Global Constraints

- Follow existing code conventions in this app (see sibling files before writing new ones).
- Use PHP 8 constructor property promotion; explicit return types and param types everywhere.
- Curly braces for all control structures.
- Run `vendor/bin/pint --dirty --format agent` after any PHP file changes, before considering a task done.
- Tests use Pest (`it(...)`/`expect(...)`), factories over manual model creation. From Task 3 onward, there are two test invocations, not one: `php artisan test --compact --testsuite=Unit,Feature` (fast, everything except the handful of cross-tenant tests) and `php artisan test --compact --testsuite=Tenancy` (slower, only the cross-tenant tests from Tasks 8–10) — see Task 3 for why. Bare `php artisan test` (no `--testsuite`) runs both together in one process, which is exactly what must be avoided once Task 3 lands.
- Do not delete a test's *coverage* without replacing it — moving/adapting a test when the code it tests moves is fine and expected; note it explicitly when you do it.
- No new base directories beyond what's listed in this plan's file lists.

---

## Notes surfaced during planning (read before starting)

1. **`database/migrations/2026_07_22_120001_seed_club_settings_table.php`** hardcodes a `club_settings` row named "RC Cotonou Ife" — every new tenant's database runs this migration too, so **every newly provisioned tenant starts with this placeholder branding** (name, tagline, colors). This is intentional for this plan (changing it would break existing tests that assert on the seeded values — see `tests/Feature/Admin/ClubSettingManagementTest.php`). Whoever provisions a real tenant edits the club identity via `admin.club-settings.edit` right after creation, same as it works today. Not a bug to fix in this plan.
2. **`AppServiceProvider::overrideMailConfigFromDatabase()`** currently runs once at request boot, reading `MailSetting::current()` off whatever the default `sqlite` connection happens to be — before any tenant is resolved. In the multi-tenant world this would read the wrong (or no) tenant's mail settings. Task 2 moves this logic into `TenantContext::use()` so it re-applies every time the active tenant changes. The existing direct-provider test (`tests/Feature/MailSettingConfigOverrideTest.php`) is adapted accordingly in Task 2, not deleted-and-forgotten.
3. **Route guard**: the existing `admin.*` route group's `auth` middleware checks the `web` guard only. For super-admin impersonation (Task 9) to reuse these same routes, that middleware becomes `auth:web,super_admin` — but only once Task 9 lands, once the `super_admin` guard actually exists (Task 6). Making that change any earlier (e.g. in Task 4) breaks every currently-passing "guest redirected to login" test: Laravel's `Authenticate` middleware calls `Auth::guard($name)` for every guard in a comma-separated list and throws `InvalidArgumentException` on an undefined guard name instead of skipping it, turning an expected 302 into a 500. No existing controller or view calls `Auth::user()`/`auth()->user()`/`Auth::id()` (verified by grep), so accepting either guard, once both exist, is safe — nothing downstream assumes a `web`-guard user.

---

### Task 1: Central connection, `tenants` table, `Tenant` model

**Files:**
- Modify: `config/database.php`
- Modify: `.env`, `.env.example`
- Modify: `phpunit.xml`
- Create: `database/migrations/2026_07_23_090000_create_tenants_table.php`
- Create: `app/Models/Tenant.php`
- Create: `database/factories/TenantFactory.php`
- Test: `tests/Feature/Models/TenantTest.php`

**Interfaces:**
- Produces: `App\Models\Tenant` — Eloquent model on connection `central`, fillable `name`, `host`, `sqlite_path`. `TenantFactory` produces valid rows with a unique `host` and a `sqlite_path` pointing at a real temp file location (`storage/framework/testing/tenants/{uuid}.sqlite`).

- [ ] **Step 1: Add the `central` connection to `config/database.php`**

In `config/database.php`, inside `'connections' => [ ... ]`, add a new entry right after the existing `'sqlite'` entry:

```php
        'central' => [
            'driver' => 'sqlite',
            'database' => env('DB_DATABASE_CENTRAL', database_path('data/central.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
            'transaction_mode' => 'DEFERRED',
        ],
```

- [ ] **Step 2: Pin `DB_DATABASE_CENTRAL` and a deterministic `APP_URL` for tests**

In `phpunit.xml`, inside `<php>`, add (keep existing entries — including `DB_DATABASE value=":memory:"` — unchanged; only add these):

```xml
        <env name="APP_URL" value="http://localhost"/>
        <env name="DB_DATABASE_CENTRAL" value=":memory:"/>
        <env name="SUPER_ADMIN_HOST" value="admin.localhost"/>
```

`APP_URL` is pinned explicitly so tests don't depend on whatever `APP_URL` a developer has in their local `.env` — the default test tenant created in Task 3 will use `host: 'localhost'` to match this. `DB_DATABASE` (the main `sqlite` connection) deliberately stays `:memory:`, exactly as it is today, for every test *except* the handful in Tasks 8–10 that need a second, independently-queryable tenant database — Task 3 explains why those few need a completely separate test setup instead of changing this shared one.

- [ ] **Step 3: Document the new env vars in `.env.example`**

Add after the existing `DB_*` lines in `.env.example`:

```
DB_DATABASE_CENTRAL="${APP_BASE_PATH}/database/data/central.sqlite"
SUPER_ADMIN_HOST=admin.example.test
```

Add the same two lines (with real values) to `.env` — use `database/data/central.sqlite` relative path resolution (the app already does this for the main DB per `docker/entrypoint.sh`), so just add:

```
DB_DATABASE_CENTRAL=/home/fifonsi/PhpstormProjects/presence-rotary/database/data/central.sqlite
SUPER_ADMIN_HOST=admin.localhost
```

(matches how `DB_DATABASE` is already set as an absolute path in this project's `.env`; check the existing `DB_DATABASE` line and mirror its style).

- [ ] **Step 4: Create the `tenants` migration in its own `central/` migration path**

Create the directory `database/migrations/central/` and put this migration there — **not** in the top-level `database/migrations/` alongside the tenant-schema migrations. This was verified empirically while writing this plan: Laravel's migration *bookkeeping* (which migrations have already run) always lives on whichever connection `--database` points to for that specific `artisan migrate` invocation — it is **not** per-migration, even though the DDL itself correctly respects each migration's own `protected $connection`. If `tenants`/`super_admins` migrations lived in the same top-level folder as the tenant-schema migrations, every `php artisan migrate --database=sqlite` run against a *new* tenant file (which has no migration history of its own) would see the central-connection migrations as "still pending" and try to re-run them — failing with "table already exists" against the already-migrated `central` database. Laravel's default migration path (`database/migrations`) is scanned non-recursively, so simply keeping `central`'s migrations in their own subdirectory keeps them out of every plain `migrate --database=sqlite` call automatically; they're only picked up when explicitly pointed at via `--path=database/migrations/central`.

Create `database/migrations/central/2026_07_23_090000_create_tenants_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('central')->create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('host')->unique();
            $table->string('sqlite_path')->unique();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('central')->dropIfExists('tenants');
    }
};
```

The `protected $connection = 'central'` property (combined with the explicit `Schema::connection('central')` calls in `up()`/`down()`) guarantees the actual DDL runs against `central` regardless of whatever connection is currently the default when `artisan migrate` runs. It does **not**, by itself, change which connection's `migrations` bookkeeping table records this migration as having run — that's controlled entirely by the `--database` flag passed to the `migrate` command, which is exactly why this migration lives in its own `central/` subdirectory (see above) rather than relying on the property to keep it out of tenant-scoped migrate runs.

- [ ] **Step 5: Create the `Tenant` model**

Create `app/Models/Tenant.php`:

```php
<?php

namespace App\Models;

use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    protected $connection = 'central';

    protected $fillable = ['name', 'host', 'sqlite_path'];
}
```

- [ ] **Step 6: Create the `TenantFactory`**

Create `database/factories/TenantFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $slug = Str::slug(fake()->unique()->company());
        $directory = storage_path('framework/testing/tenants');

        if (! is_dir($directory)) {
            mkdir($directory, recursive: true);
        }

        return [
            'name' => fake()->company(),
            'host' => "{$slug}.example.test",
            'sqlite_path' => $directory.'/'.Str::uuid().'.sqlite',
        ];
    }
}
```

The factory ensures its own target directory exists — tests that need a *real*, independently migratable tenant database (Tasks 9 and 10) can just call `Tenant::factory()->create()` without overriding `sqlite_path` and get a unique, ready-to-use file path for free.

- [ ] **Step 7: Write the failing test**

Create `tests/Feature/Models/TenantTest.php`:

```php
<?php

use App\Models\Tenant;

it('persists a tenant on the central connection', function () {
    $tenant = Tenant::factory()->create(['host' => 'club1.example.test']);

    expect($tenant->getConnectionName())->toBe('central')
        ->and(Tenant::where('host', 'club1.example.test')->exists())->toBeTrue();
});

it('requires a unique host', function () {
    Tenant::factory()->create(['host' => 'club1.example.test']);

    expect(fn () => Tenant::factory()->create(['host' => 'club1.example.test']))
        ->toThrow(Illuminate\Database\QueryException::class);
});
```

- [ ] **Step 8: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/Models/TenantTest.php`
Expected: FAIL — `central` connection/migration doesn't exist yet as far as the test runner's schema is concerned (no migration has run against it in the test process). This is expected since `RefreshDatabase` hasn't been taught about `central` yet (that's Task 3) — for now, just confirm the class autoloads and the failure is a database/table error, not a class-not-found error.

- [ ] **Step 9: Run migrations locally to sanity-check the migration file**

Run: `php artisan migrate --database=central --path=database/migrations/central --force`
Expected: `INFO  Running migrations.` then `2026_07_23_090000_create_tenants_table ... DONE`. Then roll it back to leave the repo clean: `php artisan migrate:rollback --database=central --path=database/migrations/central --force`.

A full green test run happens in Task 3, once `tests/TestCase.php` explicitly migrates `central` for every test — that's expected and fine for now.

- [ ] **Step 10: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add config/database.php .env.example phpunit.xml tests/Pest.php database/migrations/2026_07_23_090000_create_tenants_table.php app/Models/Tenant.php database/factories/TenantFactory.php tests/Feature/Models/TenantTest.php
git commit -m "feat: add central DB connection and tenants table"
```

(Do not commit `.env` — it's gitignored; the step above already documents what to add to it.)

---

### Task 2: `TenantContext` service (DB switch + mail config)

**Files:**
- Create: `app/Services/TenantContext.php`
- Modify: `app/Providers/AppServiceProvider.php` (remove `overrideMailConfigFromDatabase`, register `TenantContext` singleton)
- Modify: `tests/Feature/MailSettingConfigOverrideTest.php` (adapt to test through `TenantContext::use()` instead of the removed provider method)
- Test: `tests/Feature/Services/TenantContextTest.php`

**Interfaces:**
- Consumes: `App\Models\Tenant` (Task 1), `App\Models\MailSetting::current()` (existing).
- Produces: `App\Services\TenantContext` with `use(Tenant $tenant): void`, `current(): ?Tenant`, `clear(): void` — every later task (middleware, jobs, dashboard, provisioning) resolves this via constructor/method injection (`app(TenantContext::class)` or a typed parameter) and calls these three methods only.

- [ ] **Step 1: Write the failing unit tests**

Create `tests/Feature/Services/TenantContextTest.php`:

```php
<?php

use App\Models\MailSetting;
use App\Models\Tenant;
use App\Services\TenantContext;
use Illuminate\Support\Facades\DB;

it('switches the sqlite connection to the tenant path and back', function () {
    $tenantContext = app(TenantContext::class);
    $originalPath = config('database.connections.sqlite.database');

    $tenant = Tenant::factory()->make(['sqlite_path' => database_path('data/tenants/switch-test.sqlite')]);

    $tenantContext->use($tenant);

    expect(config('database.connections.sqlite.database'))->toBe($tenant->sqlite_path)
        ->and($tenantContext->current())->toBe($tenant);

    config(['database.connections.sqlite.database' => $originalPath]);
    DB::purge('sqlite');
});

it('does not purge the connection when switching to the already-active path', function () {
    $tenantContext = app(TenantContext::class);
    $activePath = config('database.connections.sqlite.database');

    DB::connection('sqlite')->statement('CREATE TABLE IF NOT EXISTS switch_marker (id INTEGER)');
    DB::connection('sqlite')->table('switch_marker')->insert(['id' => 1]);

    $tenant = Tenant::factory()->make(['sqlite_path' => $activePath]);
    $tenantContext->use($tenant);

    expect(DB::connection('sqlite')->table('switch_marker')->count())->toBe(1);
});

it('clears the current tenant', function () {
    $tenantContext = app(TenantContext::class);
    $tenantContext->use(Tenant::factory()->make(['sqlite_path' => config('database.connections.sqlite.database')]));

    $tenantContext->clear();

    expect($tenantContext->current())->toBeNull();
});

it('leaves mail config untouched when the tenant has no mail settings row', function () {
    app(TenantContext::class)->use(Tenant::factory()->make(['sqlite_path' => config('database.connections.sqlite.database')]));

    expect(config('mail.default'))->toBe('array')
        ->and(MailSetting::current())->toBeNull();
});

it('applies the tenant mail settings when a row exists', function () {
    MailSetting::create([
        'host' => 'smtp.custom.test',
        'port' => 2526,
        'username' => 'custom-user',
        'password' => 'custom-pass',
        'encryption' => 'ssl',
        'from_address' => 'custom@example.com',
        'from_name' => 'Custom Sender',
    ]);

    app(TenantContext::class)->use(Tenant::factory()->make(['sqlite_path' => config('database.connections.sqlite.database')]));

    expect(config('mail.default'))->toBe('smtp')
        ->and(config('mail.mailers.smtp.host'))->toBe('smtp.custom.test')
        ->and(config('mail.mailers.smtp.scheme'))->toBe('smtps')
        ->and(config('mail.from.name'))->toBe('Custom Sender');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Services/TenantContextTest.php`
Expected: FAIL — `Class "App\Services\TenantContext" not found`.

- [ ] **Step 3: Implement `TenantContext`**

Create `app/Services/TenantContext.php`:

```php
<?php

namespace App\Services;

use App\Models\MailSetting;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TenantContext
{
    private ?Tenant $current = null;

    public function use(Tenant $tenant): void
    {
        if (config('database.connections.sqlite.database') !== $tenant->sqlite_path) {
            config(['database.connections.sqlite.database' => $tenant->sqlite_path]);
            DB::purge('sqlite');
        }

        $this->current = $tenant;

        $this->applyMailSettings();
    }

    public function current(): ?Tenant
    {
        return $this->current;
    }

    public function clear(): void
    {
        $this->current = null;
    }

    private function applyMailSettings(): void
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
            'mail.mailers.smtp.scheme' => match ($mailSetting->encryption) {
                'ssl' => 'smtps',
                'tls' => 'smtp',
                default => null,
            },
            'mail.from.address' => $mailSetting->from_address,
            'mail.from.name' => $mailSetting->from_name,
        ]);
    }
}
```

- [ ] **Step 4: Register it as a singleton**

In `app/Providers/AppServiceProvider.php`, remove the `overrideMailConfigFromDatabase()` method and its call in `boot()`, and register the singleton in `register()`:

```php
<?php

namespace App\Providers;

use App\Services\TenantContext;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }
    }
}
```

- [ ] **Step 5: Run the new tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Services/TenantContextTest.php`
Expected: PASS (5 tests).

- [ ] **Step 6: Adapt the now-broken mail config override test**

`tests/Feature/MailSettingConfigOverrideTest.php` currently instantiates `AppServiceProvider` and calls the removed method directly — replace its body to exercise the same behavior via `TenantContext::use()`. Replace the full file content:

```php
<?php

use App\Models\MailSetting;
use App\Models\Tenant;
use App\Services\TenantContext;

function switchToTenantUsingCurrentSqliteFile(): void
{
    app(TenantContext::class)->use(
        Tenant::factory()->make(['sqlite_path' => config('database.connections.sqlite.database')])
    );
}

it('leaves the default mail config untouched when no MailSetting row exists', function () {
    switchToTenantUsingCurrentSqliteFile();

    expect(config('mail.default'))->toBe('array')
        ->and(config('mail.mailers.smtp.host'))->toBe('127.0.0.1')
        ->and((int) config('mail.mailers.smtp.port'))->toBe(2525)
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

    switchToTenantUsingCurrentSqliteFile();

    expect(config('mail.default'))->toBe('smtp')
        ->and(config('mail.mailers.smtp.host'))->toBe('smtp.custom.test')
        ->and((int) config('mail.mailers.smtp.port'))->toBe(2526)
        ->and(config('mail.mailers.smtp.username'))->toBe('custom-user')
        ->and(config('mail.mailers.smtp.password'))->toBe('custom-pass')
        ->and(config('mail.mailers.smtp.scheme'))->toBe('smtps')
        ->and(config('mail.from.address'))->toBe('custom@example.com')
        ->and(config('mail.from.name'))->toBe('Custom Sender');
});

it('maps tls encryption to the smtp scheme', function () {
    MailSetting::create([
        'host' => 'smtp.custom.test',
        'port' => 2526,
        'username' => 'custom-user',
        'password' => 'custom-pass',
        'encryption' => 'tls',
        'from_address' => 'custom@example.com',
        'from_name' => 'Custom Sender',
    ]);

    switchToTenantUsingCurrentSqliteFile();

    expect(config('mail.mailers.smtp.scheme'))->toBe('smtp');
});

it('maps null encryption to a null scheme', function () {
    MailSetting::create([
        'host' => 'smtp.custom.test',
        'port' => 2526,
        'username' => 'custom-user',
        'password' => 'custom-pass',
        'encryption' => null,
        'from_address' => 'custom@example.com',
        'from_name' => 'Custom Sender',
    ]);

    switchToTenantUsingCurrentSqliteFile();

    expect(config('mail.mailers.smtp.scheme'))->toBeNull();
});
```

This keeps the exact same four behaviors under test, driven through the new relocated code path instead of the deleted method.

- [ ] **Step 7: Run the full test file to verify it passes**

Run: `php artisan test --compact tests/Feature/MailSettingConfigOverrideTest.php`
Expected: PASS (4 tests).

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/TenantContext.php app/Providers/AppServiceProvider.php tests/Feature/Services/TenantContextTest.php tests/Feature/MailSettingConfigOverrideTest.php
git commit -m "feat: add TenantContext service, relocate mail config override onto it"
```

---

### Task 3: Test-suite bootstrap for the default tenant, plus an isolated harness for cross-tenant tests

**Files:**
- Modify: `tests/TestCase.php`
- Create: `tests/TenancyTestCase.php`
- Create: `tests/Tenancy/` (empty directory for now — Tasks 8–10 add files here)
- Modify: `phpunit.xml` (add a `Tenancy` testsuite)
- Modify: `tests/Pest.php` (bind `TenancyTestCase` to the new `Tenancy` directory, deliberately *without* `RefreshDatabase`)
- Modify: `composer.json` (default `composer test` runs only `Unit,Feature`)
- Test: run the **full existing suite** to prove nothing broke.

**Interfaces:**
- Consumes: `App\Models\Tenant`, `App\Services\TenantContext` (Tasks 1–2).
- Produces: every test in `tests/Feature` runs with a resolved "current tenant" (`host: 'localhost'`, `sqlite_path: ':memory:'`) without any test file needing to know this exists. A separate `Tests\TenancyTestCase`, used only by the handful of tests in Tasks 8–10 that need a second, independently-queryable tenant database, living in `tests/Tenancy/` and run via a separate `php artisan test --testsuite=Tenancy` invocation.

**Why two test cases in two directories, not one:** three things were verified empirically while writing this plan, in this order:

1. Switching the shared `sqlite` connection away from `:memory:` mid-test — even briefly, even carefully restoring the config value afterward — permanently destroys that in-memory database (reconnecting to `:memory:` always opens a *fresh, empty* database) and corrupts every *other* test sharing the same PHP process afterward (`no such table: users`, `cannot start a transaction within a transaction`, `cannot VACUUM from within a transaction`).
2. Making the *entire* suite's base connection a real file instead avoids that corruption, but was measured to make the full ~212-test suite take **over 15 minutes** in this environment (vs. ~5 seconds with `:memory:`) — not an acceptable trade-off for the 200+ tests that never touch a second tenant.
3. A dedicated test case for just the cross-tenant tests, using its own fresh real file per test and **not** using `RefreshDatabase` at all (explicitly running `migrate` itself instead), keeps the fast suite fast and the cross-tenant tests correct — but only once its migration bookkeeping doesn't collide with the shared `sqlite`-connection migrations (see the `central`-migration-path note in Task 1) and only when it lives in its own Pest-bound directory: Pest raises a hard error ("already uses the test case [...]") if two different `uses()`/`extend()` bindings both try to claim the same file, so the cross-tenant tests cannot simply be nested inside `tests/Feature/...` — they need their own top-level `tests/Tenancy/` directory with its own binding, exactly mirroring how `tests/Feature` and `tests/Unit` already coexist as siblings in `phpunit.xml`.

- [ ] **Step 1: Update `TestCase` to migrate `central` and provision a default tenant per test**

Replace the contents of `tests/TestCase.php`:

```php
<?php

namespace Tests;

use App\Models\Tenant;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--database' => 'central',
            '--path' => 'database/migrations/central',
            '--force' => true,
        ]);

        $tenant = Tenant::factory()->create([
            'host' => 'localhost',
            'sqlite_path' => ':memory:',
        ]);

        app(TenantContext::class)->use($tenant);
    }
}
```

Two things worth flagging, both verified empirically:

- The explicit `central` migrate call is necessary — `RefreshDatabase` only migrates the connection that's *already* the app default (`sqlite`), and only scans the default, non-recursive `database/migrations` path, so it never discovers `database/migrations/central` on its own. Without this line, every single test would fail on `Tenant::factory()->create()` with "no such table: tenants".
- Unlike the main `sqlite`/`:memory:` connection (which `RefreshDatabase` keeps alive across each test's fresh application container, by design, precisely so it doesn't need re-migrating every time), the `central`/`:memory:` connection does **not** get that treatment automatically — it was verified that skipping this call after the first test (e.g., via a `static` "already migrated" guard) makes every test after the first fail with "no such table" again. Migrating it every test is cheap (measured: adds well under a second across the entire 212-test suite) — cheaper than trying to be clever about it.

`sqlite_path: ':memory:'` matches `config('database.connections.sqlite.database')` (still `:memory:` — Task 1 didn't change it), so `TenantContext::use()`'s equality check skips the `DB::purge('sqlite')` call — the schema `RefreshDatabase` already migrated into that in-memory database stays intact.

- [ ] **Step 2: Create `tests/Tenancy/` and `TenancyTestCase`**

Create the (for now empty) directory `tests/Tenancy/` — Tasks 8, 9 and 10 add test files here.

Create `tests/TenancyTestCase.php`:

```php
<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TenancyTestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $uniqueId = uniqid();
        $sqlitePath = base_path("storage/framework/testing/tenancy-sqlite-{$uniqueId}.sqlite");
        $centralPath = base_path("storage/framework/testing/tenancy-central-{$uniqueId}.sqlite");

        foreach ([$sqlitePath, $centralPath] as $path) {
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), recursive: true);
            }

            touch($path);
        }

        config(['database.connections.sqlite.database' => $sqlitePath]);
        config(['database.connections.central.database' => $centralPath]);
        DB::purge('sqlite');
        DB::purge('central');

        $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true]);
        $this->artisan('migrate', [
            '--database' => 'central',
            '--path' => 'database/migrations/central',
            '--force' => true,
        ]);
    }
}
```

This extends Laravel's base test case **directly** (not `Tests\TestCase`) — it deliberately does *not* inherit the `:memory:`-based default-tenant bootstrap, and does *not* use the `RefreshDatabase` trait at all. Every test gets its own brand-new, uniquely-named real SQLite file for *both* connections, migrated explicitly. Real files (unlike `:memory:`) persist correctly no matter how many times the `sqlite` connection gets purged and repointed within the test body — which is exactly what Tasks 8–10 need to do to create and switch between additional tenants. Nothing needs to be undone in a `tearDown()`: each test's files are only ever read by that test (and by whatever tenant registry rows it creates), and are never shared with the fast `Tests\TestCase`-driven suite, since that suite runs in a completely separate process.

- [ ] **Step 3: Add the `Tenancy` testsuite to `phpunit.xml`**

In `phpunit.xml`, add a third testsuite alongside the existing `Unit` and `Feature` ones:

```xml
        <testsuite name="Tenancy">
            <directory>tests/Tenancy</directory>
        </testsuite>
```

- [ ] **Step 4: Bind `TenancyTestCase` to the new directory in `tests/Pest.php`**

In `tests/Pest.php`, add a second `pest()->extend(...)` call right after the existing one — note there is **no** `->use(RefreshDatabase::class)` here, deliberately:

```php
pest()->extend(Tests\TenancyTestCase::class)
    ->in('Tenancy');
```

- [ ] **Step 5: Make the default `composer test` run skip the `Tenancy` suite**

In `composer.json`, update the `"test"` script:

```json
        "test": [
            "@php artisan config:clear --ansi @no_additional_args",
            "@php artisan test --testsuite=Unit,Feature"
        ],
```

Running the cross-tenant tests is a separate, explicit command: `php artisan test --testsuite=Tenancy` — slower (real file I/O, ~5–7 seconds for a handful of tests, measured) but safe to run in its own process without ever touching the fast default suite. Wire this in as a second CI step whenever CI is set up for this repo (out of scope for this plan — no `.github` workflow exists yet).

- [ ] **Step 6: Run the full suite**

Run: `php artisan test --compact --testsuite=Unit,Feature`
Expected: PASS, same test count as before this plan started (no regressions), and no meaningful slowdown (still `:memory:`-backed, migrating `central` on top costs well under a second across the whole run). If any test fails, read the failure — it almost always means a test created its own `Tenant`/touched the `central` connection in a way that collides with this default (unlikely at this point since no other task has touched routes yet); fix by making that test's own tenant use a distinct `host`.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add tests/TestCase.php tests/TenancyTestCase.php tests/Tenancy phpunit.xml tests/Pest.php composer.json
git commit -m "test: bootstrap a default tenant for every test, isolate cross-tenant tests in their own suite"
```

---

### Task 4: `ResolveTenant` middleware

**Files:**
- Create: `app/Http/Middleware/ResolveTenant.php`
- Modify: `bootstrap/app.php`
- Modify: `routes/web.php` (wrap existing routes in the middleware group)
- Test: `tests/Feature/TenantResolutionTest.php`

**Interfaces:**
- Consumes: `App\Models\Tenant`, `App\Services\TenantContext`.
- Produces: any request whose `Host` doesn't match a row in `tenants` gets a 404, before reaching any controller.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/TenantResolutionTest.php`:

```php
<?php

use App\Models\Tenant;

it('serves the public check-in page for a known tenant host', function () {
    $this->get('http://localhost/')->assertOk();
});

it('returns 404 for an unknown host', function () {
    $this->get('http://unknown-host.example.test/')->assertNotFound();
});

it('returns 404 for the admin login page on an unknown host', function () {
    $this->get('http://unknown-host.example.test/admin/login')->assertNotFound();
});
```

Note: the first test deliberately reuses `localhost` — the *same* host `tests/TestCase.php` (Task 3) already registers as the default tenant — instead of creating a second `Tenant` row. Two things rule out a second row: `tenants.sqlite_path` has a `unique()` constraint (Task 1), so a second tenant can't share the default's `:memory:` path; and giving it a genuinely different real path would make `TenantContext::use()` purge the `sqlite` connection away from `:memory:` mid-test, which is exactly the corruption Task 3's whole design exists to keep out of the fast `Feature` suite. Reusing `localhost` needs neither. It still meaningfully proves host-based resolution is working, not just "requests always succeed": paired with the next two tests (unknown host → 404), the only way all three tests pass is if the middleware is actually resolving by host — a build with no middleware at all would make test 1 pass but tests 2–3 fail (everything would 200), and a build that always resolves *some* tenant regardless of host would make tests 2–3 fail the same way.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/TenantResolutionTest.php`
Expected: 1 pass, 2 fail. The first test (`localhost`) already passes — nothing blocks that request even without the middleware. The two "unknown host" tests fail (200 instead of 404) since there's no middleware yet to enforce host-based resolution. Both failures resolve once the middleware lands in the next step; the "known host" test isn't testing something new, it's guarding against a regression once the middleware exists.

- [ ] **Step 3: Implement the middleware**

Create `app/Http/Middleware/ResolveTenant.php`:

```php
<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Tenant::where('host', $request->getHost())->first();

        abort_if($tenant === null, 404);

        $this->tenantContext->use($tenant);

        return $next($request);
    }
}
```

- [ ] **Step 4: Wire the middleware into `routes/web.php`**

Wrap the existing public + admin routes (everything currently in the file) in a `Route::middleware(ResolveTenant::class)->group(...)`. Replace the full file content of `routes/web.php`:

```php
<?php

use App\Http\Controllers\Admin\AttendanceController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\CheckinSettingController;
use App\Http\Controllers\Admin\ClubSettingController;
use App\Http\Controllers\Admin\MailSettingController;
use App\Http\Controllers\Admin\MeetingSessionController;
use App\Http\Controllers\Admin\MemberController;
use App\Http\Controllers\Admin\PositionController;
use App\Http\Controllers\Admin\TitleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AttendanceFormController;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Support\Facades\Route;

Route::middleware(ResolveTenant::class)->group(function () {
    Route::get('/', [AttendanceFormController::class, 'show'])->name('attendance.show');
    Route::post('/check-in', [AttendanceFormController::class, 'lookup'])->name('attendance.lookup');
    Route::post('/attendances', [AttendanceFormController::class, 'store'])->name('attendance.store');

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::middleware('guest')->group(function () {
            Route::get('login', [AuthController::class, 'create'])->name('login');
            Route::post('login', [AuthController::class, 'store'])->name('login.store');
        });

        Route::middleware('auth')->group(function () {
            Route::post('logout', [AuthController::class, 'destroy'])->name('logout');
            Route::get('sessions', [MeetingSessionController::class, 'index'])->name('sessions.index');
            Route::post('sessions', [MeetingSessionController::class, 'store'])->name('sessions.store');
            Route::post('sessions/{meetingSession}/toggle-open', [MeetingSessionController::class, 'toggleOpen'])->name('sessions.toggle-open');
            Route::get('sessions/{meetingSession}', [MeetingSessionController::class, 'show'])->name('sessions.show');
            Route::get('sessions/{meetingSession}/export-pdf', [MeetingSessionController::class, 'exportPdf'])->name('sessions.export-pdf');
            Route::patch('attendances/{attendance}/toggle-present', [AttendanceController::class, 'togglePresent'])->name('attendances.toggle-present');
            Route::get('users', [UserController::class, 'index'])->name('users.index');
            Route::get('users/create', [UserController::class, 'create'])->name('users.create');
            Route::post('users', [UserController::class, 'store'])->name('users.store');
            Route::get('members', [MemberController::class, 'index'])->name('members.index');
            Route::get('members/{member}', [MemberController::class, 'show'])->name('members.show');
            Route::get('members/{member}/edit', [MemberController::class, 'edit'])->name('members.edit');
            Route::put('members/{member}', [MemberController::class, 'update'])->name('members.update');
            Route::get('titles', [TitleController::class, 'index'])->name('titles.index');
            Route::get('titles/create', [TitleController::class, 'create'])->name('titles.create');
            Route::post('titles', [TitleController::class, 'store'])->name('titles.store');
            Route::get('titles/{title}/edit', [TitleController::class, 'edit'])->name('titles.edit');
            Route::put('titles/{title}', [TitleController::class, 'update'])->name('titles.update');
            Route::patch('titles/{title}/toggle-active', [TitleController::class, 'toggleActive'])->name('titles.toggle-active');
            Route::patch('titles/{title}/move-order/{direction}', [TitleController::class, 'moveOrder'])->name('titles.move-order');
            Route::delete('titles/{title}', [TitleController::class, 'destroy'])->name('titles.destroy');
            Route::get('positions', [PositionController::class, 'index'])->name('positions.index');
            Route::get('positions/create', [PositionController::class, 'create'])->name('positions.create');
            Route::post('positions', [PositionController::class, 'store'])->name('positions.store');
            Route::get('positions/{position}/edit', [PositionController::class, 'edit'])->name('positions.edit');
            Route::put('positions/{position}', [PositionController::class, 'update'])->name('positions.update');
            Route::patch('positions/{position}/toggle-active', [PositionController::class, 'toggleActive'])->name('positions.toggle-active');
            Route::patch('positions/{position}/move-order/{direction}', [PositionController::class, 'moveOrder'])->name('positions.move-order');
            Route::delete('positions/{position}', [PositionController::class, 'destroy'])->name('positions.destroy');
            Route::get('mail-settings', [MailSettingController::class, 'edit'])->name('mail-settings.edit');
            Route::put('mail-settings', [MailSettingController::class, 'update'])->name('mail-settings.update');
            Route::post('mail-settings/test', [MailSettingController::class, 'sendTest'])->name('mail-settings.test');
            Route::get('checkin-settings', [CheckinSettingController::class, 'edit'])->name('checkin-settings.edit');
            Route::put('checkin-settings', [CheckinSettingController::class, 'update'])->name('checkin-settings.update');
            Route::get('club-settings', [ClubSettingController::class, 'edit'])->name('club-settings.edit');
            Route::put('club-settings', [ClubSettingController::class, 'update'])->name('club-settings.update');
        });
    });
});
```

Only one change from the original file: the whole body is now wrapped in `Route::middleware(ResolveTenant::class)->group(...)`. The `admin.*` group's `auth` middleware stays `'auth'` (plain `web` guard) for now — Task 9 changes it to `'auth:web,super_admin'` once the `super_admin` guard actually exists (Task 6). Doing that swap here instead would break immediately: Laravel's `Authenticate` middleware calls `Auth::guard($name)` for every guard in the list and throws `InvalidArgumentException` on an undefined guard name rather than skipping it — turning every "guest redirected to login" test's expected 302 into a 500. This was caught by an implementer subagent running the full suite, not anticipated in the original design.

- [ ] **Step 5: Register the middleware alias (not required, but confirm route-level class reference resolves)**

`Route::middleware(ResolveTenant::class)` references the class directly, so no alias registration in `bootstrap/app.php` is required. Open `bootstrap/app.php` and confirm it still only contains the existing `redirectGuestsTo` and `trustProxies` calls — no change needed there for this task.

- [ ] **Step 6: Run the new test to verify it passes**

Run: `php artisan test --compact tests/Feature/TenantResolutionTest.php`
Expected: PASS (3 tests).

- [ ] **Step 7: Run the full suite**

Run: `php artisan test --compact --testsuite=Unit,Feature`
Expected: PASS — every existing test hits `http://localhost/...` by default, which now resolves to the default tenant created in `TestCase::setUp()` (Task 3), so no other test file needs changes.

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Middleware/ResolveTenant.php routes/web.php tests/Feature/TenantResolutionTest.php
git commit -m "feat: resolve the active tenant from the request host"
```

---

### Task 5: Per-tenant public storage path

**Files:**
- Modify: `app/Http/Controllers/Admin/ClubSettingController.php`
- Modify: `tests/Feature/Admin/ClubSettingManagementTest.php`

**Interfaces:**
- Consumes: `App\Services\TenantContext::current()`.
- Produces: club logos are now stored under `tenants/{tenant_id}/club/...` instead of `club/...`.

- [ ] **Step 1: Update the failing assertion first**

In `tests/Feature/Admin/ClubSettingManagementTest.php`, the "uploads and stores a new logo" test currently asserts `Storage::disk('public')->assertMissing('club/old-logo.png')` after seeding `'logo_path' => 'club/old-logo.png'`. Update the seeded path and assertions to the tenant-scoped shape. Replace that test's body:

```php
it('uploads and stores a new logo, replacing the previous file', function () {
    Storage::fake('public');

    $tenantId = app(App\Services\TenantContext::class)->current()->id;
    $oldPath = "tenants/{$tenantId}/club/old-logo.png";

    $clubSetting = ClubSetting::current();
    $clubSetting->update(['logo_path' => $oldPath]);
    Storage::disk('public')->put($oldPath, 'fake-image-content');

    $this->actingAs(User::factory()->create())
        ->put(route('admin.club-settings.update'), [
            'name' => $clubSetting->name,
            'primary_color' => $clubSetting->primary_color,
            'secondary_color' => $clubSetting->secondary_color,
            'logo' => UploadedFile::fake()->image('logo.png'),
        ])->assertRedirect(route('admin.club-settings.edit'));

    Storage::disk('public')->assertMissing($oldPath);

    $newPath = ClubSetting::current()->logo_path;

    expect($newPath)->not->toBeNull()
        ->and($newPath)->toStartWith("tenants/{$tenantId}/club/");
    Storage::disk('public')->assertExists($newPath);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/Admin/ClubSettingManagementTest.php`
Expected: FAIL on the new `toStartWith` assertion — the controller still stores under `club/...`.

- [ ] **Step 3: Update the controller**

In `app/Http/Controllers/Admin/ClubSettingController.php`, inject `TenantContext` and scope the store path. Replace the full file:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateClubSettingRequest;
use App\Models\ClubSetting;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class ClubSettingController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

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

            $tenantId = $this->tenantContext->current()->id;
            $data['logo_path'] = $request->file('logo')->store("tenants/{$tenantId}/club", 'public');
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

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/Admin/ClubSettingManagementTest.php`
Expected: PASS (all tests in the file).

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Admin/ClubSettingController.php tests/Feature/Admin/ClubSettingManagementTest.php
git commit -m "feat: scope club logo storage path per tenant"
```

---

### Task 6: `super_admins` table, model, guard, and `config/tenancy.php`

**Files:**
- Create: `database/migrations/central/2026_07_23_090100_create_super_admins_table.php`
- Create: `app/Models/SuperAdmin.php`
- Create: `database/factories/SuperAdminFactory.php`
- Create: `config/tenancy.php`
- Modify: `config/auth.php`
- Test: `tests/Feature/Models/SuperAdminTest.php`

**Interfaces:**
- Produces: `App\Models\SuperAdmin` (connection `central`, guard `super_admin`), `config('tenancy.super_admin_host')`.

- [ ] **Step 1: Create the migration**

Create `database/migrations/central/2026_07_23_090100_create_super_admins_table.php` — in the `central/` subdirectory alongside the `tenants` migration from Task 1, for the same reason (see Task 1's note on migration bookkeeping):

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('central')->create('super_admins', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('central')->dropIfExists('super_admins');
    }
};
```

- [ ] **Step 2: Create the `SuperAdmin` model**

Create `app/Models/SuperAdmin.php`:

```php
<?php

namespace App\Models;

use Database\Factories\SuperAdminFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class SuperAdmin extends Authenticatable
{
    /** @use HasFactory<SuperAdminFactory> */
    use HasFactory, Notifiable;

    protected $connection = 'central';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }
}
```

- [ ] **Step 3: Create the factory**

Create `database/factories/SuperAdminFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\SuperAdmin;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<SuperAdmin>
 */
class SuperAdminFactory extends Factory
{
    protected $model = SuperAdmin::class;

    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }
}
```

- [ ] **Step 4: Create `config/tenancy.php`**

Create `config/tenancy.php`:

```php
<?php

return [
    'super_admin_host' => env('SUPER_ADMIN_HOST', 'admin.example.test'),
];
```

- [ ] **Step 5: Add the `super_admin` guard and provider**

In `config/auth.php`, add `App\Models\SuperAdmin` to the `use` imports, and add the guard/provider entries. Replace the top of the file and the two arrays:

```php
<?php

use App\Models\SuperAdmin;
use App\Models\User;

return [

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],

        'super_admin' => [
            'driver' => 'session',
            'provider' => 'super_admins',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', User::class),
        ],

        'super_admins' => [
            'driver' => 'eloquent',
            'model' => SuperAdmin::class,
        ],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
```

(Keep the doc comments from the original file if your editor preserves them; only the arrays' contents matter for behavior.)

- [ ] **Step 6: Write the failing test**

Create `tests/Feature/Models/SuperAdminTest.php`:

```php
<?php

use App\Models\SuperAdmin;
use Illuminate\Support\Facades\Auth;

it('persists on the central connection', function () {
    $superAdmin = SuperAdmin::factory()->create();

    expect($superAdmin->getConnectionName())->toBe('central');
});

it('authenticates through the super_admin guard', function () {
    $superAdmin = SuperAdmin::factory()->create();

    Auth::guard('super_admin')->login($superAdmin);

    expect(Auth::guard('super_admin')->check())->toBeTrue()
        ->and(Auth::guard('web')->check())->toBeFalse();
});
```

- [ ] **Step 7: Run the test to verify it fails, then implement, then pass**

Run: `php artisan test --compact tests/Feature/Models/SuperAdminTest.php`
Expected first: FAIL (`Class "App\Models\SuperAdmin" not found` — nothing from steps 1–5 exists yet).

After confirming the model/migration/factory/config from steps 1–5 above are in place, re-run:

Run: `php artisan test --compact tests/Feature/Models/SuperAdminTest.php`
Expected: PASS (2 tests) — `tests/TestCase.php`'s `setUp()` (Task 3) already migrates `database/migrations/central` on every test, so the new `super_admins` migration living there is picked up automatically; no test infrastructure changes needed for this task.

- [ ] **Step 8: Run the full suite**

Run: `php artisan test --compact --testsuite=Unit,Feature`
Expected: PASS — no existing behavior touched this task besides config additions.

- [ ] **Step 9: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_07_23_090100_create_super_admins_table.php app/Models/SuperAdmin.php database/factories/SuperAdminFactory.php config/tenancy.php config/auth.php tests/Feature/Models/SuperAdminTest.php
git commit -m "feat: add super_admins table, model, guard and tenancy config"
```

---

### Task 7: Super-admin authentication

**Files:**
- Create: `app/Http/Controllers/SuperAdmin/AuthController.php`
- Create: `app/Http/Requests/SuperAdmin/SuperAdminLoginRequest.php`
- Create: `resources/views/super-admin/auth/login.blade.php`
- Create: `resources/views/components/layouts/super-admin.blade.php`
- Modify: `routes/web.php` (add the domain-constrained super-admin route group)
- Modify: `tests/Pest.php` (add the shared `superAdminUrl()` test helper)
- Test: `tests/Feature/SuperAdmin/AuthTest.php`

**Interfaces:**
- Consumes: `App\Models\SuperAdmin`, `config('tenancy.super_admin_host')`.
- Produces: route names `super-admin.login`, `super-admin.login.store`, `super-admin.logout`; a global `superAdminUrl(string $path = ''): string` test helper used by every `tests/Feature/SuperAdmin/*Test.php` file in this plan (Tasks 7–10) — defined **once** here, not redeclared per file (Pest loads every test file into the same process, so redeclaring a global function in more than one file fatals with "Cannot redeclare").

- [ ] **Step 1: Add the shared test helper**

In `tests/Pest.php`, replace the placeholder `function something() {...}` in the "Functions" section with:

```php
function superAdminUrl(string $path = ''): string
{
    return 'http://'.config('tenancy.super_admin_host').'/'.ltrim($path, '/');
}
```

Every subsequent task's `SuperAdmin` test files call this helper — none of them redefine it.

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/SuperAdmin/AuthTest.php`:

```php
<?php

use App\Models\SuperAdmin;

it('shows the super-admin login page', function () {
    $this->get(superAdminUrl('superadmin/login'))->assertOk();
});

it('logs a super admin in with valid credentials', function () {
    $superAdmin = SuperAdmin::factory()->create(['email' => 'root@example.test', 'password' => 'secret-password']);

    $this->post(superAdminUrl('superadmin/login'), [
        'email' => 'root@example.test',
        'password' => 'secret-password',
    ])->assertRedirect();

    $this->assertAuthenticatedAs($superAdmin, 'super_admin');
});

it('rejects invalid credentials', function () {
    SuperAdmin::factory()->create(['email' => 'root@example.test', 'password' => 'secret-password']);

    $this->post(superAdminUrl('superadmin/login'), [
        'email' => 'root@example.test',
        'password' => 'wrong-password',
    ])->assertSessionHasErrors();

    $this->assertGuest('super_admin');
});

it('logs a super admin out', function () {
    $superAdmin = SuperAdmin::factory()->create();

    $this->actingAs($superAdmin, 'super_admin')
        ->post(superAdminUrl('superadmin/logout'))
        ->assertRedirect();

    $this->assertGuest('super_admin');
});
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/SuperAdmin/AuthTest.php`
Expected: FAIL — route `super-admin.login` doesn't exist / 404 on all requests.

- [ ] **Step 4: Create the login request**

Create `app/Http/Requests/SuperAdmin/SuperAdminLoginRequest.php`:

```php
<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class SuperAdminLoginRequest extends FormRequest
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
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    public function authenticate(): void
    {
        if (! Auth::guard('super_admin')->attempt($this->only('email', 'password'))) {
            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }
    }
}
```

- [ ] **Step 5: Create the controller**

Create `app/Http/Controllers/SuperAdmin/AuthController.php`:

```php
<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\SuperAdminLoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function create(): View
    {
        return view('super-admin.auth.login');
    }

    public function store(SuperAdminLoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        return redirect()->route('super-admin.tenants.index');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('super_admin')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('super-admin.login');
    }
}
```

- [ ] **Step 6: Create the super-admin layout**

Create `resources/views/components/layouts/super-admin.blade.php`:

```blade
@props(['title' => 'Super-admin'])
<!doctype html>
<html lang="fr" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-cream font-sans text-navy antialiased">
    @auth('super_admin')
        <nav class="border-b border-divider bg-white px-4 py-3">
            <div class="mx-auto flex max-w-5xl items-center justify-between">
                <div class="flex items-center gap-4 text-sm font-semibold">
                    <a href="{{ route('super-admin.tenants.index') }}" class="text-navy hover:text-navy-hover">Clubs</a>
                    <a href="{{ route('super-admin.dashboard') }}" class="text-navy hover:text-navy-hover">Tableau de bord</a>
                </div>
                <form method="POST" action="{{ route('super-admin.logout') }}">
                    @csrf
                    <button type="submit" class="cursor-pointer text-sm font-semibold text-muted hover:text-navy">Déconnexion</button>
                </form>
            </div>
        </nav>
    @endauth

    <main class="mx-auto max-w-5xl px-4 py-8">
        {{ $slot }}
    </main>
</body>
</html>
```

- [ ] **Step 7: Create the login view**

Create `resources/views/super-admin/auth/login.blade.php`:

```blade
<x-layouts.super-admin title="Connexion super-admin">
    <div class="mx-auto max-w-[380px] rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)]">
        <h1 class="font-display text-xl font-extrabold text-navy">Connexion super-admin</h1>

        @if ($errors->any())
            <div class="mt-4 rounded-lg bg-error-bg px-4 py-3 text-sm text-error">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('super-admin.login.store') }}" class="mt-4 flex flex-col gap-4">
            @csrf
            <div class="flex flex-col gap-1.5">
                <label for="email" class="text-sm font-semibold">E-mail</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="password" class="text-sm font-semibold">Mot de passe</label>
                <input type="password" id="password" name="password" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>
            <button type="submit"
                class="cursor-pointer rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                Se connecter
            </button>
        </form>
    </div>
</x-layouts.super-admin>
```

- [ ] **Step 8: Add the domain-constrained route group**

In `routes/web.php`, add this block above the existing `Route::middleware(ResolveTenant::class)->group(...)` block, for readability (grouping the super-admin-only routes together at the top of the file). Registration order doesn't actually affect matching here — this group's paths are all prefixed `superadmin/...`, which never overlaps with the other group's `/`, `/admin/...` paths, domain-constrained or not. Add the new `use` import:

```php
use App\Http\Controllers\SuperAdmin\AuthController as SuperAdminAuthController;
```

```php
Route::domain(config('tenancy.super_admin_host'))->prefix('superadmin')->name('super-admin.')->group(function () {
    Route::middleware('guest:super_admin')->group(function () {
        Route::get('login', [SuperAdminAuthController::class, 'create'])->name('login');
        Route::post('login', [SuperAdminAuthController::class, 'store'])->name('login.store');
    });

    Route::middleware('auth:super_admin')->group(function () {
        Route::post('logout', [SuperAdminAuthController::class, 'destroy'])->name('logout');
    });
});
```

(Tasks 8–10 add more routes inside the same `auth:super_admin` group — `tenants.*`, `dashboard`, `impersonate.*`.)

- [ ] **Step 9: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/SuperAdmin/AuthTest.php`
Expected: FAIL still on the redirect-target routes (`super-admin.tenants.index` doesn't exist yet) — that's expected; Task 8 adds it. For now, confirm the failure is specifically about the missing `tenants.index` route name (a `RouteNotFoundException`), not about login/auth itself. If so, this task is done; Task 8 will make the full file green.

- [ ] **Step 10: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/SuperAdmin/AuthController.php app/Http/Requests/SuperAdmin/SuperAdminLoginRequest.php resources/views/super-admin/auth/login.blade.php resources/views/components/layouts/super-admin.blade.php routes/web.php tests/Pest.php tests/Feature/SuperAdmin/AuthTest.php
git commit -m "feat: add super-admin authentication"
```

---

### Task 8: Tenant provisioning (list + create)

**Files:**
- Create: `app/Http/Controllers/SuperAdmin/TenantController.php`
- Create: `app/Http/Requests/SuperAdmin/StoreTenantRequest.php`
- Create: `resources/views/super-admin/tenants/index.blade.php`
- Create: `resources/views/super-admin/tenants/create.blade.php`
- Modify: `routes/web.php`
- Modify: `bootstrap/app.php` (guest-redirect target needs to depend on host — see Step 7)
- Test: `tests/Feature/SuperAdmin/TenantProvisioningTest.php`
- Test: `tests/Tenancy/TenantProvisioningMigrationTest.php`

**Interfaces:**
- Consumes: `App\Models\Tenant`.
- Produces: route names `super-admin.tenants.index`, `super-admin.tenants.create`, `super-admin.tenants.store`. Creating a tenant leaves a migrated, empty SQLite file at `database/data/tenants/{uuid}.sqlite` and a matching `tenants` row.

**Note on the two test files:** three of these four scenarios never touch the `sqlite` connection at all (they only read/write `central`, via `Tenant`/`SuperAdmin`), so they live in the fast `tests/Feature/SuperAdmin/` suite as normal. The fourth — "provisions a new tenant with a migrated database" — has to actually verify the freshly created tenant's SQLite file got migrated, which means switching the `sqlite` connection to it, which per Task 3's findings is only safe in the isolated `tests/Tenancy/` suite.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/SuperAdmin/TenantProvisioningTest.php`:

```php
<?php

use App\Models\SuperAdmin;
use App\Models\Tenant;

it('redirects guests to the super-admin login', function () {
    $this->get(superAdminUrl('superadmin/tenants'))->assertRedirect(superAdminUrl('superadmin/login'));
});

it('lists existing tenants', function () {
    Tenant::factory()->create(['name' => 'Rotary Club Test']);

    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->get(superAdminUrl('superadmin/tenants'))
        ->assertOk()
        ->assertSee('Rotary Club Test');
});

it('rejects a duplicate host', function () {
    Tenant::factory()->create(['host' => 'existing.example.test']);

    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->post(superAdminUrl('superadmin/tenants'), [
            'name' => 'Doublon',
            'host' => 'existing.example.test',
        ])->assertSessionHasErrors(['host']);
});
```

Create `tests/Tenancy/TenantProvisioningMigrationTest.php`:

```php
<?php

use App\Models\SuperAdmin;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('provisions a new tenant with a migrated database', function () {
    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->post(superAdminUrl('superadmin/tenants'), [
            'name' => 'Rotary Club Nouveau',
            'host' => 'nouveau.example.test',
        ])->assertRedirect(superAdminUrl('superadmin/tenants'));

    $tenant = Tenant::where('host', 'nouveau.example.test')->firstOrFail();

    expect($tenant->name)->toBe('Rotary Club Nouveau')
        ->and($tenant->sqlite_path)->toEndWith('.sqlite')
        ->and(file_exists($tenant->sqlite_path))->toBeTrue();

    config(['database.connections.sqlite.database' => $tenant->sqlite_path]);
    DB::purge('sqlite');

    expect(Schema::hasTable('club_settings'))->toBeTrue();

    @unlink($tenant->sqlite_path);
});
```

This file lives in `tests/Tenancy/`, bound to `TenancyTestCase` (Task 3) — it gets its own fresh, real `sqlite` and `central` database, so the `SuperAdmin` created here is independent from anything in the main `Feature` suite.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/SuperAdmin/TenantProvisioningTest.php tests/Tenancy/TenantProvisioningMigrationTest.php`
Expected: FAIL — routes don't exist yet.

- [ ] **Step 3: Create the store request**

Create `app/Http/Requests/SuperAdmin/StoreTenantRequest.php`:

```php
<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;

class StoreTenantRequest extends FormRequest
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
            'host' => ['required', 'string', 'max:255', 'unique:central.tenants,host'],
        ];
    }
}
```

The `unique:central.tenants,host` rule targets the `central` connection explicitly (Laravel's `unique` rule accepts a `connection.table` form).

- [ ] **Step 4: Create the controller**

Create `app/Http/Controllers/SuperAdmin/TenantController.php`:

```php
<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\StoreTenantRequest;
use App\Models\Tenant;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Illuminate\View\View;

class TenantController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function index(): View
    {
        return view('super-admin.tenants.index', [
            'tenants' => Tenant::orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('super-admin.tenants.create');
    }

    public function store(StoreTenantRequest $request): RedirectResponse
    {
        $previousTenant = $this->tenantContext->current();

        $directory = database_path('data/tenants');

        if (! is_dir($directory)) {
            mkdir($directory, recursive: true);
        }

        $sqlitePath = $directory.'/'.Str::uuid().'.sqlite';
        touch($sqlitePath);

        $this->tenantContext->use(new Tenant([...$request->validated(), 'sqlite_path' => $sqlitePath]));
        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);

        $tenant = Tenant::create([
            ...$request->validated(),
            'sqlite_path' => $sqlitePath,
        ]);

        if ($previousTenant !== null) {
            $this->tenantContext->use($previousTenant);
        } else {
            $this->tenantContext->clear();
        }

        return redirect()->route('super-admin.tenants.index')->with('status', 'Club créé.');
    }
}
```

The filename is a UUID generated up front (not the row's auto-increment id), so the file can be created and migrated *before* the `tenants` row exists — no rename step needed, and the registry row is still only inserted after a successful migration (per the design's "no orphaned registry entry on a failed migration" requirement).

- [ ] **Step 5: Add the routes**

In `routes/web.php`, add the import and routes inside the existing `auth:super_admin` group from Task 7:

```php
use App\Http\Controllers\SuperAdmin\TenantController;
```

```php
        Route::get('tenants', [TenantController::class, 'index'])->name('tenants.index');
        Route::get('tenants/create', [TenantController::class, 'create'])->name('tenants.create');
        Route::post('tenants', [TenantController::class, 'store'])->name('tenants.store');
```

- [ ] **Step 6: Create the views**

Create `resources/views/super-admin/tenants/index.blade.php`:

```blade
<x-layouts.super-admin title="Clubs — Super-admin">
    <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)]">
        <div class="flex items-center justify-between gap-3">
            <h1 class="font-display text-xl font-extrabold text-navy">Clubs</h1>
            <a href="{{ route('super-admin.tenants.create') }}"
                class="cursor-pointer rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                Ajouter un club
            </a>
        </div>

        @if (session('status'))
            <div class="mt-4 rounded-lg bg-cream px-4 py-3 text-sm text-navy">{{ session('status') }}</div>
        @endif

        <div class="mt-6 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-divider text-muted-strong">
                        <th class="py-2 pr-4 font-semibold">Nom</th>
                        <th class="py-2 pr-4 font-semibold">Sous-domaine</th>
                        <th class="py-2 pr-4 font-semibold"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-divider">
                    @foreach ($tenants as $tenant)
                        <tr>
                            <td class="py-3 pr-4 font-semibold text-navy">{{ $tenant->name }}</td>
                            <td class="py-3 pr-4 text-muted">{{ $tenant->host }}</td>
                            <td class="py-3 pr-4">
                                <form method="POST" action="{{ route('super-admin.impersonate.start', $tenant) }}">
                                    @csrf
                                    <button type="submit" class="cursor-pointer text-sm font-semibold text-navy hover:text-navy-hover">
                                        Voir en tant que
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-layouts.super-admin>
```

Create `resources/views/super-admin/tenants/create.blade.php`:

```blade
<x-layouts.super-admin title="Nouveau club — Super-admin">
    <div class="mx-auto max-w-[480px] rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)]">
        <h1 class="font-display text-xl font-extrabold text-navy">Nouveau club</h1>

        @if ($errors->any())
            <div class="mt-4 rounded-lg bg-error-bg px-4 py-3 text-sm text-error">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('super-admin.tenants.store') }}" class="mt-4 flex flex-col gap-4">
            @csrf
            <div class="flex flex-col gap-1.5">
                <label for="name" class="text-sm font-semibold">Nom du club</label>
                <input type="text" id="name" name="name" value="{{ old('name') }}" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="host" class="text-sm font-semibold">Sous-domaine (ex. club2.tondomaine.org)</label>
                <input type="text" id="host" name="host" value="{{ old('host') }}" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>
            <button type="submit"
                class="cursor-pointer rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                Créer le club
            </button>
        </form>
    </div>
</x-layouts.super-admin>
```

Note the `index.blade.php` view already references `route('super-admin.impersonate.start', $tenant)`, which Task 9 adds — this view will 500 until Task 9 lands; that's expected within this in-progress plan and is resolved by the very next task.

- [ ] **Step 7: Fix the guest-redirect target for super-admin routes**

This step exists because of a real bug caught by an implementer subagent while running this task's tests: `bootstrap/app.php` currently has `$middleware->redirectGuestsTo(fn () => route('admin.login'))` — a single, guard-agnostic redirect target used for *every* unauthenticated request, regardless of which guard rejected it. Task 7 added the `super_admin` guard and its own login page, but nothing exercised an unauthenticated `GET` against an `auth:super_admin`-protected route until this task's "redirects guests to the super-admin login" test — which now fails because it's redirected to `admin/login` (the *club* admin login) instead of `superadmin/login`. This isn't just a test artifact: in production, an unauthenticated visitor to `admin.<domain>/superadmin/tenants` would be sent to the wrong login page entirely.

Fix by making the redirect target depend on which host the request came in on — the same signal `ResolveTenant` already uses to distinguish super-admin requests from club requests. Laravel's `redirectGuestsTo()` callback receives the current `Request` (verified against `vendor/laravel/framework/src/Illuminate/Auth/Middleware/Authenticate.php`, which calls it as `call_user_func($callback, $request)`).

Replace the one line in `bootstrap/app.php`:

```php
$middleware->redirectGuestsTo(fn () => route('admin.login'));
```

with:

```php
$middleware->redirectGuestsTo(fn (Request $request) => $request->getHost() === config('tenancy.super_admin_host')
    ? route('super-admin.login')
    : route('admin.login'));
```

(`Request` is already imported at the top of `bootstrap/app.php` — no new `use` statement needed.)

- [ ] **Step 8: Run the provisioning tests to verify list/store pass, and only the impersonate-link rendering fails**

Run: `php artisan test --compact tests/Feature/SuperAdmin/TenantProvisioningTest.php`
Expected: the `it('lists existing tenants', ...)` test will FAIL with a `RouteNotFoundException` from the view (since `index.blade.php` references `super-admin.impersonate.start`). This is expected and resolved in Task 9. Confirm the other two tests (`redirects guests`, `rejects a duplicate host`) PASS — they don't render the index view with a populated table row triggering that route call. `redirects guests` specifically now needs Step 7's fix to pass.

Run: `php artisan test --compact tests/Tenancy/TenantProvisioningMigrationTest.php`
Expected: PASS — this test never renders `index.blade.php`, so it's unaffected by the not-yet-existing impersonate route.

Run: `php artisan test --compact --testsuite=Unit,Feature`
Expected: PASS, no regressions — Step 7's redirect change only adds a host-based branch, it doesn't change behavior for any request to a host other than `config('tenancy.super_admin_host')`, which covers every existing test.

- [ ] **Step 9: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/SuperAdmin/TenantController.php app/Http/Requests/SuperAdmin/StoreTenantRequest.php resources/views/super-admin/tenants routes/web.php bootstrap/app.php tests/Feature/SuperAdmin/TenantProvisioningTest.php tests/Tenancy/TenantProvisioningMigrationTest.php
git commit -m "feat: add tenant provisioning (list + create)"
```

---

### Task 9: Impersonation ("view as club X")

**Files:**
- Create: `app/Http/Controllers/SuperAdmin/ImpersonationController.php`
- Modify: `app/Http/Middleware/ResolveTenant.php`
- Modify: `resources/views/components/layouts/admin.blade.php` (impersonation banner)
- Modify: `routes/web.php`
- Test: `tests/Feature/SuperAdmin/ImpersonationTest.php`
- Test: `tests/Tenancy/ImpersonationViewTest.php`

**Interfaces:**
- Consumes: `session('impersonating_tenant_id')`.
- Produces: route names `super-admin.impersonate.start`, `super-admin.impersonate.stop`. Visiting `admin.<super_admin_host>/admin/...` while impersonating serves that tenant's existing admin panel unchanged.

**Note on the two test files:** verifying that the impersonated tenant's *actual admin panel* renders requires a second, genuinely migrated tenant SQLite database — per Task 3's findings, that only belongs in the isolated `tests/Tenancy/` suite. The 404-when-not-impersonating and stop-impersonation scenarios never touch the `sqlite` connection, so they stay in the fast `Feature` suite.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/SuperAdmin/ImpersonationTest.php`:

```php
<?php

use App\Models\SuperAdmin;
use App\Models\Tenant;

it('returns 404 on the admin panel host when not impersonating anyone', function () {
    $this->get(superAdminUrl('admin/login'))->assertNotFound();
});

it('stops impersonation and clears the session flag', function () {
    $tenant = Tenant::factory()->create();

    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->withSession(['impersonating_tenant_id' => $tenant->id])
        ->post(superAdminUrl('superadmin/impersonate/stop'))
        ->assertRedirect(route('super-admin.tenants.index'));

    $this->assertFalse(session()->has('impersonating_tenant_id'));
});
```

Create `tests/Tenancy/ImpersonationViewTest.php`:

```php
<?php

use App\Models\SuperAdmin;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('lets a super admin view a tenant admin panel after starting impersonation', function () {
    $tenant = Tenant::factory()->create();
    config(['database.connections.sqlite.database' => $tenant->sqlite_path]);
    DB::purge('sqlite');
    Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
    $tenantAdmin = User::factory()->create();

    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->post(superAdminUrl("superadmin/tenants/{$tenant->id}/impersonate"))
        ->assertRedirect(route('admin.sessions.index'));

    $this->withSession(['impersonating_tenant_id' => $tenant->id])
        ->actingAs($tenantAdmin)
        ->get(superAdminUrl('admin/sessions'))
        ->assertOk()
        ->assertSee('RC Cotonou Ife');

    @unlink($tenant->sqlite_path);
});
```

This test lives in `tests/Tenancy/`, bound to `TenancyTestCase` (Task 3), so it gets its own fresh real `sqlite`/`central` pair — the `$tenant` created here is a *second*, additional tenant on top of that, exercising exactly the same "switch to a real file, migrate it" mechanics the real `ImpersonationController` and `ResolveTenant` use. It asserts `'RC Cotonou Ife'` rather than `$tenant->name`: `resources/views/admin/sessions/index.blade.php` renders inside `x-layouts.admin`, which prints the tenant's `ClubSetting::current()->name` — and every freshly migrated tenant gets that exact placeholder name from the seed migration (Plan Notes §1), not `Tenant::factory()`'s own random `name`.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/SuperAdmin/ImpersonationTest.php tests/Tenancy/ImpersonationViewTest.php`
Expected: FAIL — routes don't exist yet, and `ResolveTenant` doesn't branch on the super-admin host yet.

- [ ] **Step 3: Extend `ResolveTenant` to branch on the super-admin host**

Replace `app/Http/Middleware/ResolveTenant.php`:

```php
<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $request->getHost() === config('tenancy.super_admin_host')
            ? $this->resolveImpersonatedTenant($request)
            : Tenant::where('host', $request->getHost())->first();

        abort_if($tenant === null, 404);

        $this->tenantContext->use($tenant);

        return $next($request);
    }

    private function resolveImpersonatedTenant(Request $request): ?Tenant
    {
        $tenantId = $request->session()->get('impersonating_tenant_id');

        return $tenantId !== null ? Tenant::find($tenantId) : null;
    }
}
```

- [ ] **Step 4: Create the impersonation controller**

Create `app/Http/Controllers/SuperAdmin/ImpersonationController.php`:

```php
<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ImpersonationController extends Controller
{
    public function start(Request $request, Tenant $tenant): RedirectResponse
    {
        $request->session()->put('impersonating_tenant_id', $tenant->id);

        return redirect()->route('admin.sessions.index');
    }

    public function stop(Request $request): RedirectResponse
    {
        $request->session()->forget('impersonating_tenant_id');

        return redirect()->route('super-admin.tenants.index');
    }
}
```

- [ ] **Step 5: Add the routes**

In `routes/web.php`, add the import and, inside the `auth:super_admin` group:

```php
use App\Http\Controllers\SuperAdmin\ImpersonationController;
```

```php
        Route::post('tenants/{tenant}/impersonate', [ImpersonationController::class, 'start'])->name('impersonate.start');
        Route::post('impersonate/stop', [ImpersonationController::class, 'stop'])->name('impersonate.stop');
```

- [ ] **Step 6: Let the `admin.*` group accept a super-admin session, not just a club admin**

Still in `routes/web.php`, change the `admin.*` protected group's middleware from `'auth'` to `'auth:web,super_admin'`:

```php
        Route::middleware('auth:web,super_admin')->group(function () {
```

This is the change Task 4's plan text already explained but deliberately deferred to here: making it before the `super_admin` guard existed (it now does, since Task 6) would have made Laravel's `Authenticate` middleware throw `InvalidArgumentException` on every request to these routes, since it resolves every guard named in the list and errors on an undefined one rather than skipping it. Now that `config/auth.php` defines `super_admin` (Task 6), this is safe: a request authenticated on *either* guard passes. No existing controller or view calls `Auth::user()`/`auth()->user()`/`Auth::id()` (verified by grep during planning), so accepting either guard doesn't create ambiguity anywhere downstream.

Run the full suite once after this specific change, before moving to Step 6, to confirm the guard-list change itself doesn't regress anything: `php artisan test --compact --testsuite=Unit,Feature`. Expected: PASS, same count as before this step (the `super_admin` guard is now satisfiable, so no request should error; nothing yet authenticates against it, so no request that previously needed a plain `web` session should behave differently).

- [ ] **Step 7: Add the impersonation banner to the admin layout**

In `resources/views/components/layouts/admin.blade.php`, right after the opening `<body ...>` tag (before `<x-page-loading-overlay />`), add:

```blade
    @if (session()->has('impersonating_tenant_id'))
        <div class="flex items-center justify-between bg-navy px-4 py-2 text-sm text-white">
            <span>Vous consultez : {{ $clubSetting?->name ?? 'ce club' }}</span>
            <form method="POST" action="{{ route('super-admin.impersonate.stop') }}">
                @csrf
                <button type="submit" class="cursor-pointer font-semibold underline">Quitter la vue</button>
            </form>
        </div>
    @endif
```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/SuperAdmin/ImpersonationTest.php`
Expected: PASS (2 tests).

Run: `php artisan test --compact tests/Tenancy/ImpersonationViewTest.php`
Expected: PASS (1 test).

- [ ] **Step 9: Re-run Task 8's provisioning test (it depended on this route existing in the view)**

Run: `php artisan test --compact tests/Feature/SuperAdmin/TenantProvisioningTest.php`
Expected: still 2 of 3 passing, **not** 3 of 3 — `it('lists existing tenants', ...)` still fails, but now with a *different* `RouteNotFoundException`: `super-admin.dashboard`, not `super-admin.impersonate.start`. This isn't a regression from this task. `resources/views/components/layouts/super-admin.blade.php` (Task 7) already unconditionally calls `route('super-admin.dashboard')` in its nav bar whenever `@auth('super_admin')` is true — that route doesn't exist until Task 10. It stayed hidden until now purely because Blade renders a component's slot content (the `index.blade.php` table, with the `impersonate.start` call this task just fixed) before the parent layout's own markup (the nav bar) — so the *first* missing route always masked the second. Confirm the failure message specifically names `super-admin.dashboard`, not `super-admin.impersonate.start` (that would indicate this task's own fix didn't actually land). Task 10 resolves this for good.

- [ ] **Step 10: Run the full suite**

Run: `php artisan test --compact --testsuite=Unit,Feature`
Expected: same one pre-existing failure as above (`super-admin.dashboard` missing), no other regressions.

Run: `php artisan test --compact --testsuite=Tenancy`
Expected: PASS (now covers Task 8's and this task's cross-tenant tests together).

- [ ] **Step 11: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/SuperAdmin/ImpersonationController.php app/Http/Middleware/ResolveTenant.php resources/views/components/layouts/admin.blade.php routes/web.php tests/Feature/SuperAdmin/ImpersonationTest.php tests/Tenancy/ImpersonationViewTest.php
git commit -m "feat: add super-admin impersonation of a tenant's admin panel"
```

---

### Task 10: Aggregated dashboard

**Files:**
- Create: `app/Http/Controllers/SuperAdmin/DashboardController.php`
- Create: `resources/views/super-admin/dashboard.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Tenancy/DashboardTest.php`

**Interfaces:**
- Consumes: `App\Services\TenantContext`, `App\Models\Tenant`, `App\Models\Member`, `App\Models\Attendance`.
- Produces: route name `super-admin.dashboard`.

This test needs two separately migrated, populated tenant databases at once — per Task 3's findings, that only belongs in the isolated `tests/Tenancy/` suite.

- [ ] **Step 1: Write the failing test**

Create `tests/Tenancy/DashboardTest.php`:

```php
<?php

use App\Models\Attendance;
use App\Models\MeetingSession;
use App\Models\Member;
use App\Models\SuperAdmin;
use App\Models\Tenant;
use App\Services\TenantContext;
use Illuminate\Support\Facades\Artisan;

it('shows member and attendance counts per tenant', function () {
    $tenantContext = app(TenantContext::class);

    $tenantA = Tenant::factory()->create(['name' => 'Club A']);
    touch($tenantA->sqlite_path);
    $tenantContext->use($tenantA);
    Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
    Member::factory()->count(3)->create();
    $session = MeetingSession::factory()->create();
    Attendance::factory()->for($session)->create(['present' => true]);

    $tenantB = Tenant::factory()->create(['name' => 'Club B']);
    touch($tenantB->sqlite_path);
    $tenantContext->use($tenantB);
    Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
    Member::factory()->count(5)->create();

    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->get(superAdminUrl('superadmin/dashboard'))
        ->assertOk()
        ->assertSee('Club A')
        ->assertSee('Club B')
        ->assertSeeInOrder(['Club A', '3'])
        ->assertSeeInOrder(['Club B', '5']);

    @unlink($tenantA->sqlite_path);
    @unlink($tenantB->sqlite_path);
});
```

The `touch()` calls before each `$tenantContext->use(...)` are required — `TenantFactory` (Task 1) only creates the *directory* its generated `sqlite_path` lives in, not the file itself, and `TenantContext::use()` immediately calls `applyMailSettings()` → `Schema::hasTable('mail_settings')`, which needs a real, already-existing file (Laravel's SQLite connector refuses to open a path that doesn't exist yet — it won't create one for you). This is different from `Artisan::call('migrate', ...)` on its own, which *does* create a missing SQLite file automatically — that's why Tasks 8's and 9's tests, which never call `TenantContext::use()` directly before their own `migrate` call, didn't need this. `TenantController::store()` (Task 8) already does the same `touch()` before its own `TenantContext::use()` call, for the identical reason.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Tenancy/DashboardTest.php`
Expected: FAIL — route doesn't exist.

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/SuperAdmin/DashboardController.php`:

```php
<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Member;
use App\Models\Tenant;
use App\Services\TenantContext;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function index(): View
    {
        $previousTenant = $this->tenantContext->current();

        $rows = Tenant::orderBy('name')->get()->map(function (Tenant $tenant): array {
            $this->tenantContext->use($tenant);

            return [
                'name' => $tenant->name,
                'member_count' => Member::count(),
                'attendance_count' => Attendance::where('present', true)->count(),
            ];
        });

        if ($previousTenant !== null) {
            $this->tenantContext->use($previousTenant);
        } else {
            $this->tenantContext->clear();
        }

        return view('super-admin.dashboard', ['rows' => $rows]);
    }
}
```

- [ ] **Step 4: Add the route**

In `routes/web.php`, add the import and, inside the `auth:super_admin` group:

```php
use App\Http\Controllers\SuperAdmin\DashboardController;
```

```php
        Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
```

- [ ] **Step 5: Create the view**

Create `resources/views/super-admin/dashboard.blade.php`:

```blade
<x-layouts.super-admin title="Tableau de bord — Super-admin">
    <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)]">
        <h1 class="font-display text-xl font-extrabold text-navy">Tableau de bord</h1>

        <div class="mt-6 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-divider text-muted-strong">
                        <th class="py-2 pr-4 font-semibold">Club</th>
                        <th class="py-2 pr-4 font-semibold">Membres</th>
                        <th class="py-2 pr-4 font-semibold">Présences enregistrées</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-divider">
                    @foreach ($rows as $row)
                        <tr>
                            <td class="py-3 pr-4 font-semibold text-navy">{{ $row['name'] }}</td>
                            <td class="py-3 pr-4">{{ $row['member_count'] }}</td>
                            <td class="py-3 pr-4">{{ $row['attendance_count'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-layouts.super-admin>
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test --compact tests/Tenancy/DashboardTest.php`
Expected: PASS.

- [ ] **Step 7: Run everything**

Run: `php artisan test --compact --testsuite=Unit,Feature`
Expected: PASS.

Run: `php artisan test --compact --testsuite=Tenancy`
Expected: PASS (3 tests total across this task and Tasks 8–9's cross-tenant tests).

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/SuperAdmin/DashboardController.php resources/views/super-admin/dashboard.blade.php routes/web.php tests/Tenancy/DashboardTest.php
git commit -m "feat: add super-admin aggregated dashboard"
```

---

### Task 11: Tenant-aware queued mail jobs

**Files:**
- Create: `app/Jobs/SendAttendanceThankYouMailJob.php`
- Create: `app/Jobs/SendNewAdminCredentialsMailJob.php`
- Modify: `app/Mail/AttendanceThankYouMail.php` (drop `ShouldQueue`)
- Modify: `app/Mail/NewAdminCredentialsMail.php` (drop `ShouldQueue`)
- Modify: `app/Http/Controllers/Admin/MeetingSessionController.php`
- Modify: `app/Http/Controllers/Admin/UserController.php`
- Modify: `tests/Feature/Admin/AttendanceThankYouEmailTest.php`
- Modify: `tests/Feature/Admin/UserManagementTest.php`
- Test additions: `tests/Feature/Jobs/SendAttendanceThankYouMailJobTest.php`, `tests/Feature/Jobs/SendNewAdminCredentialsMailJobTest.php`

**Interfaces:**
- Consumes: `App\Services\TenantContext`, `App\Models\Attendance`, `App\Models\MeetingSession`, `App\Models\User`.
- Produces: `SendAttendanceThankYouMailJob::dispatch(int $tenantId, int $attendanceId, int $meetingSessionId, ?string $nextSessionTitle, ?Carbon $nextSessionDate)`, `SendNewAdminCredentialsMailJob::dispatch(int $tenantId, int $userId, string $password)`.

- [ ] **Step 1: Write the failing unit tests for the jobs**

Create `tests/Feature/Jobs/SendAttendanceThankYouMailJobTest.php`:

```php
<?php

use App\Jobs\SendAttendanceThankYouMailJob;
use App\Mail\AttendanceThankYouMail;
use App\Models\Attendance;
use App\Models\MeetingSession;
use App\Services\TenantContext;
use Illuminate\Support\Facades\Mail;

it('sends the thank-you mail for the given tenant, attendance and session', function () {
    Mail::fake();
    $tenantId = app(TenantContext::class)->current()->id;

    $meetingSession = MeetingSession::factory()->create();
    $attendance = Attendance::factory()->for($meetingSession)->create(['email' => 'present@example.com']);

    (new SendAttendanceThankYouMailJob($tenantId, $attendance->id, $meetingSession->id))->handle(app(TenantContext::class));

    Mail::assertSent(AttendanceThankYouMail::class, fn (AttendanceThankYouMail $mail) => $mail->hasTo('present@example.com')
        && $mail->attendance->is($attendance)
        && $mail->meetingSession->is($meetingSession));
});
```

Create `tests/Feature/Jobs/SendNewAdminCredentialsMailJobTest.php`:

```php
<?php

use App\Jobs\SendNewAdminCredentialsMailJob;
use App\Mail\NewAdminCredentialsMail;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Support\Facades\Mail;

it('sends the credentials mail for the given tenant and user', function () {
    Mail::fake();
    $tenantId = app(TenantContext::class)->current()->id;

    $user = User::factory()->create(['email' => 'new-admin@example.com']);

    (new SendNewAdminCredentialsMailJob($tenantId, $user->id, 'temp-password'))->handle(app(TenantContext::class));

    Mail::assertSent(NewAdminCredentialsMail::class, fn (NewAdminCredentialsMail $mail) => $mail->hasTo('new-admin@example.com')
        && $mail->user->is($user)
        && $mail->password === 'temp-password');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Jobs`
Expected: FAIL — job classes don't exist.

- [ ] **Step 3: Implement the jobs**

Create `app/Jobs/SendAttendanceThankYouMailJob.php`:

```php
<?php

namespace App\Jobs;

use App\Mail\AttendanceThankYouMail;
use App\Models\Attendance;
use App\Models\MeetingSession;
use App\Models\Tenant;
use App\Services\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

class SendAttendanceThankYouMailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $tenantId,
        public int $attendanceId,
        public int $meetingSessionId,
        public ?string $nextSessionTitle = null,
        public ?Carbon $nextSessionDate = null,
    ) {}

    public function handle(TenantContext $tenantContext): void
    {
        $tenantContext->use(Tenant::findOrFail($this->tenantId));

        $attendance = Attendance::findOrFail($this->attendanceId);
        $meetingSession = MeetingSession::findOrFail($this->meetingSessionId);

        Mail::to($attendance->email)->send(
            new AttendanceThankYouMail($attendance, $meetingSession, $this->nextSessionTitle, $this->nextSessionDate)
        );
    }
}
```

Create `app/Jobs/SendNewAdminCredentialsMailJob.php`:

```php
<?php

namespace App\Jobs;

use App\Mail\NewAdminCredentialsMail;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendNewAdminCredentialsMailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $tenantId,
        public int $userId,
        public string $password,
    ) {}

    public function handle(TenantContext $tenantContext): void
    {
        $tenantContext->use(Tenant::findOrFail($this->tenantId));

        $user = User::findOrFail($this->userId);

        Mail::to($user->email)->send(new NewAdminCredentialsMail($user, $this->password));
    }
}
```

- [ ] **Step 4: Run the job unit tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Jobs`
Expected: PASS (2 tests).

- [ ] **Step 5: Drop `ShouldQueue` from the Mailables**

In `app/Mail/AttendanceThankYouMail.php`, remove the `ShouldQueue` interface and `Queueable`/`SerializesModels` traits (matching `MailSettingTestMail`'s pattern — a synchronous Mailable). Replace the class declaration lines:

```php
use App\Models\Attendance;
use App\Models\ClubSetting;
use App\Models\MeetingSession;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Carbon;

class AttendanceThankYouMail extends Mailable
{
    public function __construct(
        public Attendance $attendance,
        public MeetingSession $meetingSession,
        public ?string $nextSessionTitle = null,
        public ?Carbon $nextSessionDate = null,
    ) {}
```

(remove the now-unused `Illuminate\Bus\Queueable`, `Illuminate\Contracts\Queue\ShouldQueue`, `Illuminate\Queue\SerializesModels` imports and the `use Queueable, SerializesModels;` trait line; `envelope()`/`content()` stay unchanged).

Apply the same change to `app/Mail/NewAdminCredentialsMail.php`:

```php
use App\Models\ClubSetting;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class NewAdminCredentialsMail extends Mailable
{
    public function __construct(
        public User $user,
        public string $password,
    ) {}
```

- [ ] **Step 6: Update `MeetingSessionController` to dispatch the job**

In `app/Http/Controllers/Admin/MeetingSessionController.php`, replace the `Mail`/`AttendanceThankYouMail` imports with the job, inject `TenantContext`, and change `sendThankYouEmails`:

```php
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMeetingSessionRequest;
use App\Http\Requests\ToggleMeetingSessionOpenRequest;
use App\Jobs\SendAttendanceThankYouMailJob;
use App\Models\Attendance;
use App\Models\MeetingSession;
use App\Models\Title;
use App\Services\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class MeetingSessionController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    // ... index(), store() unchanged ...

    private function sendThankYouEmails(ToggleMeetingSessionOpenRequest $request, MeetingSession $meetingSession): void
    {
        $nextSessionTitle = null;
        $nextSessionDate = null;

        if ($request->boolean('mention_next_session')) {
            $option = (string) $request->string('next_session_option');

            if (str_starts_with($option, 'session:')) {
                $nextSession = MeetingSession::find((int) substr($option, strlen('session:')));
                $nextSessionTitle = $nextSession?->title;
                $nextSessionDate = $nextSession?->date;
            } elseif ($request->filled('next_session_date')) {
                $nextSessionDate = Carbon::parse((string) $request->string('next_session_date'));
            }
        }

        $tenantId = $this->tenantContext->current()->id;

        $meetingSession->attendances()
            ->where('present', true)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->get()
            ->each(fn (Attendance $attendance) => SendAttendanceThankYouMailJob::dispatch(
                $tenantId, $attendance->id, $meetingSession->id, $nextSessionTitle, $nextSessionDate
            ));
    }

    // ... show(), exportPdf(), principalTitles(), buildGroups() unchanged ...
}
```

Keep every other method in the file exactly as it was — only the imports, the new constructor, and `sendThankYouEmails`'s body change.

- [ ] **Step 7: Update `UserController` to dispatch the job**

Replace `app/Http/Controllers/Admin/UserController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Jobs\SendNewAdminCredentialsMailJob;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\View\View;

class UserController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function index(): View
    {
        return view('admin.users.index', [
            'users' => User::orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.users.create');
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $password = Str::password(16);

        $user = User::create([
            ...$request->validated(),
            'password' => $password,
        ]);

        SendNewAdminCredentialsMailJob::dispatch($this->tenantContext->current()->id, $user->id, $password);

        return redirect()->route('admin.users.index');
    }
}
```

- [ ] **Step 8: Update `AttendanceThankYouEmailTest`**

In `tests/Feature/Admin/AttendanceThankYouEmailTest.php`, replace `App\Mail\AttendanceThankYouMail` + `Mail::fake()`/`Mail::assertQueued`/`Mail::assertNothingQueued`/`Mail::assertQueuedCount` with `App\Jobs\SendAttendanceThankYouMailJob` + `Queue::fake()`/`Queue::assertPushed`/`Queue::assertNothingPushed`. Replace the full file:

```php
<?php

use App\Jobs\SendAttendanceThankYouMailJob;
use App\Models\Attendance;
use App\Models\MeetingSession;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

it('does not send any email when closing without checking the box', function () {
    $meetingSession = MeetingSession::factory()->create(['is_open' => true]);
    Attendance::factory()->for($meetingSession)->create(['present' => true, 'email' => 'present@example.com']);
    $admin = User::factory()->create();

    Queue::fake();

    $this->actingAs($admin)
        ->post(route('admin.sessions.toggle-open', $meetingSession))
        ->assertRedirect();

    Queue::assertNothingPushed();
});

it('queues a thank-you email only to present attendees with an email when closing with the box checked', function () {
    $meetingSession = MeetingSession::factory()->create(['is_open' => true]);
    $withEmail = Attendance::factory()->for($meetingSession)->create(['present' => true, 'email' => 'present@example.com']);
    Attendance::factory()->for($meetingSession)->create(['present' => true, 'email' => null]);
    Attendance::factory()->for($meetingSession)->create(['present' => false, 'email' => 'absent@example.com']);
    $admin = User::factory()->create();

    Queue::fake();

    $this->actingAs($admin)
        ->post(route('admin.sessions.toggle-open', $meetingSession), [
            'send_thank_you_email' => '1',
        ])
        ->assertRedirect();

    Queue::assertPushed(
        SendAttendanceThankYouMailJob::class,
        fn (SendAttendanceThankYouMailJob $job) => $job->attendanceId === $withEmail->id
    );
    Queue::assertPushed(SendAttendanceThankYouMailJob::class, 1);
});

it('never sends mail when reopening an already-closed session', function () {
    $meetingSession = MeetingSession::factory()->create(['is_open' => false]);
    Attendance::factory()->for($meetingSession)->create(['present' => true, 'email' => 'present@example.com']);
    $admin = User::factory()->create();

    Queue::fake();

    $this->actingAs($admin)
        ->post(route('admin.sessions.toggle-open', $meetingSession), [
            'send_thank_you_email' => '1',
        ])
        ->assertRedirect();

    Queue::assertNothingPushed();
});

it('passes the selected upcoming session title and date when mentioning the next session', function () {
    $meetingSession = MeetingSession::factory()->create(['is_open' => true]);
    Attendance::factory()->for($meetingSession)->create(['present' => true, 'email' => 'present@example.com']);
    $nextSession = MeetingSession::factory()->create([
        'title' => 'Assemblée annuelle',
        'date' => now()->addWeek()->toDateString(),
    ]);
    $admin = User::factory()->create();

    Queue::fake();

    $this->actingAs($admin)
        ->post(route('admin.sessions.toggle-open', $meetingSession), [
            'send_thank_you_email' => '1',
            'mention_next_session' => '1',
            'next_session_option' => "session:{$nextSession->id}",
        ])
        ->assertRedirect();

    Queue::assertPushed(
        SendAttendanceThankYouMailJob::class,
        fn (SendAttendanceThankYouMailJob $job) => $job->nextSessionTitle === $nextSession->title
            && $job->nextSessionDate->isSameDay($nextSession->date)
    );
});

it('passes a manually typed next session date without a title', function () {
    $meetingSession = MeetingSession::factory()->create(['is_open' => true]);
    Attendance::factory()->for($meetingSession)->create(['present' => true, 'email' => 'present@example.com']);
    $admin = User::factory()->create();

    Queue::fake();

    $this->actingAs($admin)
        ->post(route('admin.sessions.toggle-open', $meetingSession), [
            'send_thank_you_email' => '1',
            'mention_next_session' => '1',
            'next_session_option' => 'manual',
            'next_session_date' => '2026-08-15',
        ])
        ->assertRedirect();

    Queue::assertPushed(
        SendAttendanceThankYouMailJob::class,
        fn (SendAttendanceThankYouMailJob $job) => $job->nextSessionTitle === null
            && $job->nextSessionDate->toDateString() === '2026-08-15'
    );
});

it('shows the close-session panel with the thank-you email options when the session is open', function () {
    $meetingSession = MeetingSession::factory()->create(['is_open' => true]);
    MeetingSession::factory()->create([
        'title' => 'Assemblée annuelle',
        'date' => now()->addWeek()->toDateString(),
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.show', $meetingSession))
        ->assertOk()
        ->assertSee('closeSessionPanel(', false)
        ->assertSee('Envoyer un mail de remerciement aux présents')
        ->assertSee('Mentionner la prochaine séance')
        ->assertSee('Assemblée annuelle');
});

it('does not show the close-session panel checkboxes when the session is already closed', function () {
    $meetingSession = MeetingSession::factory()->create(['is_open' => false]);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.show', $meetingSession))
        ->assertOk()
        ->assertDontSee('Envoyer un mail de remerciement aux présents');
});
```

- [ ] **Step 9: Update `UserManagementTest`**

In `tests/Feature/Admin/UserManagementTest.php`, replace the mail-related import and the "creates a new admin" test:

```php
<?php

use App\Jobs\SendNewAdminCredentialsMailJob;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

it('redirects guests to login', function () {
    $this->get(route('admin.users.index'))->assertRedirect(route('admin.login'));
});

it('lists existing admins to an authenticated admin', function () {
    User::factory()->create(['name' => 'Jeanne Admin', 'email' => 'jeanne@example.com']);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.users.index'))
        ->assertOk()
        ->assertSee('Jeanne Admin')
        ->assertSee('jeanne@example.com');
});

it('creates a new admin and dispatches their generated credentials email', function () {
    Queue::fake();

    $this->actingAs(User::factory()->create())
        ->post(route('admin.users.store'), [
            'name' => 'Nouvel Admin',
            'email' => 'nouvel.admin@example.com',
        ])->assertRedirect(route('admin.users.index'));

    $created = User::where('email', 'nouvel.admin@example.com')->firstOrFail();

    expect($created->name)->toBe('Nouvel Admin');

    Queue::assertPushed(SendNewAdminCredentialsMailJob::class, fn (SendNewAdminCredentialsMailJob $job) => $job->userId === $created->id);
});

it('rejects an invalid admin creation payload', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('admin.users.store'), ['name' => '', 'email' => 'not-an-email'])
        ->assertSessionHasErrors(['name', 'email']);
});

it('rejects a duplicate admin email', function () {
    User::factory()->create(['email' => 'existing@example.com']);

    $this->actingAs(User::factory()->create())
        ->post(route('admin.users.store'), [
            'name' => 'Doublon',
            'email' => 'existing@example.com',
        ])->assertSessionHasErrors(['email']);
});
```

- [ ] **Step 10: Run the affected test files**

Run: `php artisan test --compact tests/Feature/Admin/AttendanceThankYouEmailTest.php tests/Feature/Admin/UserManagementTest.php tests/Feature/Mail`
Expected: PASS. (`tests/Feature/Mail/AttendanceThankYouMailTest.php`, `tests/Feature/Mail/BrandedEmailsTest.php` construct the Mailables directly and don't reference `ShouldQueue`/queuing — they keep passing unmodified.)

- [ ] **Step 11: Run the full suite**

Run: `php artisan test --compact --testsuite=Unit,Feature`
Expected: PASS.

- [ ] **Step 12: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Jobs app/Mail/AttendanceThankYouMail.php app/Mail/NewAdminCredentialsMail.php app/Http/Controllers/Admin/MeetingSessionController.php app/Http/Controllers/Admin/UserController.php tests/Feature/Admin/AttendanceThankYouEmailTest.php tests/Feature/Admin/UserManagementTest.php tests/Feature/Jobs
git commit -m "feat: send queued mail through tenant-aware jobs instead of queued Mailables"
```

---

### Task 12: Docker entrypoint migrates every tenant

**Files:**
- Modify: `docker/entrypoint.sh`

**Interfaces:**
- None (infra-only; no application code consumes this).

- [ ] **Step 1: Update the entrypoint script**

Replace `docker/entrypoint.sh`:

```sh
#!/bin/sh
set -e

mkdir -p database/data/tenants

if [ ! -f database/data/central.sqlite ]; then
    touch database/data/central.sqlite
fi

php artisan migrate --database=central --path=database/migrations/central --force

for tenant_db in database/data/tenants/*.sqlite; do
    [ -e "$tenant_db" ] || continue
    DB_DATABASE="$tenant_db" php artisan migrate --database=sqlite --force
done

php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"
```

`--path=database/migrations/central` on the first `migrate` call is required, not optional — Laravel's migration *bookkeeping* (which migrations have already run) lives on whichever connection `--database` points to, not per-migration, so a plain `php artisan migrate --database=central` without `--path` would scan the default `database/migrations` directory and attempt to (re-)run every *tenant* migration against `central` too (see Task 1's note). Conversely, the per-tenant loop's `migrate --database=sqlite` deliberately has **no** `--path` override — Laravel's default migration scan of `database/migrations` is non-recursive, so it naturally never sees `database/migrations/central/`'s files, and no tenant migration run ever touches `central`.

`DB_DATABASE="$tenant_db" php artisan migrate --database=sqlite --force` overrides the `sqlite` connection's database path for that single command invocation only (the env var is read by `config/database.php`'s `env('DB_DATABASE', ...)` at the start of that process), so each loop iteration targets exactly one tenant file without needing any PHP-level tenant switching at deploy time. The `for ... in *.sqlite` glob with `[ -e "$tenant_db" ] || continue` safely no-ops when the directory is empty (no tenants provisioned yet) instead of literally trying to migrate a file named `*.sqlite`.

Note: this script no longer seeds (`php artisan db:seed --force` was removed) — seeding now happens implicitly per-tenant via the `2026_07_22_120001_seed_club_settings_table.php` migration (which already runs for every tenant automatically, per Plan Notes §1) plus whatever the super-admin's provisioning flow (Task 8) already runs (`migrate` only, no separate seed step needed since the migration itself seeds `club_settings`). If any other seeder existed for global data (titles/positions — check `2026_07_15_120003_seed_titles_and_positions.php`), it's already a **migration**, not a `database/seeders/*` seeder, so it already runs automatically for every tenant via the per-tenant migrate loop above; no `db:seed` call was ever needed for those.

- [ ] **Step 2: Manually verify locally (no automated test — this is a shell script for the container)**

Run:
```bash
mkdir -p /tmp/entrypoint-check/database/data/tenants
touch /tmp/entrypoint-check/database/data/tenants/1.sqlite
touch /tmp/entrypoint-check/database/data/tenants/2.sqlite
cd /tmp/entrypoint-check && for f in database/data/tenants/*.sqlite; do echo "would migrate: $f"; done
```
Expected output:
```
would migrate: database/data/tenants/1.sqlite
would migrate: database/data/tenants/2.sqlite
```
This confirms the glob expands as intended without needing a real container rebuild during planning; the actual `docker build` + `docker compose up` verification happens as part of the next real deploy, outside this test-suite-driven plan.

- [ ] **Step 3: Format (shell script, not PHP — Pint doesn't apply) and commit**

```bash
git add docker/entrypoint.sh
git commit -m "chore: migrate the central database and every tenant database on container start"
```

---

## Final full-suite check

- [ ] Run `php artisan test --compact --testsuite=Unit,Feature` — expect PASS, no skipped/incomplete tests, and still only a few seconds (this is the command `composer test` now runs by default — see Task 3).
- [ ] Run `php artisan test --compact --testsuite=Tenancy` — expect PASS (the handful of cross-tenant tests from Tasks 8–10). Slower (real SQLite file I/O), that's expected — see Task 3's notes.
- [ ] Run `vendor/bin/pint --format agent` (no `--dirty`, full repo) once at the very end to catch any stray formatting across all files touched in this plan.
- [ ] Re-read `docs/superpowers/specs/2026-07-23-multi-tenant-clubs-design.md` section by section and confirm every section (§1–§6) has a corresponding task above (§1 → Tasks 1–3, §2 → Tasks 4–5, §3 → Tasks 6–10, §4 → Task 11, §5 → Task 12, §6 → Task 3 + test updates threaded through every task).
