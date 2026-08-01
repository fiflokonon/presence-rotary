# Admin Dashboard & Visual Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the admin panel a real landing dashboard (KPI stat cards, two Chart.js charts, recent activity) and a global icon-driven visual refresh, per `docs/superpowers/specs/2026-08-01-admin-dashboard-redesign-design.md`.

**Architecture:** A new `admin.dashboard` route/controller/view becomes the post-login landing page, replacing the current redirect to `admin.sessions.index`. All aggregation happens in the controller (read-only queries, no new service class). Icons come from Font Awesome Free 6, loaded via the free cdnjs CDN — no Composer/npm icon package. Charts come from `chart.js`, installed via npm and bundled through the existing Vite pipeline, driven by a new Alpine.js component.

**Tech Stack:** Laravel 13, Blade, Tailwind v4, Alpine.js 3, Chart.js (new), Font Awesome 6 (CDN, new), Pest 4.

## Global Constraints

- Palette stays navy/cream/gold — no new Tailwind color tokens. Existing tokens: `--color-navy: #12213D`, `--color-navy-hover: #1c3559`, `--color-gold: #C77700`, `--color-cream: #F5F3EE`, `--color-success: #0E7C66`/`--color-success-bg: #E7F5F1`, `--color-muted: #6B6558`/`--color-muted-strong: #8A8474`, `--color-border: #DEDAD0`, `--color-divider: #EDEAE2`, `--color-error: #B23B3B`/`--color-error-bg: #FBEAEA` (`resources/css/app.css`).
- Existing page cards already use `rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)] md:p-8` consistently across every `admin/**` view — do not change this pattern, it's already the target style. The dashboard's own cards should match it.
- No changes to existing pages' functionality — Membres, Titres, Organisations, etc. keep their current structure; only icons/labels are touched where explicitly listed below.
- Run `vendor/bin/pint --dirty --format agent` after every task that touches PHP files, before committing.
- Run `php artisan test --compact` after every task; all tests must pass before committing.
- Only commit when a task's steps say to — don't bundle multiple tasks into one commit.

---

### Task 1: `admin.dashboard` route, controller, KPI view, and redirect-target migration

**Files:**
- Create: `app/Http/Controllers/Admin/DashboardController.php`
- Create: `resources/views/admin/dashboard.blade.php`
- Create: `tests/Feature/Admin/DashboardTest.php`
- Modify: `routes/web.php`
- Modify: `bootstrap/app.php`
- Modify: `app/Http/Controllers/Admin/AuthController.php`
- Modify: `app/Http/Controllers/SuperAdmin/ImpersonationController.php`
- Modify: `resources/views/components/layouts/admin.blade.php`
- Modify: `tests/Feature/Admin/AuthTest.php`
- Modify: `tests/Feature/Admin/PasswordChangeTest.php`
- Modify: `tests/Feature/Session/TenantSafeDatabaseSessionHandlerTest.php`
- Modify: `tests/Feature/SuperAdmin/TenantProvisioningLoginTest.php`
- Modify: `tests/Tenancy/ImpersonationViewTest.php`

**Interfaces:**
- Produces: route `admin.dashboard` (GET `/admin/dashboard`), `DashboardController::index(): View` returning view `admin.dashboard` with keys `activeMembersCount` (int), `totalSessionsCount` (int), `averageAttendanceRate` (int|null), `lastSession` (MeetingSession|null, with `present_count`/`attendances_count` dynamic attributes), `attendanceTrend` (Collection<MeetingSession>, oldest→newest, each with `present_count`/`attendances_count`), `lastSessionBreakdown` (Collection<string, int> keyed by group label), `recentSessions` (Collection<MeetingSession>, max 5, newest first).
- Consumes: `App\Models\MeetingSession` (`attendances()` relation), `App\Models\Member`, `App\Models\Attendance` (`groupLabel` accessor) — all pre-existing, unchanged.

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/Admin/DashboardTest.php`:

```php
<?php

use App\Models\Attendance;
use App\Models\MeetingSession;
use App\Models\Member;
use App\Models\Title;
use App\Models\User;

it('redirects guests to login', function () {
    $this->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
});

