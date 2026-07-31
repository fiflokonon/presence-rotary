# Self-service password change (org admins & super-admins) — spec

Date: 2026-07-31

## Context

There is currently no way for a logged-in admin to change their own
password — neither org admins (`web` guard, `App\Models\User`, tenant
`sqlite` connection) nor super-admins (`super_admin` guard,
`App\Models\SuperAdmin`, `central` connection). Passwords are only ever set
once: a random 16-character password generated in
`Admin\UserController::store` and mailed via `SendNewAdminCredentialsMailJob`
(`app/Http/Controllers/Admin/UserController.php:31`).

The two guards have always been built as fully separate, duplicated stacks —
`Admin\AuthController` / `SuperAdmin\AuthController`, `App\Http\Requests\LoginRequest`
/ `App\Http\Requests\SuperAdmin\SuperAdminLoginRequest`, separate layouts
(`components/layouts/admin.blade.php` sidebar vs.
`components/layouts/super-admin.blade.php` topbar). This spec follows that
same convention rather than introducing a shared abstraction between them.

This spec covers only changing a known password while logged in. A "forgot
password" / email-reset flow is a separate, later spec — the `central`
connection doesn't even have a `password_reset_tokens` table today (only the
tenant `sqlite` connection does, via the default Laravel migration, and it's
currently unused).

