# Admin panel dashboard & visual redesign — spec

Date: 2026-08-01

## Context

The admin panel currently has no landing/overview page: after login, an org
admin lands directly on `admin.sessions.index` (`Séances`), a plain
CRUD list of meeting sessions with no aggregate stats. The whole panel
(`components/layouts/admin.blade.php` sidebar, cards throughout) uses
plain text links with no icons, and no charting/data-visualization exists
anywhere in the app. The user's own assessment: it feels generic, lacks
icons, doesn't read as a "modern dashboard."

No icon library or charting library is installed today. `package.json`
has only `alpinejs` and `qrcode` as runtime JS dependencies; there is no
Composer package for icons either.

Two sibling projects (`vote-carrefoot`, `pronostic-carrefoot`) were
considered as design references but ruled out — both just use an
unmodified third-party Bootstrap admin theme ("Hyper" by Coderthemes),
not a design system worth porting into this Tailwind-based app.

## Goal

1. A new `Tableau de bord` page becomes the landing page after admin
   login (replacing the current redirect to `Séances`), showing 4 KPI
   stat cards, 2 charts, and a recent-activity list — giving an
   at-a-glance overview of club attendance health.
2. The rest of the admin panel (sidebar nav, buttons, cards) gets a
   visual refresh: icons throughout, softer shadows, more rounded
   cards — without changing the navy/cream/gold palette or any existing
   page's functionality/structure beyond that styling.

## Non-goals

- No changes to the navy/cream/gold color identity.
- No new features on existing pages (Membres, Titres, Organisations,
  etc.) beyond icons/card styling.
- No date-range picker or historical drill-down on the dashboard — the
  charts show a fixed "last 10 sessions" window.
- No dashboard equivalent for the super-admin panel (`components/layouts/super-admin.blade.php`)
  — out of scope for this spec.

## Design

### 1. Dependencies

- **Icons**: Font Awesome Free 6 (solid set), loaded via the free
  cdnjs CDN — a single
  `<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">`
  in `components/layouts/admin.blade.php`'s `<head>`. No account/kit ID
  needed, no Composer or npm package.
- **Charts**: `chart.js` via npm, imported in `resources/js/app.js` and
  bundled through the existing Vite pipeline (same pattern as
  `alpinejs`).

### 2. New landing route: `admin.dashboard`

New `App\Http\Controllers\Admin\DashboardController@index`, registered
in `routes/web.php` inside the existing protected group
(`Route::middleware(['auth:web,super_admin', 'auth.session.guard:web'])`),
as the **first** route in that group:

```php
Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
```

This becomes the new post-login destination. Three places currently
redirect to `admin.sessions.index` and must change to `admin.dashboard`:

- `bootstrap/app.php`'s `redirectUsersTo` closure (tenant branch).
- `Admin\AuthController::store()`.
- `SuperAdmin\ImpersonationController::start()`.

`admin.sessions.index` itself is unchanged — still reachable from the
sidebar, still the CRUD page it is today.

### 3. Dashboard data (`DashboardController@index`)

All aggregation happens in the controller (no new service class needed —
this is a single, cohesive read-only query set, not shared logic).

```php
public function index(): View
{
    $recentSessions = MeetingSession::query()
        ->latest('date')
        ->take(10)
        ->withCount(['attendances', 'attendances as present_count' => fn ($q) => $q->where('present', true)])
        ->get();

    $attendanceRates = $recentSessions
        ->map(fn ($session) => $session->attendances_count > 0
            ? round($session->present_count / $session->attendances_count * 100)
            : null)
        ->filter(fn ($rate) => $rate !== null);

    $lastSession = $recentSessions->first();

    return view('admin.dashboard', [
        'activeMembersCount' => Member::count(),
        'totalSessionsCount' => MeetingSession::count(),
        'averageAttendanceRate' => $attendanceRates->isNotEmpty() ? round($attendanceRates->average()) : null,
        'lastSession' => $lastSession,
        'attendanceTrend' => $recentSessions->reverse()->values(), // oldest → newest, for the line chart
        'lastSessionBreakdown' => $lastSession
            ? $lastSession->attendances()->where('present', true)->get()->groupBy(fn ($a) => $a->groupLabel)->map->count()
            : collect(),
        'recentSessions' => $recentSessions->take(5),
    ]);
}
```