it('shows an empty state when there are no sessions yet', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('Pas encore de séance');
});

it('shows KPI numbers computed from seeded data', function () {
    Member::factory()->count(5)->create();

    $rotary = Title::where('name', 'Rotary')->sole();

    $olderSession = MeetingSession::factory()->create(['date' => '2026-07-01']);
    Attendance::factory()->for($olderSession)->create(['title_id' => $rotary->id, 'present' => true]);
    Attendance::factory()->for($olderSession)->create(['title_id' => $rotary->id, 'present' => false]);

    $lastSession = MeetingSession::factory()->create(['date' => '2026-07-15']);
    Attendance::factory()->for($lastSession)->create(['title_id' => $rotary->id, 'present' => true]);
    Attendance::factory()->for($lastSession)->create(['title_id' => $rotary->id, 'present' => true]);
    Attendance::factory()->for($lastSession)->create(['title_id' => $rotary->id, 'present' => false]);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('5') // membres actifs
        ->assertSee('59 %') // moyenne des taux (50 et 67 arrondis)
        ->assertSee('2/3') // dernière séance : présents/total
        ->assertDontSee('Pas encore de séance');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=DashboardTest`
Expected: FAIL — route `admin.dashboard` doesn't exist (`RouteNotFoundException`).

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/Admin/DashboardController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MeetingSession;
use App\Models\Member;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $recentSessions = MeetingSession::query()
            ->latest('date')
            ->take(10)
            ->withCount([
                'attendances',
                'attendances as present_count' => fn ($query) => $query->where('present', true),
            ])
            ->get();

        $rates = $recentSessions
            ->filter(fn (MeetingSession $session) => $session->attendances_count > 0)
            ->map(fn (MeetingSession $session) => round($session->present_count / $session->attendances_count * 100));

        $lastSession = $recentSessions->first();

        return view('admin.dashboard', [
            'activeMembersCount' => Member::count(),
            'totalSessionsCount' => MeetingSession::count(),
            'averageAttendanceRate' => $rates->isNotEmpty() ? (int) round($rates->average()) : null,
            'lastSession' => $lastSession,
            'attendanceTrend' => $recentSessions->reverse()->values(),
            'lastSessionBreakdown' => $lastSession
                ? $lastSession->attendances()->where('present', true)->get()->groupBy(fn ($attendance) => $attendance->groupLabel)->map->count()
                : collect(),
            'recentSessions' => $recentSessions->take(5),
        ]);
    }
}
```

- [ ] **Step 4: Register the route**

In `routes/web.php`, add the import and route. First add the import alongside the other `Admin\` controller imports:

```php
use App\Http\Controllers\Admin\CheckinSettingController;
use App\Http\Controllers\Admin\ClubSettingController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\MailSettingController;
```

Then add the route as the **first** entry inside the `Route::middleware(['auth:web,super_admin', 'auth.session.guard:web'])->group(function () { ... })` block (immediately after the opening `{`, before `Route::post('logout', ...)`):

```php
        Route::middleware(['auth:web,super_admin', 'auth.session.guard:web'])->group(function () {
            Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
            Route::post('logout', [AuthController::class, 'destroy'])->name('logout');
```

- [ ] **Step 5: Create the view**

Create `resources/views/admin/dashboard.blade.php`:

```blade
<x-layouts.admin title="Tableau de bord — Administration">
    <div class="flex flex-col gap-6">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)]">
                <p class="text-xs font-semibold uppercase text-muted-strong">Membres actifs</p>
                <p class="mt-2 font-display text-3xl font-extrabold text-navy">{{ $activeMembersCount }}</p>
            </div>
            <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)]">
                <p class="text-xs font-semibold uppercase text-muted-strong">Séances organisées</p>
                <p class="mt-2 font-display text-3xl font-extrabold text-navy">{{ $totalSessionsCount }}</p>
            </div>
            <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)]">
                <p class="text-xs font-semibold uppercase text-muted-strong">Taux de présence moyen</p>
                <p class="mt-2 font-display text-3xl font-extrabold text-navy">
                    {{ $averageAttendanceRate !== null ? $averageAttendanceRate.' %' : '—' }}
                </p>
            </div>
            <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)]">
                <p class="text-xs font-semibold uppercase text-muted-strong">Dernière séance</p>
                @if ($lastSession)
                    <p class="mt-2 font-display text-3xl font-extrabold text-navy">{{ $lastSession->present_count }}/{{ $lastSession->attendances_count }}</p>
                    <p class="mt-1 text-xs text-muted">{{ $lastSession->date->translatedFormat('d F Y') }}</p>
                @else
                    <p class="mt-2 text-sm text-muted">Aucune séance</p>
                @endif
            </div>
        </div>

        @if ($recentSessions->isEmpty())
            <div class="rounded-2xl bg-white p-6 text-center shadow-[0_2px_10px_rgba(20,30,50,.06)] md:p-8">
                <p class="text-sm text-muted">Pas encore de séance. Créez votre première séance depuis « Séances ».</p>
            </div>
        @else
            <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)] md:p-8">
                <h2 class="font-display text-lg font-extrabold text-navy">Activité récente</h2>
                <ul class="mt-4 divide-y divide-divider">
                    @foreach ($recentSessions as $session)
                        <li>
                            <a href="{{ route('admin.sessions.show', $session) }}"
                                class="flex cursor-pointer items-center justify-between gap-3 rounded-lg py-3 pl-2 pr-2 hover:bg-cream">
                                <span class="min-w-0 truncate text-sm font-semibold text-navy">
                                    {{ $session->title }} — {{ $session->date->translatedFormat('d F Y') }}
                                </span>
                                <span class="shrink-0 rounded-full bg-success-bg px-2 py-0.5 text-[11px] font-semibold uppercase text-success">
                                    {{ $session->present_count }}/{{ $session->attendances_count }}
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
</x-layouts.admin>
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test --compact --filter=DashboardTest`
Expected: PASS (3 tests).

- [ ] **Step 7: Point post-login/impersonation/guest redirects at the new dashboard**

In `bootstrap/app.php`, change:

```php
        $middleware->redirectUsersTo(fn (Request $request) => $request->getHost() === config('tenancy.super_admin_host')
            ? route('super-admin.dashboard')
            : route('admin.sessions.index'));