**Cross-session invalidation caveat:** the user asked that other active
sessions be logged out when a password changes. Laravel's built-in mechanism
for this (`Auth::guard()->logoutOtherDevices()` + the `auth.session`
middleware) only tracks `config('auth.defaults.guard')` — it always compares
against a `password_hash_web`-style session key derived from the *default*
guard, with no way to parameterize it per-route. That's fine for the `web`
guard (the app's default), but silently wrong for `super_admin`. Rather than
leave that guard's "other sessions" requirement unmet, this spec adds a
small custom middleware (`AuthenticateSessionForGuard`, section 3) that's
guard-aware. It replaces `auth.session` for both guards' protected route
groups, so the behavior is identical and correct for both.

## Goal

An org admin can go to `/admin/password`, and a super-admin to
`/superadmin/password`, enter their current password plus a new one twice,
and save. On success: the password is updated, every other active session
for that account (any browser/device) is logged out the next time it makes a
request, and the current session shows a confirmation message. No email
involved.

## Design

### 1. Org admin password change (`web` guard)

New `App\Http\Controllers\Admin\PasswordController`:

```php
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
```

New `App\Http\Requests\UpdatePasswordRequest`:

```php
public function authorize(): bool
{
    return true;
}

public function rules(): array
{
    return [
        'current_password' => ['required', 'current_password:web'],
        'password' => ['required', 'confirmed', Password::defaults()],
    ];
}
```

`current_password:web` is Laravel's built-in rule
(`ValidatesAttributes::validateCurrentPassword`) — it re-checks the
submitted value against the authenticated `web` user's stored hash, so the
controller never has to do that itself. `Password::defaults()` is the
framework default (min 8 characters) — nothing stricter exists anywhere else
in this app, so there's no reason to invent a custom policy here.

The `AuthenticateSessionForGuard` middleware (section 3) needs to run on
**every** protected `admin/*` route, not just the password page — otherwise
a stale session would only get caught if it happened to hit
`/admin/password` again, and would stay valid indefinitely on every other
page. So the existing protected group gains it:

```php
Route::middleware(['auth:web,super_admin', 'auth.session.guard:web'])->group(function () {
    // ...all existing routes: logout, sessions, users, members, titles,
    // positions, mail-settings, checkin-settings, club-settings...
});
```

This is safe to add unconditionally: the middleware only acts when the
`web` guard specifically has a user (see section 3), so it's a no-op for a
super-admin browsing while impersonating (who's authenticated via the
`super_admin` guard, not `web`).

The password routes themselves, however, get their own **separate** group
restricted to `auth:web` only — deliberately narrower than the
`auth:web,super_admin` block above:

```php
Route::middleware(['auth:web', 'auth.session.guard:web'])->group(function () {
    Route::get('password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('password', [PasswordController::class, 'update'])->name('password.update');
});
```

**Impersonation note:** `admin/*` routes generally accept both `web` and
`super_admin` guards so an impersonating super-admin can browse the panel.
The `Authenticate` middleware calls `Auth::shouldUse()` on whichever guard
matched, so on those routes "the current admin" isn't reliably the `web`
guard. Password change is scoped to `auth:web` only — impersonating
super-admins can't reach `/admin/password` at all, and the new sidebar link
is hidden while impersonating (`session()->has('impersonating_tenant_id')`,
already the pattern used for the "Quitter la vue" banner in
`components/layouts/admin.blade.php`). Changing a tenant's admin password
while impersonating isn't a use case anyone asked for; it can be a future
spec if it comes up.

New sidebar entry, next to "Se déconnecter" in
`resources/views/components/layouts/admin.blade.php`:

```blade
@auth
    @unless (session()->has('impersonating_tenant_id'))
        <a href="{{ route('admin.password.edit') }}" @click="close()"
            class="cursor-pointer rounded-lg px-3 py-2 text-sm font-semibold {{ request()->routeIs('admin.password.*') ? 'bg-navy text-white' : 'text-navy hover:bg-cream' }}">
            Mon mot de passe
        </a>
    @endunless
    <form method="POST" action="{{ route('admin.logout') }}">
        ...
```

New view `resources/views/admin/password/edit.blade.php` (`<x-layouts.admin>`),
following the plain-form conventions of `admin/club-settings/edit.blade.php`:
fields for "Mot de passe actuel", "Nouveau mot de passe", "Confirmer le
nouveau mot de passe", a "Mettre à jour" submit button, and the `status`
flash message shown as a success banner when present.

### 2. Super-admin password change (`super_admin` guard)

Mirrors section 1 exactly, in the `SuperAdmin` namespace:

- `App\Http\Controllers\SuperAdmin\PasswordController` — same shape, using
  `Auth::guard('super_admin')`.
- `App\Http\Requests\SuperAdmin\SuperAdminUpdatePasswordRequest` — same
  rules, with `current_password:super_admin`.
- No impersonation ambiguity on this side — a super-admin is always just a
  super-admin here — so, unlike the `web` side, the password routes join the
  existing protected group directly instead of needing a narrower one:
  ```php
  Route::middleware(['auth:super_admin', 'auth.session.guard:super_admin'])->group(function () {
      // ...existing routes: logout, dashboard, tenants, impersonate...
      Route::get('superadmin/password', [PasswordController::class, 'edit'])->name('super-admin.password.edit');
      Route::put('superadmin/password', [PasswordController::class, 'update'])->name('super-admin.password.update');
  });
  ```
- New link in `components/layouts/super-admin.blade.php`'s topbar, next to
  "Déconnexion":
  ```blade
  <a href="{{ route('super-admin.password.edit') }}" class="text-navy hover:text-navy-hover">Mon mot de passe</a>
  ```
- New view `resources/views/super-admin/password/edit.blade.php`
  (`<x-layouts.super-admin>`), same fields as section 1's view.

### 3. Guard-aware cross-session logout

New `App\Http\Middleware\AuthenticateSessionForGuard`, applied (see sections
1–2) to both guards' protected groups in place of Laravel's stock
`auth.session`:

```php
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

Each authenticated session stores the password hash it logged in with. Every
request re-checks that hash against the account's current one; a mismatch
(caused by a password change from a different session) logs that session out
immediately. Because the change itself updates the DB row, no explicit
"kick the others out" call is needed anywhere — this middleware alone makes
it happen on their very next request. This also means
`Auth::guard()->logoutOtherDevices()` is **not** used anywhere in this
feature — it's unnecessary once this middleware is in place, and it wouldn't
have covered `super_admin` correctly on its own (see Context).

Registered as an alias in `bootstrap/app.php`:

```php
$middleware->alias([
    'auth.session.guard' => \App\Http\Middleware\AuthenticateSessionForGuard::class,
]);
```

### 4. Files touched

- `app/Http/Middleware/AuthenticateSessionForGuard.php` (new)
- `app/Http/Controllers/Admin/PasswordController.php` (new)
- `app/Http/Controllers/SuperAdmin/PasswordController.php` (new)
- `app/Http/Requests/UpdatePasswordRequest.php` (new)
- `app/Http/Requests/SuperAdmin/SuperAdminUpdatePasswordRequest.php` (new)
- `resources/views/admin/password/edit.blade.php` (new)
- `resources/views/super-admin/password/edit.blade.php` (new)
- `resources/views/components/layouts/admin.blade.php` (sidebar entry)
- `resources/views/components/layouts/super-admin.blade.php` (topbar entry)
- `bootstrap/app.php` (middleware alias)
- `routes/web.php` (four new routes; `auth.session.guard` added to the
  existing `admin/*` and `superadmin/*` protected groups; one new narrower
  group for the `web`-only password routes)

## Testing

New `tests/Feature/Admin/PasswordChangeTest.php`:

- Guest is redirected to `admin.login` for both routes.
- Wrong current password → validation error on `current_password`, password
  unchanged.
- New password + confirmation mismatch → validation error, password
  unchanged.
- Valid submission → redirect back with `status` flash, and the new password
  works on a fresh login attempt (old one no longer does).
- A second, separate authenticated session (simulated via a second
  `actingAs()`-style client hitting a protected `admin/*` route after the
  first session's password change) gets logged out on its next request.

New `tests/Feature/SuperAdmin/PasswordChangeTest.php`, same cases against
`super-admin.password.*` and the `super_admin` guard.

No separate middleware unit test — this codebase has no isolated
unit/middleware tests anywhere (`tests/Unit/` only holds enum tests); every
other middleware is covered through the Feature tests that exercise it. The
"second session gets logged out on its next request" case in both
`PasswordChangeTest` files above is exactly that coverage for
`AuthenticateSessionForGuard` — including its edge cases (first request
seeding the hash, matching hash passing through) would be redundant with
what those two scenarios already prove.

## Out of scope

- "Forgot password" / email-based reset (separate future spec).
- Password change while impersonating a tenant (see the impersonation note
  in section 1).
- Any password strength policy beyond Laravel's `Password::defaults()`.
- Rate limiting on the password-change form — it's already behind
  authentication, unlike the login form (which has its own `RateLimiter`
  usage in `LoginRequest`/`SuperAdminLoginRequest`).
