# Super-admin welcome page — spec

Date: 2026-07-30

## Context

The multi-tenant conversion (`docs/superpowers/specs/2026-07-23-multi-tenant-clubs-design.md`)
added a dedicated super-admin subdomain (`admin.<domain>`, `config('tenancy.super_admin_host')`)
with routes only under `/superadmin/*` (login, dashboard, tenant management —
see `routes/web.php:21-36`). There is no route for the bare domain root.

In production this was discovered live: visiting `https://admin.rcc-ife.org/`
returns a 404. The reason is that the public, tenant-facing route group
(`Route::middleware(ResolveTenant::class)->group(...)`, `routes/web.php:38`)
has no `->domain()` restriction, so it matches `GET /` on *every* host,
including the super-admin one. `ResolveTenant` then looks up "admin" as a
tenant subdomain, finds none, and 404s by design (no silent fallback — see
multi-tenant spec §2). This is correct behavior for an unregistered tenant
host, but leaves the super-admin domain's root with no dedicated landing
page, which is confusing for anyone typing the bare domain instead of going
straight to `/superadmin/login`.

## Goal

Give `admin.<domain>/` a real landing page: a minimal welcome screen with a
button to the super-admin login, so the root of that domain no longer 404s.
An already-authenticated super-admin hitting `/` should skip the welcome
screen and land directly on the dashboard.

## Design

### Routing

`routes/web.php` currently has:

```php
Route::domain(config('tenancy.super_admin_host'))->prefix('superadmin')->name('super-admin.')->group(function () {
    // login, dashboard, tenants, impersonation routes
});
```

This becomes an outer domain-only group wrapping the existing prefixed
group as one child, plus a new sibling route for the bare root:

```php
Route::domain(config('tenancy.super_admin_host'))->group(function () {
    Route::get('/', [WelcomeController::class, 'show'])->name('super-admin.welcome');

    Route::prefix('superadmin')->name('super-admin.')->group(function () {
        // unchanged: login, dashboard, tenants, impersonation routes
    });
});
```

Registration order is unchanged relative to the tenant-facing group below
it (`routes/web.php:38`), so `admin.<domain>` continues to be matched by
this domain-scoped group first, and every other host's `/` still falls
through to `ResolveTenant` exactly as today.

### Controller

New `app/Http/Controllers/SuperAdmin/WelcomeController.php`, one method:

```php
public function show(): View|RedirectResponse
{
    if (Auth::guard('super_admin')->check()) {
        return redirect()->route('super-admin.dashboard');
    }

    return view('super-admin.welcome');
}
```

The check is explicit and local to this controller rather than expressed
via the `guest:super_admin` middleware + a global `redirectUsersTo`
config — the existing `guest:super_admin` middleware is also applied to
the `/superadmin/login` routes, and changing `redirectUsersTo` globally
would alter that unrelated route's behavior too, which is out of scope
here.

### View

New `resources/views/super-admin/welcome.blade.php`, rendered through the
existing `x-layouts.super-admin` layout component (same layout the login
page uses). Content is minimal, matching the login page's visual style
(white rounded card, `font-display`/`navy`/`cream` palette):

- Platform name as heading.
- A single "Se connecter" button linking to `route('super-admin.login')`.

No tagline, no additional marketing copy — nothing else is shown or
collected on this page.

### Testing

New `tests/Feature/SuperAdminWelcomePageTest.php`, run in the regular
`Unit,Feature` suite (`:memory:` central connection, no tenant involved —
this page never touches the `sqlite`/tenant connection):

- Guest visiting `/` on the super-admin host gets a 200 response
  containing the login button/link.
- An authenticated super-admin visiting `/` on the super-admin host is
  redirected to `super-admin.dashboard`.
- (Existing coverage, unchanged) a request to `/` on any other host still
  404s via `ResolveTenant` — no new test needed, but the whole-branch
  review should confirm no regression here.

## Out of scope

- Any change to `guest:super_admin` / `redirectUsersTo` middleware
  behavior on the existing `/superadmin/login` routes.
- Marketing content, branding assets, or a tagline on the welcome page.
- A landing page for tenant subdomains (`org1.<domain>/`, etc.) — those
  already have a real page (`attendance.show`) and are unaffected.

## Files added/changed

- `app/Http/Controllers/SuperAdmin/WelcomeController.php` (new)
- `resources/views/super-admin/welcome.blade.php` (new)
- `routes/web.php` (wrap the existing super-admin domain group, add the
  `/` route)
- `tests/Feature/SuperAdminWelcomePageTest.php` (new)