```

to:

```php
        $middleware->redirectUsersTo(fn (Request $request) => $request->getHost() === config('tenancy.super_admin_host')
            ? route('super-admin.dashboard')
            : route('admin.dashboard'));
```

In `app/Http/Controllers/Admin/AuthController.php`, in `store()`, change:

```php
        return redirect()->route('admin.sessions.index');
```

to:

```php
        return redirect()->route('admin.dashboard');
```

In `app/Http/Controllers/SuperAdmin/ImpersonationController.php`, in `start()`, change:

```php
        return redirect()->route('admin.sessions.index');
```

to:

```php
        return redirect()->route('admin.dashboard');
```

- [ ] **Step 8: Add the sidebar nav link**

In `resources/views/components/layouts/admin.blade.php`, add a new first item inside `<nav class="mt-6 flex flex-1 flex-col gap-1">`, immediately before the `Séances` link:

```blade
            <nav class="mt-6 flex flex-1 flex-col gap-1">
                <a href="{{ route('admin.dashboard') }}" @click="close()"
                    class="cursor-pointer rounded-lg px-3 py-2 text-sm font-semibold {{ request()->routeIs('admin.dashboard') ? 'bg-navy text-white' : 'text-navy hover:bg-cream' }}">
                    Tableau de bord
                </a>
                <a href="{{ route('admin.sessions.index') }}" @click="close()"
