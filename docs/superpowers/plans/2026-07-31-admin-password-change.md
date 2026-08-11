# Self-Service Password Change (Org Admins & Super-Admins) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a logged-in org admin (`/admin/password`) and a logged-in super-admin (`/superadmin/password`) each change their own password given their current one, and have every other active session on that account logged out automatically on its next request.

**Architecture:** Two parallel, fully duplicated vertical slices (one per guard, matching this codebase's existing `Admin\*` / `SuperAdmin\*` convention — see `Admin\AuthController` vs `SuperAdmin\AuthController`), plus one small shared piece: a custom guard-aware session middleware that both slices rely on for cross-session invalidation, because Laravel's built-in `auth.session` middleware only tracks the *default* guard and would silently do nothing for `super_admin`.

**Tech Stack:** Laravel 13, PHP 8.4, Pest 4, Blade + Tailwind v4, SQLite (central + per-tenant connections).

## Global Constraints

- Two independent, duplicated implementations per guard — do not introduce a shared controller/request base class between `web` and `super_admin`. This matches every other auth-related pair in this codebase (`AuthController`, `LoginRequest`).
- No "forgot password" / email-reset flow — this plan only covers changing a known password while authenticated. Do not add a `password_reset_tokens` migration or any mail-sending code.
- New password validation uses Laravel's `Illuminate\Validation\Rules\Password::defaults()` (8 char minimum) — do not invent a stricter policy.
- The password-change routes for the `web` guard must be reachable only by real `web`-guard sessions, never by an impersonating super-admin (`auth:web` only, not `auth:web,super_admin`), and the sidebar link must stay hidden during impersonation (`session()->has('impersonating_tenant_id')`).
- Run `vendor/bin/pint --dirty --format agent` after every task that touches `.php` files, before committing.

---

### Task 1: Guard-aware cross-session logout middleware

**Files:**
- Create: `app/Http/Middleware/AuthenticateSessionForGuard.php`
- Modify: `bootstrap/app.php`

**Interfaces:**
- Consumes: nothing from earlier tasks (this is the foundation task).
- Produces: middleware alias `auth.session.guard`, used as `auth.session.guard:web` and `auth.session.guard:super_admin` by Tasks 2 and 3. Behavior: each authenticated session stores the password hash it logged in with in `session('password_hash_{guard}')`; every request re-checks that hash against the account's *current* DB password, and force-logs-out the guard (throwing `Illuminate\Auth\AuthenticationException`) on a mismatch. A password change elsewhere is what causes that mismatch — no explicit "log out other devices" call is needed anywhere else in this plan.

This middleware currently has no route using it, so there is nothing to exercise it against yet — it gets its real test coverage once it's wired into Task 2's and Task 3's routes and Feature tests. There is no isolated middleware test elsewhere in this codebase to follow as precedent (`tests/Unit/` only holds enum tests), so this task just builds and wires the class, then confirms nothing existing broke.

- [ ] **Step 1: Create the middleware**

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;

class AuthenticateSessionForGuard
{
    public function __construct(private readonly AuthFactory $auth) {}

    public function handle(Request $request, Closure $next, string $guardName): mixed
    {
        $guard = $this->auth->guard($guardName);

        if (! $request->hasSession() || ! $guard->user()?->getAuthPassword()) {
            return $next($request);
        }

        $sessionKey = "password_hash_{$guardName}";
        $currentHash = $guard->user()->getAuthPassword();
        $storedHash = $request->session()->get($sessionKey);

        if ($storedHash === null) {
            $request->session()->put($sessionKey, $currentHash);
        } elseif (! hash_equals($currentHash, $storedHash)) {
            $guard->logout();
            $request->session()->flush();

            throw new AuthenticationException('Unauthenticated.', [$guardName]);
        }

        return tap($next($request), function () use ($request, $guard, $sessionKey): void {
            if ($guard->check()) {
                $request->session()->put($sessionKey, $guard->user()->getAuthPassword());
            }
        });
    }
}
```

- [ ] **Step 2: Register the middleware alias**

Modify `bootstrap/app.php` — add the alias as the first line inside the `withMiddleware` closure:

```php
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'auth.session.guard' => \App\Http\Middleware\AuthenticateSessionForGuard::class,
        ]);

        $middleware->redirectGuestsTo(fn (Request $request) => $request->getHost() === config('tenancy.super_admin_host')
            ? route('super-admin.login')
            : route('admin.login'));

        $middleware->trustProxies(at: '*');
    })