Notes:
- `Member::count()` is the total member roster size — there's no
  "active" flag on `Member` itself (only `Title`/`Position` have
  `is_active`), so "Membres actifs" in the UI just means "total
  members," matching what the model actually tracks.
- "Taux de présence moyen" averages the same last-10-sessions window as
  the trend chart, not all-time, so the two numbers stay consistent
  with each other.
- Empty state: a brand-new tenant with zero sessions gets
  `averageAttendanceRate: null`, empty `attendanceTrend`/`recentSessions`
  collections. The view must render a "Pas encore de séance" placeholder
  instead of an empty/broken chart — Chart.js is only initialized when
  `attendanceTrend` is non-empty.

### 4. Dashboard view (`resources/views/admin/dashboard.blade.php`)

- **KPI row**: 4 cards, each with a Font Awesome icon, a large number,
  and a label — `Membres actifs`, `Séances organisées`, `Taux de
  présence moyen` (as `XX %` or `—` if null), `Dernière séance` (date +
  `present_count/attendances_count`, or "Aucune séance" if null).
- **Charts row**: 2 cards side by side (stacked on mobile).
  - Line chart: attendance rate % per session, x-axis = session dates,
    from `attendanceTrend`.
  - Bar chart: `lastSessionBreakdown` (organization label → present
    count) for the most recent session only.
  - Chart.js instances are created in an Alpine `x-data` component
    (matching the existing `attendanceDashboard`-style pattern already
    used in `admin/sessions/show.blade.php`), fed the controller's data
    via `@json(...)` into `x-init`.
- **Recent activity**: a simple list (not a table) of the 5 most recent
  sessions — title, date, present/total badge, link to
  `admin.sessions.show` — reusing the badge styling already present in
  `admin/sessions/index.blade.php`.

### 5. Sidebar & global visual refresh

`components/layouts/admin.blade.php`:

- Add `Tableau de bord` as the first nav item, linking to
  `route('admin.dashboard')`, active via
  `request()->routeIs('admin.dashboard')`.
- Add a Font Awesome icon before every nav item's label (e.g.
  `fa-gauge` dashboard, `fa-calendar-days` séances, `fa-user-shield`
  administrateurs, `fa-palette` identité du club, `fa-envelope` mail,
  `fa-list-check` formulaire, `fa-users` membres, `fa-sitemap`
  organisations, `fa-id-badge` titres/qualités), plus `fa-key` on "Mon
  mot de passe" and `fa-right-from-bracket` on "Se déconnecter".
- Card style: increase border-radius (`rounded-lg` → `rounded-xl`),
  soften shadows (add `shadow-sm` where cards currently have none),
  consistently across `admin/**` views that use the
  `bg-white p-6 shadow-[...]` card pattern already established (e.g.
  `admin/password/edit.blade.php`).
- Buttons for create/edit/delete/export actions get a matching Font
  Awesome icon before their label, no new button variants.

### 6. Testing

- `tests/Feature/Admin/DashboardTest.php` (new): guest redirect,
  authenticated access renders KPI numbers correctly against seeded
  `MeetingSession`/`Attendance`/`Member` data, empty-state rendering
  with zero sessions, and that `admin.dashboard` is the actual
  post-login redirect target (extends the existing login-redirect
  assertions in `AuthTest.php`).
- Update existing tests currently asserting a post-login/impersonation
  redirect to `admin.sessions.index` (`AuthTest.php`, the password
  cross-session test in `PasswordChangeTest.php`,
  `TenantProvisioningLoginTest.php`, and the impersonation flow test)
  to expect `admin.dashboard` instead.
- No JS/Chart.js unit tests — Chart.js rendering is not exercised by
  Pest; the controller's data-shaping logic is what's tested.