```

- [ ] **Step 9: Update existing tests that assert the old post-login/impersonation redirect target**

In `tests/Feature/Admin/AuthTest.php`, change both occurrences of `route('admin.sessions.index')` used as a post-login/guest-redirect-when-authenticated target to `route('admin.dashboard')` — leave the `redirects guests hitting admin routes to the login form` test (line 11, which only uses `admin.sessions.index` as *a* protected route to probe, not as a redirect destination) unchanged:

```php
it('logs an admin in with valid credentials', function () {
    $user = User::factory()->create(['password' => bcrypt('secret123')]);

    $this->post(route('admin.login'), [
        'email' => $user->email,
        'password' => 'secret123',
    ])->assertRedirect(route('admin.dashboard'));

    $this->assertAuthenticatedAs($user);
});
```

```php
it('redirects an already-authenticated admin visiting the login form to the admin panel', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('admin.login'))
        ->assertRedirect(route('admin.dashboard'));
});
```

In `tests/Feature/Admin/PasswordChangeTest.php`, change every `route('admin.sessions.index')` occurrence (both as `assertRedirect()` targets and as the follow-up `->get(...)->assertOk()` probe used to seed/verify the session baseline) to `route('admin.dashboard')` — this affects the `updates the password and lets the admin log in with the new one` test and the `logs out other active sessions...` test (7 occurrences total across both tests).

In `tests/Feature/Session/TenantSafeDatabaseSessionHandlerTest.php`, change:

```php
    $this->post(route('admin.login'), [
        'email' => $user->email,
        'password' => 'secret12345',
    ])->assertRedirect(route('admin.sessions.index'));
```

to:

```php
    $this->post(route('admin.login'), [
        'email' => $user->email,
        'password' => 'secret12345',
    ])->assertRedirect(route('admin.dashboard'));
```

In `tests/Feature/SuperAdmin/TenantProvisioningLoginTest.php`, change:

```php
    $response->assertSessionHasNoErrors();
    $response->assertRedirect('http://nouveau.example.test/admin/sessions');

    // A second, separate request re-using the session cookie is where the
    // DatabaseSessionHandler must re-hydrate the user via the tenant connection.
    $dashboard = $this->get('http://nouveau.example.test/admin/sessions');
    $dashboard->assertOk();
```

to:

```php
    $response->assertSessionHasNoErrors();
    $response->assertRedirect('http://nouveau.example.test/admin/dashboard');

    // A second, separate request re-using the session cookie is where the
    // DatabaseSessionHandler must re-hydrate the user via the tenant connection.
    $dashboard = $this->get('http://nouveau.example.test/admin/dashboard');
    $dashboard->assertOk();
```

In `tests/Tenancy/ImpersonationViewTest.php`, change:

```php
    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->post(superAdminUrl("superadmin/tenants/{$tenant->id}/impersonate"))
        ->assertRedirect(route('admin.sessions.index'));

    $this->withSession(['impersonating_tenant_id' => $tenant->id])
        ->actingAs($tenantAdmin)
        ->get(superAdminUrl('admin/sessions'))
        ->assertOk()
        ->assertSee('RC Cotonou Ife');
```

to:

```php
    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->post(superAdminUrl("superadmin/tenants/{$tenant->id}/impersonate"))
        ->assertRedirect(route('admin.dashboard'));

    $this->withSession(['impersonating_tenant_id' => $tenant->id])
        ->actingAs($tenantAdmin)
        ->get(superAdminUrl('admin/dashboard'))
        ->assertOk()
        ->assertSee('RC Cotonou Ife');
```

- [ ] **Step 10: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`
Expected: all files pass or get auto-fixed.

- [ ] **Step 11: Run the full test suite**

Run: `php artisan test --compact`
Expected: all tests pass (no more references to the old redirect target anywhere).

- [ ] **Step 12: Commit**

```bash
git add app/Http/Controllers/Admin/DashboardController.php \
        resources/views/admin/dashboard.blade.php \
        tests/Feature/Admin/DashboardTest.php \
        routes/web.php bootstrap/app.php \
        app/Http/Controllers/Admin/AuthController.php \
        app/Http/Controllers/SuperAdmin/ImpersonationController.php \
        resources/views/components/layouts/admin.blade.php \
        tests/Feature/Admin/AuthTest.php \
        tests/Feature/Admin/PasswordChangeTest.php \
        tests/Feature/Session/TenantSafeDatabaseSessionHandlerTest.php \
        tests/Feature/SuperAdmin/TenantProvisioningLoginTest.php \
        tests/Tenancy/ImpersonationViewTest.php
git commit -m "feat: add admin dashboard as the new post-login landing page"
```

---

### Task 2: Font Awesome CDN + sidebar & key action-button icons