```

- [ ] **Step 3: Run the full test suite to confirm nothing broke**

Run: `php artisan test --compact`
Expected: all existing tests still PASS (this middleware isn't referenced by any route yet, so behavior is unchanged).

- [ ] **Step 4: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Middleware/AuthenticateSessionForGuard.php bootstrap/app.php
git commit -m "feat: add guard-aware session invalidation middleware"
```

---

### Task 2: Org admin password change (`web` guard)

**Files:**
- Create: `app/Http/Requests/UpdatePasswordRequest.php`
- Create: `app/Http/Controllers/Admin/PasswordController.php`
- Create: `resources/views/admin/password/edit.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/components/layouts/admin.blade.php`
- Modify: `tests/Pest.php`
- Create: `tests/Feature/Admin/PasswordChangeTest.php`

**Interfaces:**
- Consumes: `auth.session.guard` alias from Task 1.
- Produces: named routes `admin.password.edit` (`GET /admin/password`) and `admin.password.update` (`PUT /admin/password`); shared test helper `simulateNewRequestBoundary($app)` in `tests/Pest.php`, reused by Task 3.

- [ ] **Step 1: Add the shared test helper**

Modify `tests/Pest.php` — append after the existing `superAdminUrl()` function:

```php
function simulateNewRequestBoundary($app): void
{
    $app['session']->flush();
    $app['auth']->forgetGuards();
}
```

This exists because Laravel's test HTTP client does not reboot the application container between simulated requests within one test method — unlike real PHP-FPM, which fully reboots per request. Without resetting the session store and the cached auth guards between two simulated "devices" in the same test, the second `actingAs`-free login would be treated as already-authenticated (blocked by the `guest` middleware), and a previously-authenticated cookie would fail to decrypt/resolve correctly on replay. This exact pattern was verified empirically while writing this plan: `flush()` alone fixes cookie replay but not double-login; `forgetGuards()` alone fixes neither; `Auth::guard()->logout()` breaks cookie replay entirely (it removes the auth marker from the shared in-memory session store, corrupting the *other* session's data before it's ever replayed); `session()->regenerate(true)` breaks replay by deleting the old session's DB row outright. The `flush()` + `forgetGuards()` combination is the only one that correctly isolates two simulated logins from each other while leaving both sessions' stored DB rows intact and replayable.

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Admin/PasswordChangeTest.php`:

```php
<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('redirects guests to login on edit', function () {
    $this->get(route('admin.password.edit'))->assertRedirect(route('admin.login'));
});

it('redirects guests to login on update', function () {
    $this->put(route('admin.password.update'), [])->assertRedirect(route('admin.login'));
});

