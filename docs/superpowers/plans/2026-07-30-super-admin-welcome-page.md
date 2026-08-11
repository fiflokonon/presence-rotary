# Super-admin welcome page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give `admin.<domain>/` (the super-admin subdomain root) a real landing page instead of a 404, with a button to the super-admin login, and skip straight to the dashboard for an already-authenticated super-admin.

**Architecture:** One new domain-scoped route (`GET /` on `config('tenancy.super_admin_host')`), one new single-action-style controller (`WelcomeController::show`), one new Blade view reusing the existing `x-layouts.super-admin` layout component.

**Tech Stack:** Laravel 13, PHP 8.4, Pest 4, Tailwind CSS v4 (via existing `x-layouts.super-admin` classes).

## Global Constraints

- Curly braces required for all control structures, even single-line (project PHP convention).
- Constructor property promotion and explicit return types for all methods (project PHP convention).
- No `guest:super_admin` / `redirectUsersTo` middleware changes — the auth-check must live in the controller, not global middleware config (spec "Out of scope").
- No tagline or marketing copy on the welcome page — heading + one login button only (spec "Design > View").
- Run `vendor/bin/pint --dirty --format agent` before considering any PHP change done (project convention).

---

### Task 1: Welcome route, controller, view, and tests

**Files:**
- Create: `app/Http/Controllers/SuperAdmin/WelcomeController.php`
- Create: `resources/views/super-admin/welcome.blade.php`
- Modify: `routes/web.php:14-36`
- Test: `tests/Feature/SuperAdmin/WelcomeTest.php`

**Interfaces:**
- Consumes: `config('tenancy.super_admin_host')` (existing config key, `config/tenancy.php`); `Auth::guard('super_admin')` (existing guard, `config/auth.php`); named route `super-admin.dashboard` (existing, `app/Http/Controllers/SuperAdmin/DashboardController.php`); named route `super-admin.login` (existing, `app/Http/Controllers/SuperAdmin/AuthController.php`); Blade component `<x-layouts.super-admin>` with `title` prop (existing, `resources/views/components/layouts/super-admin.blade.php`); test helper `superAdminUrl(string $path = '')` (existing, `tests/Pest.php:51-54`).
- Produces: named route `super-admin.welcome` (`GET /` on the super-admin host); `App\Http\Controllers\SuperAdmin\WelcomeController::show(): View|RedirectResponse`. Nothing later in this plan depends on these — this is the only task.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/SuperAdmin/WelcomeTest.php`:

```php
<?php

use App\Models\SuperAdmin;

it('shows the welcome page to a guest', function () {
    $this->get(superAdminUrl('/'))
        ->assertOk()
        ->assertSee('Se connecter');
});