**Files:**
- Modify: `resources/views/components/layouts/admin.blade.php`
- Modify: `resources/views/admin/sessions/index.blade.php`
- Modify: `resources/views/admin/sessions/show.blade.php`
- Modify: `resources/views/admin/users/create.blade.php`
- Modify: `resources/views/admin/titles/index.blade.php`
- Modify: `resources/views/admin/positions/index.blade.php`

**Interfaces:**
- Consumes: Font Awesome Free 6 CDN classes (e.g. `fa-solid fa-gauge`) — no Composer/npm package, no PHP interface.
- Produces: nothing consumed by later tasks — purely visual, independent of Task 3.

- [ ] **Step 1: Load Font Awesome via CDN**

In `resources/views/components/layouts/admin.blade.php`, add the stylesheet link in `<head>`, right after the `<title>` tag:

```blade
    <title>{{ $title }}</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
```

- [ ] **Step 2: Add icons to every sidebar nav item**

In `resources/views/components/layouts/admin.blade.php`, update the `<nav>` block (each `<a>`/`<button>` gets an `<i>` icon before its text, wrapped so text and icon sit inline):

```blade
            <nav class="mt-6 flex flex-1 flex-col gap-1">
                <a href="{{ route('admin.dashboard') }}" @click="close()"
                    class="cursor-pointer flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold {{ request()->routeIs('admin.dashboard') ? 'bg-navy text-white' : 'text-navy hover:bg-cream' }}">
                    <i class="fa-solid fa-gauge w-4 text-center" aria-hidden="true"></i> Tableau de bord
                </a>
                <a href="{{ route('admin.sessions.index') }}" @click="close()"
                    class="cursor-pointer flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold {{ request()->routeIs('admin.sessions.*') ? 'bg-navy text-white' : 'text-navy hover:bg-cream' }}">
                    <i class="fa-solid fa-calendar-days w-4 text-center" aria-hidden="true"></i> Séances
                </a>
                <a href="{{ route('admin.users.index') }}" @click="close()"
                    class="cursor-pointer flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold {{ request()->routeIs('admin.users.*') ? 'bg-navy text-white' : 'text-navy hover:bg-cream' }}">
                    <i class="fa-solid fa-user-shield w-4 text-center" aria-hidden="true"></i> Administrateurs
                </a>
                <a href="{{ route('admin.club-settings.edit') }}" @click="close()"
                    class="cursor-pointer flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold {{ request()->routeIs('admin.club-settings.*') ? 'bg-navy text-white' : 'text-navy hover:bg-cream' }}">
                    <i class="fa-solid fa-palette w-4 text-center" aria-hidden="true"></i> Identité du club
                </a>
                <a href="{{ route('admin.mail-settings.edit') }}" @click="close()"
                    class="cursor-pointer flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold {{ request()->routeIs('admin.mail-settings.*') ? 'bg-navy text-white' : 'text-navy hover:bg-cream' }}">
                    <i class="fa-solid fa-envelope w-4 text-center" aria-hidden="true"></i> Paramètres mail
                </a>
                <a href="{{ route('admin.checkin-settings.edit') }}" @click="close()"
                    class="cursor-pointer flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold {{ request()->routeIs('admin.checkin-settings.*') ? 'bg-navy text-white' : 'text-navy hover:bg-cream' }}">
                    <i class="fa-solid fa-list-check w-4 text-center" aria-hidden="true"></i> Paramètres formulaire
                </a>
                <a href="{{ route('admin.members.index') }}" @click="close()"
                    class="cursor-pointer flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold {{ request()->routeIs('admin.members.*') ? 'bg-navy text-white' : 'text-navy hover:bg-cream' }}">
                    <i class="fa-solid fa-users w-4 text-center" aria-hidden="true"></i> Membres
                </a>
                <a href="{{ route('admin.titles.index') }}" @click="close()"
                    class="cursor-pointer flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold {{ request()->routeIs('admin.titles.*') ? 'bg-navy text-white' : 'text-navy hover:bg-cream' }}">
                    <i class="fa-solid fa-sitemap w-4 text-center" aria-hidden="true"></i> Organisations
                </a>
                <a href="{{ route('admin.positions.index') }}" @click="close()"
                    class="cursor-pointer flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold {{ request()->routeIs('admin.positions.*') ? 'bg-navy text-white' : 'text-navy hover:bg-cream' }}">
                    <i class="fa-solid fa-id-badge w-4 text-center" aria-hidden="true"></i> Titres/Qualités
                </a>
            </nav>
```