it('rejects an incorrect current password', function () {
    $user = User::factory()->create(['password' => 'old-password-123']);

    $this->actingAs($user)
        ->put(route('admin.password.update'), [
            'current_password' => 'wrong-password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertSessionHasErrors(['current_password']);

    expect(Hash::check('old-password-123', $user->fresh()->password))->toBeTrue();
});

it('rejects a new password that does not match its confirmation', function () {
    $user = User::factory()->create(['password' => 'old-password-123']);

    $this->actingAs($user)
        ->put(route('admin.password.update'), [
            'current_password' => 'old-password-123',
            'password' => 'new-password-123',
            'password_confirmation' => 'something-else',
        ])->assertSessionHasErrors(['password']);

    expect(Hash::check('old-password-123', $user->fresh()->password))->toBeTrue();
});

it('updates the password and lets the admin log in with the new one', function () {
    $user = User::factory()->create(['password' => 'old-password-123']);

    $this->actingAs($user)
        ->put(route('admin.password.update'), [
            'current_password' => 'old-password-123',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertRedirect()
        ->assertSessionHas('status', 'Mot de passe mis à jour.');

    expect(Hash::check('new-password-123', $user->fresh()->password))->toBeTrue();

    $this->post(route('admin.login'), [
        'email' => $user->email,
        'password' => 'new-password-123',
    ])->assertRedirect(route('admin.sessions.index'));
});

it('logs out other active sessions on the same account when the password changes', function () {
    config(['session.driver' => 'database']);

    $user = User::factory()->create(['password' => 'old-password-123']);
    $cookieName = config('session.cookie');

    $deviceA = $this->post(route('admin.login'), [
        'email' => $user->email,
        'password' => 'old-password-123',
    ]);
    $deviceA->assertRedirect(route('admin.sessions.index'));
    $cookieA = collect($deviceA->headers->getCookies())->first(fn ($c) => $c->getName() === $cookieName)->getValue();

    simulateNewRequestBoundary($this->app);

    $deviceB = $this->post(route('admin.login'), [
        'email' => $user->email,
        'password' => 'old-password-123',
    ]);
    $deviceB->assertRedirect(route('admin.sessions.index'));
    $cookieB = collect($deviceB->headers->getCookies())->first(fn ($c) => $c->getName() === $cookieName)->getValue();

    simulateNewRequestBoundary($this->app);
    $this->withUnencryptedCookie($cookieName, $cookieA)
        ->put(route('admin.password.update'), [
            'current_password' => 'old-password-123',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertRedirect();

    simulateNewRequestBoundary($this->app);
    $this->withUnencryptedCookie($cookieName, $cookieA)
        ->get(route('admin.sessions.index'))
        ->assertOk();

    simulateNewRequestBoundary($this->app);
    $this->withUnencryptedCookie($cookieName, $cookieB)
        ->get(route('admin.sessions.index'))
        ->assertRedirect(route('admin.login'));
});
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php artisan test --compact --filter="PasswordChangeTest"`
Expected: FAIL — `route('admin.password.edit')` and `route('admin.password.update')` don't exist yet (`RouteNotFoundException`).

- [ ] **Step 4: Create the form request**

Create `app/Http/Requests/UpdatePasswordRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class UpdatePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'current_password:web'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}
```

- [ ] **Step 5: Create the controller**

Create `app/Http/Controllers/Admin/PasswordController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePasswordRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class PasswordController extends Controller
{
    public function edit(): View
    {
        return view('admin.password.edit');
    }

    public function update(UpdatePasswordRequest $request): RedirectResponse
    {
        Auth::guard('web')->user()->update([
            'password' => $request->validated('password'),
        ]);

        return back()->with('status', 'Mot de passe mis à jour.');
    }
}
```

- [ ] **Step 6: Wire up the routes**

Modify `routes/web.php`. First, add `App\Http\Controllers\Admin\PasswordController` to the `use` statements at the top (alphabetically, after `App\Http\Controllers\Admin\MemberController`):

```php
use App\Http\Controllers\Admin\PasswordController;
```

Then change line 54 from:

```php
        Route::middleware('auth:web,super_admin')->group(function () {
```

to:

```php
        Route::middleware(['auth:web,super_admin', 'auth.session.guard:web'])->group(function () {
```

Then, immediately after that group's closing `});` (currently line 92, right before the `});` that closes the `admin.` prefix group), add a second, narrower group:

```php
        Route::middleware(['auth:web', 'auth.session.guard:web'])->group(function () {
            Route::get('password', [PasswordController::class, 'edit'])->name('password.edit');
            Route::put('password', [PasswordController::class, 'update'])->name('password.update');
        });
```

So the end of the `admin.` prefix group reads:

```php
            Route::get('club-settings', [ClubSettingController::class, 'edit'])->name('club-settings.edit');
            Route::put('club-settings', [ClubSettingController::class, 'update'])->name('club-settings.update');
        });

        Route::middleware(['auth:web', 'auth.session.guard:web'])->group(function () {
            Route::get('password', [PasswordController::class, 'edit'])->name('password.edit');
            Route::put('password', [PasswordController::class, 'update'])->name('password.update');
        });
    });
});
```

This is deliberately a separate, narrower group (`auth:web` only, not `auth:web,super_admin`) so an impersonating super-admin — who is authenticated via the `super_admin` guard while browsing `admin/*` — cannot reach `/admin/password` at all. Adding `auth.session.guard:web` to the *existing* `auth:web,super_admin` group above is still required and safe: the middleware only acts when the `web` guard specifically has a user, so it's a no-op while impersonating.

- [ ] **Step 7: Add the sidebar link**

Modify `resources/views/components/layouts/admin.blade.php`. Replace:

```blade
            @auth
                <form method="POST" action="{{ route('admin.logout') }}">
```

with:

```blade
            @auth
                @unless (session()->has('impersonating_tenant_id'))
                    <a href="{{ route('admin.password.edit') }}" @click="close()"
                        class="cursor-pointer rounded-lg px-3 py-2 text-sm font-semibold {{ request()->routeIs('admin.password.*') ? 'bg-navy text-white' : 'text-navy hover:bg-cream' }}">
                        Mon mot de passe
                    </a>
                @endunless
                <form method="POST" action="{{ route('admin.logout') }}">
```

(Leave the rest of that `<form>` and the closing `@endauth` exactly as they are — only the lines shown above change.)

- [ ] **Step 8: Create the view**

Create `resources/views/admin/password/edit.blade.php`:

```blade
<x-layouts.admin title="Mon mot de passe — Administration">
    <div class="mx-auto max-w-md rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)] md:p-8">
        <h1 class="font-display text-xl font-extrabold text-navy">Mon mot de passe</h1>
        <p class="mt-1 text-sm text-muted">
            Saisissez votre mot de passe actuel puis le nouveau.
        </p>

        @if (session('status'))
            <div class="mt-4 rounded-lg bg-success-bg px-4 py-3 text-sm text-success">
                {{ session('status') }}
            </div>
        @endif

        <form method="POST" action="{{ route('admin.password.update') }}" class="mt-4 flex flex-col gap-3">
            @csrf
            @method('PUT')

            <div class="flex flex-col gap-1.5">
                <label for="current_password" class="text-sm font-semibold">Mot de passe actuel</label>
                <input type="password" id="current_password" name="current_password" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="password" class="text-sm font-semibold">Nouveau mot de passe</label>
                <input type="password" id="password" name="password" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="password_confirmation" class="text-sm font-semibold">Confirmer le nouveau mot de passe</label>
                <input type="password" id="password_confirmation" name="password_confirmation" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>

            <button type="submit"
                class="mt-2 cursor-pointer self-start rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                Mettre à jour
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

- [ ] **Step 9: Run the tests to verify they pass**

Run: `php artisan test --compact --filter="PasswordChangeTest"`
Expected: PASS — all 6 tests green.

- [ ] **Step 10: Run the full suite to check for regressions**

Run: `php artisan test --compact`
Expected: PASS — no existing test broken by the new `auth.session.guard:web` middleware on the existing `admin/*` routes.

- [ ] **Step 11: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Requests/UpdatePasswordRequest.php app/Http/Controllers/Admin/PasswordController.php \
    resources/views/admin/password/edit.blade.php routes/web.php \
    resources/views/components/layouts/admin.blade.php tests/Pest.php \
    tests/Feature/Admin/PasswordChangeTest.php
git commit -m "feat: let org admins change their own password"
```

---

### Task 3: Super-admin password change (`super_admin` guard)

**Files:**
- Create: `app/Http/Requests/SuperAdmin/SuperAdminUpdatePasswordRequest.php`
- Create: `app/Http/Controllers/SuperAdmin/PasswordController.php`
- Create: `resources/views/super-admin/password/edit.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/components/layouts/super-admin.blade.php`
- Create: `tests/Feature/SuperAdmin/PasswordChangeTest.php`

**Interfaces:**
- Consumes: `auth.session.guard` alias from Task 1; `simulateNewRequestBoundary($app)` helper from Task 2 (`tests/Pest.php`).
- Produces: named routes `super-admin.password.edit` (`GET /superadmin/password`) and `super-admin.password.update` (`PUT /superadmin/password`).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/SuperAdmin/PasswordChangeTest.php`:

```php
<?php

use App\Models\SuperAdmin;
use Illuminate\Support\Facades\Hash;

it('redirects guests to login on edit', function () {
    $this->get(superAdminUrl('superadmin/password'))->assertRedirect(superAdminUrl('superadmin/login'));
});

it('redirects guests to login on update', function () {
    $this->put(superAdminUrl('superadmin/password'), [])->assertRedirect(superAdminUrl('superadmin/login'));
});

it('rejects an incorrect current password', function () {
    $superAdmin = SuperAdmin::factory()->create(['password' => 'old-password-123']);

    $this->actingAs($superAdmin, 'super_admin')
        ->put(superAdminUrl('superadmin/password'), [
            'current_password' => 'wrong-password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertSessionHasErrors(['current_password']);

    expect(Hash::check('old-password-123', $superAdmin->fresh()->password))->toBeTrue();
});

it('rejects a new password that does not match its confirmation', function () {
    $superAdmin = SuperAdmin::factory()->create(['password' => 'old-password-123']);

    $this->actingAs($superAdmin, 'super_admin')
        ->put(superAdminUrl('superadmin/password'), [
            'current_password' => 'old-password-123',
            'password' => 'new-password-123',
            'password_confirmation' => 'something-else',
        ])->assertSessionHasErrors(['password']);

    expect(Hash::check('old-password-123', $superAdmin->fresh()->password))->toBeTrue();
});

it('updates the password and lets the super-admin log in with the new one', function () {
    $superAdmin = SuperAdmin::factory()->create(['password' => 'old-password-123']);

    $this->actingAs($superAdmin, 'super_admin')
        ->put(superAdminUrl('superadmin/password'), [
            'current_password' => 'old-password-123',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertRedirect()
        ->assertSessionHas('status', 'Mot de passe mis à jour.');

    expect(Hash::check('new-password-123', $superAdmin->fresh()->password))->toBeTrue();

    config(['auth.defaults.guard' => 'web']);
    $this->app['auth']->forgetGuards();

    $this->post(superAdminUrl('superadmin/login'), [
        'email' => $superAdmin->email,
        'password' => 'new-password-123',
    ])->assertRedirect(superAdminUrl('superadmin/tenants'));
});

it('logs out other active sessions on the same account when the password changes', function () {
    config(['session.driver' => 'database']);

    $superAdmin = SuperAdmin::factory()->create(['password' => 'old-password-123']);
    $cookieName = config('session.cookie');

    $deviceA = $this->post(superAdminUrl('superadmin/login'), [
        'email' => $superAdmin->email,
        'password' => 'old-password-123',
    ]);
    $deviceA->assertRedirect(superAdminUrl('superadmin/tenants'));
    $cookieA = collect($deviceA->headers->getCookies())->first(fn ($c) => $c->getName() === $cookieName)->getValue();

    simulateNewRequestBoundary($this->app);

    $deviceB = $this->post(superAdminUrl('superadmin/login'), [
        'email' => $superAdmin->email,
        'password' => 'old-password-123',
    ]);
    $deviceB->assertRedirect(superAdminUrl('superadmin/tenants'));
    $cookieB = collect($deviceB->headers->getCookies())->first(fn ($c) => $c->getName() === $cookieName)->getValue();

    simulateNewRequestBoundary($this->app);
    $this->withUnencryptedCookie($cookieName, $cookieA)
        ->put(superAdminUrl('superadmin/password'), [
            'current_password' => 'old-password-123',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertRedirect();

    simulateNewRequestBoundary($this->app);
    $this->withUnencryptedCookie($cookieName, $cookieA)
        ->get(superAdminUrl('superadmin/tenants'))
        ->assertOk();

    simulateNewRequestBoundary($this->app);
    $this->withUnencryptedCookie($cookieName, $cookieB)
        ->get(superAdminUrl('superadmin/tenants'))
        ->assertRedirect(superAdminUrl('superadmin/login'));
});
```

Note: unlike Task 2's test, `actingAs($superAdmin, 'super_admin')` is followed by a real login POST in the "updates the password" test, which requires resetting `auth.defaults.guard` back to `web` first — `actingAs()` mutates that config as a side effect (`AuthManager::shouldUse()`), and a real separate HTTP request would never carry that mutation over.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter="SuperAdmin\\\\PasswordChangeTest"`
Expected: FAIL — `superAdminUrl('superadmin/password')` 404s (no route registered yet).

- [ ] **Step 3: Create the form request**

Create `app/Http/Requests/SuperAdmin/SuperAdminUpdatePasswordRequest.php`:

```php
<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class SuperAdminUpdatePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'current_password:super_admin'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}
```

- [ ] **Step 4: Create the controller**

Create `app/Http/Controllers/SuperAdmin/PasswordController.php`:

```php
<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\SuperAdminUpdatePasswordRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class PasswordController extends Controller
{
    public function edit(): View
    {
        return view('super-admin.password.edit');
    }

    public function update(SuperAdminUpdatePasswordRequest $request): RedirectResponse
    {
        Auth::guard('super_admin')->user()->update([
            'password' => $request->validated('password'),
        ]);

        return back()->with('status', 'Mot de passe mis à jour.');
    }
}
```

- [ ] **Step 5: Wire up the routes**

Modify `routes/web.php`. Add to the `use` statements at the top (alphabetically, after `App\Http\Controllers\SuperAdmin\ImpersonationController`):

```php
use App\Http\Controllers\SuperAdmin\PasswordController as SuperAdminPasswordController;
```

(Aliased because `App\Http\Controllers\Admin\PasswordController`, added in Task 2, would otherwise collide with an unaliased `App\Http\Controllers\SuperAdmin\PasswordController` import in the same file.)

Then change what's currently line 31 from:

```php
        Route::middleware('auth:super_admin')->group(function () {
```

to:

```php
        Route::middleware(['auth:super_admin', 'auth.session.guard:super_admin'])->group(function () {
```

Then add the two new routes inside that same group, after the existing `impersonate.stop` route:

```php
            Route::post('impersonate/stop', [ImpersonationController::class, 'stop'])->name('impersonate.stop');
            Route::get('password', [SuperAdminPasswordController::class, 'edit'])->name('password.edit');
            Route::put('password', [SuperAdminPasswordController::class, 'update'])->name('password.update');
```

Unlike Task 2, there's no impersonation ambiguity here — a super-admin session is always just a super-admin — so these routes join the existing group directly instead of needing a narrower one.

- [ ] **Step 6: Add the topbar link**

Modify `resources/views/components/layouts/super-admin.blade.php`. Replace:

```blade
                    <a href="{{ route('super-admin.dashboard') }}" class="text-navy hover:text-navy-hover">Tableau de bord</a>
                </div>
                <form method="POST" action="{{ route('super-admin.logout') }}">
```

with:

```blade
                    <a href="{{ route('super-admin.dashboard') }}" class="text-navy hover:text-navy-hover">Tableau de bord</a>
                    <a href="{{ route('super-admin.password.edit') }}" class="text-navy hover:text-navy-hover">Mon mot de passe</a>
                </div>
                <form method="POST" action="{{ route('super-admin.logout') }}">
```

- [ ] **Step 7: Create the view**

Create `resources/views/super-admin/password/edit.blade.php`:

```blade
<x-layouts.super-admin title="Mon mot de passe — Super-admin">
    <div class="mx-auto max-w-md rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)]">
        <h1 class="font-display text-xl font-extrabold text-navy">Mon mot de passe</h1>
        <p class="mt-1 text-sm text-muted">
            Saisissez votre mot de passe actuel puis le nouveau.
        </p>

        @if (session('status'))
            <div class="mt-4 rounded-lg bg-success-bg px-4 py-3 text-sm text-success">
                {{ session('status') }}
            </div>
        @endif

        <form method="POST" action="{{ route('super-admin.password.update') }}" class="mt-4 flex flex-col gap-3">
            @csrf
            @method('PUT')

            <div class="flex flex-col gap-1.5">
                <label for="current_password" class="text-sm font-semibold">Mot de passe actuel</label>
                <input type="password" id="current_password" name="current_password" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="password" class="text-sm font-semibold">Nouveau mot de passe</label>
                <input type="password" id="password" name="password" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="password_confirmation" class="text-sm font-semibold">Confirmer le nouveau mot de passe</label>
                <input type="password" id="password_confirmation" name="password_confirmation" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>

            <button type="submit"
                class="mt-2 cursor-pointer self-start rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                Mettre à jour
            </button>
        </form>

        @if ($errors->any())
            <div class="mt-4 rounded-lg bg-error-bg px-4 py-3 text-sm text-error">
                {{ $errors->first() }}
            </div>
        @endif
    </div>
</x-layouts.super-admin>
```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `php artisan test --compact --filter="SuperAdmin\\\\PasswordChangeTest"`
Expected: PASS — all 6 tests green.

- [ ] **Step 9: Run the full suite to check for regressions**

Run: `php artisan test --compact`
Expected: PASS — every test in the project, including Task 2's, still green.

- [ ] **Step 10: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Requests/SuperAdmin/SuperAdminUpdatePasswordRequest.php \
    app/Http/Controllers/SuperAdmin/PasswordController.php \
    resources/views/super-admin/password/edit.blade.php routes/web.php \
    resources/views/components/layouts/super-admin.blade.php \
    tests/Feature/SuperAdmin/PasswordChangeTest.php
git commit -m "feat: let super-admins change their own password"
```