it('redirects an authenticated super admin to the dashboard', function () {
    $superAdmin = SuperAdmin::factory()->create();

    $this->actingAs($superAdmin, 'super_admin')
        ->get(superAdminUrl('/'))
        ->assertRedirect(route('super-admin.dashboard'));
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=WelcomeTest`
Expected: FAIL — currently `GET /` on the super-admin host falls through to the tenant-facing `ResolveTenant` group and 404s (no `super-admin.welcome` route exists yet, so `route('super-admin.dashboard')` inside the test file itself still resolves fine, but the first assertion `assertOk()` fails with a 404 status).

- [ ] **Step 3: Add the `WelcomeController`**

Create `app/Http/Controllers/SuperAdmin/WelcomeController.php`:

```php
<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class WelcomeController extends Controller
{
    public function show(): View|RedirectResponse
    {
        if (Auth::guard('super_admin')->check()) {
            return redirect()->route('super-admin.dashboard');
        }

        return view('super-admin.welcome');
    }
}
```

- [ ] **Step 4: Add the welcome view**

Create `resources/views/super-admin/welcome.blade.php`:

```blade
<x-layouts.super-admin :title="config('app.name')">
    <div class="mx-auto max-w-[380px] rounded-2xl bg-white p-6 text-center shadow-[0_2px_10px_rgba(20,30,50,.06)]">
        <h1 class="font-display text-xl font-extrabold text-navy">{{ config('app.name') }}</h1>

        <a href="{{ route('super-admin.login') }}"
            class="mt-4 inline-block cursor-pointer rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
            Se connecter
        </a>
    </div>
</x-layouts.super-admin>
```

- [ ] **Step 5: Wrap the super-admin domain group and add the `/` route**

In `routes/web.php`, add the import (alphabetically, after the `TenantController` import and before the `ResolveTenant` import):

```php
use App\Http\Controllers\SuperAdmin\WelcomeController;
```

Replace the existing block:

```php
Route::domain(config('tenancy.super_admin_host'))->prefix('superadmin')->name('super-admin.')->group(function () {
    Route::middleware('guest:super_admin')->group(function () {
        Route::get('login', [SuperAdminAuthController::class, 'create'])->name('login');
        Route::post('login', [SuperAdminAuthController::class, 'store'])->name('login.store');
    });

    Route::middleware('auth:super_admin')->group(function () {
        Route::post('logout', [SuperAdminAuthController::class, 'destroy'])->name('logout');
        Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('tenants', [TenantController::class, 'index'])->name('tenants.index');
        Route::get('tenants/create', [TenantController::class, 'create'])->name('tenants.create');
        Route::post('tenants', [TenantController::class, 'store'])->name('tenants.store');
        Route::post('tenants/{tenant}/impersonate', [ImpersonationController::class, 'start'])->name('impersonate.start');
        Route::post('impersonate/stop', [ImpersonationController::class, 'stop'])->name('impersonate.stop');
    });
});
```

with:

```php
Route::domain(config('tenancy.super_admin_host'))->group(function () {
    Route::get('/', [WelcomeController::class, 'show'])->name('super-admin.welcome');

    Route::prefix('superadmin')->name('super-admin.')->group(function () {
        Route::middleware('guest:super_admin')->group(function () {
            Route::get('login', [SuperAdminAuthController::class, 'create'])->name('login');
            Route::post('login', [SuperAdminAuthController::class, 'store'])->name('login.store');
        });

        Route::middleware('auth:super_admin')->group(function () {
            Route::post('logout', [SuperAdminAuthController::class, 'destroy'])->name('logout');
            Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
            Route::get('tenants', [TenantController::class, 'index'])->name('tenants.index');
            Route::get('tenants/create', [TenantController::class, 'create'])->name('tenants.create');
            Route::post('tenants', [TenantController::class, 'store'])->name('tenants.store');
            Route::post('tenants/{tenant}/impersonate', [ImpersonationController::class, 'start'])->name('impersonate.start');
            Route::post('impersonate/stop', [ImpersonationController::class, 'stop'])->name('impersonate.stop');
        });
    });
});
```

The tenant-facing group below (`Route::middleware(ResolveTenant::class)->group(...)`, currently `routes/web.php:38`) is registered after this one and is unmodified — every other host's `/` still resolves through `ResolveTenant` exactly as before.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=WelcomeTest`
Expected: PASS (2 passed)

- [ ] **Step 7: Run the full fast test suite to check for regressions**

Run: `php artisan test --compact --testsuite=Unit,Feature`
Expected: PASS, no new failures (in particular, no existing test asserts a 404 on `/` for the super-admin host — the multi-tenant spec's cross-host tests target unknown *tenant* subdomains, not the super-admin host itself).

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/SuperAdmin/WelcomeController.php resources/views/super-admin/welcome.blade.php routes/web.php tests/Feature/SuperAdmin/WelcomeTest.php
git commit -m "feat: add a welcome page for the super-admin subdomain root"
```

---

## Self-Review Notes

- **Spec coverage:** Routing (§Routing) → Step 5. Controller (§Controller) → Step 3. View (§View) → Step 4. Testing (§Testing, both cases) → Steps 1/2/6. Out-of-scope guard (no middleware config change) → honored, redirect logic is inline in the controller. All spec sections covered by this single task; no gaps.
- **Placeholder scan:** none — every step has complete code, exact commands, and expected output.
- **Type consistency:** `WelcomeController::show(): View|RedirectResponse` matches both return statements (`view(...)` and `redirect()->route(...)`); route name `super-admin.welcome` and controller/view names are used consistently across Steps 3-5; test file path matches the existing `tests/Feature/SuperAdmin/*Test.php` convention (`AuthTest.php`, `ImpersonationTest.php`, `TenantProvisioningTest.php`) rather than the flat `tests/Feature/SuperAdminWelcomePageTest.php` path named in the spec's "Files added/changed" list — a deliberate small deviation to match established test-directory conventions, not a scope change.