Then update the "Mon mot de passe" and "Se déconnecter" items right below:

```blade
            @auth
                @unless (session()->has('impersonating_tenant_id'))
                    <a href="{{ route('admin.password.edit') }}" @click="close()"
                        class="cursor-pointer flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold {{ request()->routeIs('admin.password.*') ? 'bg-navy text-white' : 'text-navy hover:bg-cream' }}">
                        <i class="fa-solid fa-key w-4 text-center" aria-hidden="true"></i> Mon mot de passe
                    </a>
                @endunless
                <form method="POST" action="{{ route('admin.logout') }}">
                    @csrf
                    <button type="submit"
                        class="cursor-pointer flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm font-semibold text-gold hover:bg-cream">
                        <i class="fa-solid fa-right-from-bracket w-4 text-center" aria-hidden="true"></i> Se déconnecter
                    </button>
                </form>
            @endauth
```

- [ ] **Step 3: Add icons to the "create" and "export" buttons**

In `resources/views/admin/sessions/index.blade.php`, change:

```blade
            <button type="submit"
                class="cursor-pointer rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                Créer et activer
            </button>
```

to:

```blade
            <button type="submit"
                class="cursor-pointer flex items-center gap-2 rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                <i class="fa-solid fa-plus" aria-hidden="true"></i> Créer et activer
            </button>
```

In `resources/views/admin/sessions/show.blade.php`, change:

```blade
                    <a href="{{ route('admin.sessions.export-pdf', $meetingSession) }}" download
                        class="cursor-pointer w-full rounded-lg bg-navy px-4 py-2 text-center text-sm font-bold text-white hover:bg-navy-hover md:w-auto">
                        Exporter en PDF
                    </a>
```

to:

```blade
                    <a href="{{ route('admin.sessions.export-pdf', $meetingSession) }}" download
                        class="cursor-pointer flex w-full items-center justify-center gap-2 rounded-lg bg-navy px-4 py-2 text-center text-sm font-bold text-white hover:bg-navy-hover md:w-auto">
                        <i class="fa-solid fa-file-pdf" aria-hidden="true"></i> Exporter en PDF
                    </a>
```

In `resources/views/admin/users/create.blade.php`, change:

```blade
            <button type="submit"
                class="mt-2 cursor-pointer self-start rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                Créer l'admin
            </button>
```

to:

```blade
            <button type="submit"
                class="mt-2 cursor-pointer flex items-center gap-2 self-start rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                <i class="fa-solid fa-plus" aria-hidden="true"></i> Créer l'admin
            </button>
```

In `resources/views/admin/titles/index.blade.php`, change:

```blade
            <a href="{{ route('admin.titles.create') }}"
                class="cursor-pointer rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                Ajouter une organisation
            </a>
```

to:

```blade
            <a href="{{ route('admin.titles.create') }}"
                class="cursor-pointer flex items-center gap-2 rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                <i class="fa-solid fa-plus" aria-hidden="true"></i> Ajouter une organisation
            </a>
```

and change:

```blade
                                        <button type="submit" class="cursor-pointer text-sm font-semibold text-error underline">
                                            Supprimer
                                        </button>
```

to:

```blade
                                        <button type="submit" class="cursor-pointer inline-flex items-center gap-1 text-sm font-semibold text-error underline">
                                            <i class="fa-solid fa-trash" aria-hidden="true"></i> Supprimer
                                        </button>
```

In `resources/views/admin/positions/index.blade.php`, change:

```blade
            <a href="{{ route('admin.positions.create') }}"
                class="cursor-pointer rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                Ajouter un titre/qualité
            </a>
```

to:

```blade
            <a href="{{ route('admin.positions.create') }}"
                class="cursor-pointer flex items-center gap-2 rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                <i class="fa-solid fa-plus" aria-hidden="true"></i> Ajouter un titre/qualité
            </a>
```

and change:

```blade
                                        <button type="submit" class="cursor-pointer text-sm font-semibold text-error underline">
                                            Supprimer
                                        </button>
```

to:

```blade
                                        <button type="submit" class="cursor-pointer inline-flex items-center gap-1 text-sm font-semibold text-error underline">
                                            <i class="fa-solid fa-trash" aria-hidden="true"></i> Supprimer
                                        </button>
```

- [ ] **Step 4: Run the full test suite**

Run: `php artisan test --compact`
Expected: all tests still pass — none of these changes alter route names, form actions, or text assertions any existing test checks (icons are additive `<i>` tags, button label text is unchanged).

- [ ] **Step 5: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 6: Manually verify in the browser**

Start the dev server (`composer run dev` or `npm run dev` + `php artisan serve`, whichever the project's existing convention is) and visually confirm: sidebar shows an icon before every label, the create/export/delete buttons show their icon, nothing is visually broken or misaligned. This step has no automated test — Font Awesome CDN icon rendering can't be asserted via Pest.

- [ ] **Step 7: Commit**

```bash
git add resources/views/components/layouts/admin.blade.php \
        resources/views/admin/sessions/index.blade.php \
        resources/views/admin/sessions/show.blade.php \
        resources/views/admin/users/create.blade.php \
        resources/views/admin/titles/index.blade.php \
        resources/views/admin/positions/index.blade.php
git commit -m "style: add Font Awesome icons to sidebar nav and key action buttons"
```

---

### Task 3: Chart.js dashboard charts

**Files:**
- Modify: `package.json` (via `npm install`)
- Modify: `resources/js/app.js`
- Modify: `resources/views/admin/dashboard.blade.php`

**Interfaces:**
- Consumes: `attendanceTrend` and `lastSessionBreakdown` from `DashboardController::index()` (Task 1) — passed into the view as-is, serialized via `@js(...)`.
- Produces: nothing consumed by later tasks — this is the final task.

- [ ] **Step 1: Install Chart.js**

Run: `npm install chart.js`
Expected: `chart.js` appears in `package.json` dependencies and `package-lock.json` is updated.

- [ ] **Step 2: Add the Alpine chart component**

In `resources/js/app.js`, add the import at the top:

```js
import Alpine from 'alpinejs';
import QRCode from 'qrcode';
import Chart from 'chart.js/auto';
```

Then add a new `Alpine.data('dashboardCharts', ...)` block — place it right after the existing `Alpine.data('adminShell', ...)` block:

```js
Alpine.data('dashboardCharts', (trendLabels, trendRates, breakdownLabels, breakdownCounts) => ({
    init() {
        if (trendLabels.length > 0) {
            new Chart(this.$refs.trendCanvas, {
                type: 'line',
                data: {
                    labels: trendLabels,
                    datasets: [{
                        label: 'Taux de présence (%)',
                        data: trendRates,
                        borderColor: '#12213D',
                        backgroundColor: 'rgba(18, 33, 61, 0.08)',
                        tension: 0.3,
                        fill: true,
                    }],
                },
                options: {
                    scales: { y: { min: 0, max: 100, ticks: { callback: (value) => value + ' %' } } },
                    plugins: { legend: { display: false } },
                },
            });
        }

        if (breakdownLabels.length > 0) {
            new Chart(this.$refs.breakdownCanvas, {
                type: 'bar',
                data: {
                    labels: breakdownLabels,
                    datasets: [{
                        label: 'Présents',
                        data: breakdownCounts,
                        backgroundColor: '#C77700',
                    }],
                },
                options: {
                    scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } },
                    plugins: { legend: { display: false } },
                },
            });
        }
    },
}));
```

- [ ] **Step 3: Add the chart containers to the dashboard view**

In `resources/views/admin/dashboard.blade.php`, find this exact block (written in Task 1, Step 5):

```blade
        @if ($recentSessions->isEmpty())
            <div class="rounded-2xl bg-white p-6 text-center shadow-[0_2px_10px_rgba(20,30,50,.06)] md:p-8">
                <p class="text-sm text-muted">Pas encore de séance. Créez votre première séance depuis « Séances ».</p>
            </div>
        @else
            <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)] md:p-8">
                <h2 class="font-display text-lg font-extrabold text-navy">Activité récente</h2>
                <ul class="mt-4 divide-y divide-divider">
                    @foreach ($recentSessions as $session)
                        <li>
                            <a href="{{ route('admin.sessions.show', $session) }}"
                                class="flex cursor-pointer items-center justify-between gap-3 rounded-lg py-3 pl-2 pr-2 hover:bg-cream">
                                <span class="min-w-0 truncate text-sm font-semibold text-navy">
                                    {{ $session->title }} — {{ $session->date->translatedFormat('d F Y') }}
                                </span>
                                <span class="shrink-0 rounded-full bg-success-bg px-2 py-0.5 text-[11px] font-semibold uppercase text-success">
                                    {{ $session->present_count }}/{{ $session->attendances_count }}
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
```

and replace it entirely with this version, which adds the two chart cards above the same "Activité récente" list when sessions exist:

```blade
        @if ($recentSessions->isEmpty())
            <div class="rounded-2xl bg-white p-6 text-center shadow-[0_2px_10px_rgba(20,30,50,.06)] md:p-8">
                <p class="text-sm text-muted">Pas encore de séance. Créez votre première séance depuis « Séances ».</p>
            </div>
        @else
            <div
                x-data="dashboardCharts(
                    @js($attendanceTrend->map(fn ($s) => $s->date->translatedFormat('d/m'))),
                    @js($attendanceTrend->map(fn ($s) => $s->attendances_count > 0 ? round($s->present_count / $s->attendances_count * 100) : 0)),
                    @js($lastSessionBreakdown->keys()),
                    @js($lastSessionBreakdown->values())
                )"
                class="grid grid-cols-1 gap-4 lg:grid-cols-2"
            >
                <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)]">
                    <h2 class="font-display text-sm font-extrabold uppercase text-muted-strong">Évolution du taux de présence</h2>
                    <canvas x-ref="trendCanvas" class="mt-4"></canvas>
                </div>
                <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)]">
                    <h2 class="font-display text-sm font-extrabold uppercase text-muted-strong">Répartition (dernière séance)</h2>
                    <canvas x-ref="breakdownCanvas" class="mt-4"></canvas>
                </div>
            </div>

            <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)] md:p-8">
                <h2 class="font-display text-lg font-extrabold text-navy">Activité récente</h2>
                <ul class="mt-4 divide-y divide-divider">
                    @foreach ($recentSessions as $session)
                        <li>
                            <a href="{{ route('admin.sessions.show', $session) }}"
                                class="flex cursor-pointer items-center justify-between gap-3 rounded-lg py-3 pl-2 pr-2 hover:bg-cream">
                                <span class="min-w-0 truncate text-sm font-semibold text-navy">
                                    {{ $session->title }} — {{ $session->date->translatedFormat('d F Y') }}
                                </span>
                                <span class="shrink-0 rounded-full bg-success-bg px-2 py-0.5 text-[11px] font-semibold uppercase text-success">
                                    {{ $session->present_count }}/{{ $session->attendances_count }}
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
```

- [ ] **Step 4: Build the frontend assets**

Run: `npm run build`
Expected: build succeeds with no errors, `chart.js` gets bundled into the output.

- [ ] **Step 5: Run the full test suite**

Run: `php artisan test --compact`
Expected: all tests pass — `DashboardTest` (Task 1) still only asserts server-rendered text, unaffected by the client-side chart wiring.

- [ ] **Step 6: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 7: Manually verify in the browser**

With the dev server running, log in as an admin with at least one seeded session and confirm both charts render (line chart for the trend, bar chart for the last session's breakdown) and the empty-state message shows for a tenant with zero sessions. This step has no automated test — Chart.js canvas rendering isn't exercised by Pest.

- [ ] **Step 8: Commit**

```bash
git add package.json package-lock.json resources/js/app.js resources/views/admin/dashboard.blade.php
git commit -m "feat: add Chart.js trend and breakdown charts to the admin dashboard"
```
