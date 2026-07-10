# Admin Design Refresh Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Modernize the admin area's visual design (sidebar navigation, mobile-friendly layout, logo, "back to list" navigation, and an eye icon for session details) per the approved spec.

**Architecture:** Pure Blade/Tailwind/Alpine.js frontend refresh — no backend/controller/model changes. A new sidebar-based `layouts.admin` shell (with a mobile off-canvas drawer driven by a new `adminShell` Alpine component) replaces the current bare header layout. Semantic Tailwind v4 color tokens replace the hardcoded hex values used across the touched views. `sessions/index.blade.php`, `sessions/show.blade.php`, and `auth/login.blade.php` are restyled and made responsive within that shell.

**Tech Stack:** Laravel 13 Blade components, Tailwind CSS v4 (`@theme` tokens), Alpine.js 3 (already a dependency), inline SVG icons (no new npm package), Pest 4 feature tests.

## Global Constraints

- No new npm/composer dependencies (icons are inline SVG; spec explicitly rules out an icon package).
- No changes to `resources/views/attendance/**`, `components/layouts/app.blade.php` (beyond the login page's use of it), the PDF export template, or any controller/model/route.
- Every clickable element in the touched files gets an explicit `cursor-pointer` class.
- `public/assets/rotary-nexus-logo.png` must be committed to git as part of this work (it exists on disk but has never been tracked — verify with `git ls-files public/assets` before Task 3).
- Run `vendor/bin/pint --dirty --format agent` after PHP/Blade changes, before considering a task done.

---

### Task 1: Semantic color tokens

**Files:**
- Modify: `resources/css/app.css`

**Interfaces:**
- Produces: Tailwind utility classes consumed by every later task — `bg-navy`, `text-navy`, `hover:bg-navy-hover`, `bg-gold`/`text-gold`, `bg-cream`, `bg-success`/`text-success`/`bg-success-bg`, `text-muted`, `text-muted-strong`, `border-border`, `divide-divider`/`border-divider`, `bg-error`/`text-error`/`bg-error-bg`.
- Note: this task **consolidates** two near-duplicate grays found in `sessions/show.blade.php` into existing tokens — `#F2F0EA` (inner list divider) → `divider` (`#EDEAE2`), and `#A39C8C` (phone number text) → `muted-strong` (`#8A8474`). The visual difference is imperceptible; later tasks will replace those hex values with the consolidated tokens.

- [ ] **Step 1: Add the tokens**

Edit `resources/css/app.css` — insert the new custom properties inside the existing `@theme` block, after the `--font-display` line:

```css
@import 'tailwindcss';

@source '../../vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php';
@source '../../storage/framework/views/*.php';

@theme {
    --font-sans: 'Source Sans 3', ui-sans-serif, system-ui, sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji',
        'Segoe UI Symbol', 'Noto Color Emoji';
    --font-display: 'Libre Franklin', ui-sans-serif, system-ui, sans-serif;

    --color-navy: #12213D;
    --color-navy-hover: #1c3559;
    --color-gold: #C77700;
    --color-cream: #F5F3EE;
    --color-success: #0E7C66;
    --color-success-bg: #E7F5F1;
    --color-muted: #6B6558;
    --color-muted-strong: #8A8474;
    --color-border: #DEDAD0;
    --color-divider: #EDEAE2;
    --color-error: #B23B3B;
    --color-error-bg: #FBEAEA;
}

[x-cloak] {
    display: none !important;
}
```

- [ ] **Step 2: Build the frontend assets to confirm the tokens compile**

Run: `npm run build`
Expected: build completes with no errors, and `public/build/assets/*.css` contains `--color-navy` (or the compiled utility classes like `.bg-navy`). Verify with:

```bash
npm run build && grep -l "12213d" public/build/assets/*.css
```

Expected: a matching CSS file path is printed (case may be lowercased by the bundler).

- [ ] **Step 3: Commit**

```bash
git add resources/css/app.css
git commit -m "$(cat <<'EOF'
style: add semantic color tokens for the admin design refresh

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Admin layout shell — sidebar with mobile drawer

**Files:**
- Modify: `resources/views/components/layouts/admin.blade.php`
- Modify: `resources/js/app.js`
- Test: `tests/Feature/Admin/AdminLayoutTest.php` (new)

**Interfaces:**
- Consumes: color tokens from Task 1 (`bg-navy`, `text-navy`, `bg-cream`, `border-divider`, `text-gold`, `hover:bg-cream`).
- Produces: the `layouts.admin` component's `$slot` and `title` prop are unchanged (still consumed the same way by `sessions/index.blade.php`, `sessions/show.blade.php`). Adds a new Alpine component `adminShell()` (`sidebarOpen: boolean`, `toggle()`, `close()`) that later tasks do not need to call directly.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Admin/AdminLayoutTest.php`:

```php
<?php

use App\Models\MeetingSession;
use App\Models\User;

it('shows the sidebar navigation and logo to an authenticated admin', function () {
    MeetingSession::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.index'))
        ->assertOk()
        ->assertSee('rotary-nexus-logo.png', false)
        ->assertSee('aria-label="Ouvrir le menu"', false)
        ->assertSee('href="'.route('admin.sessions.index').'"', false);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter="shows the sidebar navigation and logo"`
Expected: FAIL — the current layout has no logo image, no hamburger button, and no `href` matching that exact route string (the current sidebar-less header has neither).

- [ ] **Step 3: Add the `adminShell` Alpine component**

Edit `resources/js/app.js`. Find the existing last two lines of the file:

```js
Alpine.start();
```

Replace them with:

```js
Alpine.data('adminShell', () => ({
    sidebarOpen: false,
    toggle() {
        this.sidebarOpen = !this.sidebarOpen;
    },
    close() {
        this.sidebarOpen = false;
    },
}));

Alpine.start();
```

(i.e. insert the new `Alpine.data('adminShell', ...)` block directly above the pre-existing `Alpine.start();` call — there must be exactly one `Alpine.start();` call in the file, at the very end.)

- [ ] **Step 4: Rewrite the admin layout with the sidebar shell**

Replace the full contents of `resources/views/components/layouts/admin.blade.php`:

```blade
@props(['title' => 'Administration — RC Cotonou Nexus'])
<!doctype html>
<html lang="fr" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-cream font-sans text-navy antialiased">
    <div x-data="adminShell()" class="flex min-h-full flex-col md:flex-row">
        <div class="flex items-center justify-between border-b border-divider bg-white px-4 py-3 md:hidden">
            <div class="flex items-center gap-2">
                <img src="{{ asset('assets/rotary-nexus-logo.png') }}" alt="RC Cotonou Nexus" class="h-8 w-8 object-contain">
                <span class="text-sm font-semibold text-navy">RC Cotonou Nexus</span>
            </div>
            <button type="button" @click="toggle()" aria-label="Ouvrir le menu"
                class="cursor-pointer rounded-lg p-2 text-navy hover:bg-cream">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </button>
        </div>

        <div x-show="sidebarOpen" x-cloak @click="close()" x-transition.opacity
            class="fixed inset-0 z-30 bg-black/40 md:hidden"></div>

        <aside
            :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
            class="fixed inset-y-0 left-0 z-40 flex w-60 flex-col border-r border-divider bg-white px-4 py-6 transition-transform duration-200 md:static md:translate-x-0"
        >
            <div class="hidden items-center gap-2 px-2 md:flex">
                <img src="{{ asset('assets/rotary-nexus-logo.png') }}" alt="RC Cotonou Nexus" class="h-10 w-10 object-contain">
                <span class="text-sm font-semibold text-navy">RC Cotonou Nexus</span>
            </div>

            <div class="flex items-center justify-between px-2 md:hidden">
                <span class="text-sm font-semibold text-navy">Menu</span>
                <button type="button" @click="close()" aria-label="Fermer le menu"
                    class="cursor-pointer rounded-lg p-1 text-muted hover:bg-cream">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <nav class="mt-6 flex flex-1 flex-col gap-1">
                <a href="{{ route('admin.sessions.index') }}" @click="close()"
                    class="cursor-pointer rounded-lg px-3 py-2 text-sm font-semibold {{ request()->routeIs('admin.sessions.*') ? 'bg-navy text-white' : 'text-navy hover:bg-cream' }}">
                    Séances
                </a>
            </nav>

            @auth
                <form method="POST" action="{{ route('admin.logout') }}">
                    @csrf
                    <button type="submit"
                        class="cursor-pointer w-full rounded-lg px-3 py-2 text-left text-sm font-semibold text-gold hover:bg-cream">
                        Se déconnecter
                    </button>
                </form>
            @endauth
        </aside>

        <main class="flex-1 px-4 py-6 md:px-8 md:py-10">
            <div class="mx-auto max-w-[1040px]">
                {{ $slot }}
            </div>
        </main>
    </div>
</body>
</html>
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --compact --filter="shows the sidebar navigation and logo"`
Expected: PASS

- [ ] **Step 6: Run the full existing admin test suite to check for regressions**

Run: `php artisan test --compact --filter=Admin`
Expected: all tests PASS (existing tests only assert on content that's still present — session titles, counts, "QR code", etc.)

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/css/app.css resources/js/app.js resources/views/components/layouts/admin.blade.php tests/Feature/Admin/AdminLayoutTest.php
git commit -m "$(cat <<'EOF'
feat: replace admin header with a sidebar shell and mobile drawer

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: Login page — logo and restyle

**Files:**
- Modify: `resources/views/admin/auth/login.blade.php`
- Add: `public/assets/rotary-nexus-logo.png` (currently untracked — must be committed here)
- Test: `tests/Feature/Admin/AuthTest.php:1-44` (append one test)

**Interfaces:**
- Consumes: color tokens from Task 1. This page uses `x-layouts.app` (the public layout), **not** `x-layouts.admin` — no sidebar here, since the user isn't authenticated yet.

- [ ] **Step 1: Confirm the logo file is trackable**

```bash
git ls-files public/assets
ls -la public/assets/rotary-nexus-logo.png
```

Expected: `ls` shows the file exists; `git ls-files` prints nothing (confirms it's currently untracked — it will be added in Step 4).

- [ ] **Step 2: Write the failing test**

Append to `tests/Feature/Admin/AuthTest.php` (after the last `it(...)` block):

```php
it('shows the club logo on the login page', function () {
    $this->get(route('admin.login'))
        ->assertOk()
        ->assertSee('rotary-nexus-logo.png', false);
});
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `php artisan test --compact --filter="shows the club logo"`
Expected: FAIL — no `<img>` referencing the logo exists yet on the login page.

- [ ] **Step 4: Rewrite the login page and track the logo asset**

Replace the full contents of `resources/views/admin/auth/login.blade.php`:

```blade
<x-layouts.app title="Connexion administrateur">
    <div class="mx-auto flex min-h-screen max-w-[380px] flex-col items-center justify-center gap-6 px-4">
        <img src="{{ asset('assets/rotary-nexus-logo.png') }}" alt="RC Cotonou Nexus" class="h-14 w-14 object-contain">
        <div class="w-full rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)]">
            <h1 class="font-display text-xl font-extrabold text-navy">Connexion administrateur</h1>

            @if ($errors->any())
                <div class="mt-4 rounded-lg bg-error-bg px-4 py-3 text-sm text-error">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('admin.login.store') }}" class="mt-4 flex flex-col gap-4">
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
    </div>
</x-layouts.app>
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --compact --filter="shows the club logo"`
Expected: PASS

- [ ] **Step 6: Run the full auth test suite to check for regressions**

Run: `php artisan test --compact --filter=AuthTest`
Expected: all tests PASS

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add public/assets/rotary-nexus-logo.png resources/views/admin/auth/login.blade.php tests/Feature/Admin/AuthTest.php
git commit -m "$(cat <<'EOF'
feat: show the club logo on the admin login page

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: Sessions list page — clickable rows, eye affordance, mobile form

**Files:**
- Modify: `resources/views/admin/sessions/index.blade.php`
- Test: `tests/Feature/Admin/MeetingSessionManagementTest.php:1-51` (append one test)

**Interfaces:**
- Consumes: color tokens from Task 1; `layouts.admin` shell from Task 2 (no prop/slot changes there).
- Produces: nothing new consumed by later tasks — this page is a leaf.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Admin/MeetingSessionManagementTest.php` (after the last `it(...)` block):

```php
it('shows a details affordance for each session row', function () {
    MeetingSession::factory()->create(['title' => 'Réunion hebdomadaire']);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.index'))
        ->assertOk()
        ->assertSee('Voir les détails');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter="shows a details affordance"`
Expected: FAIL — no "Voir les détails" text exists in the current row markup.

- [ ] **Step 3: Rewrite the sessions list page**

Replace the full contents of `resources/views/admin/sessions/index.blade.php`:

```blade
<x-layouts.admin title="Séances — Administration">
    <div class="rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)] md:p-8">
        <h1 class="font-display text-xl font-extrabold text-navy">Séances</h1>

        <form method="POST" action="{{ route('admin.sessions.store') }}" class="mt-4 flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end">
            @csrf
            <div class="flex flex-col gap-1.5">
                <label for="title" class="text-sm font-semibold">Titre</label>
                <input type="text" id="title" name="title" value="{{ old('title') }}" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="date" class="text-sm font-semibold">Date</label>
                <input type="date" id="date" name="date" value="{{ old('date') }}" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="time" class="text-sm font-semibold">Heure</label>
                <input type="time" id="time" name="time" value="{{ old('time') }}" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            </div>
            <button type="submit"
                class="cursor-pointer rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                Créer et activer
            </button>
        </form>

        @if ($errors->any())
            <div class="mt-4 rounded-lg bg-error-bg px-4 py-3 text-sm text-error">
                {{ $errors->first() }}
            </div>
        @endif

        <div
            x-data="sessionsList(@js($meetingSessions->map(fn ($meetingSession) => [
                'id' => $meetingSession->id,
                'title' => $meetingSession->title,
                'date' => $meetingSession->date->format('d/m/Y'),
                'url' => route('admin.sessions.show', $meetingSession),
                'isActive' => $meetingSession->is_active,
                'isOpen' => $meetingSession->is_open,
            ])))"
        >
            <input type="text" x-model="search" placeholder="Rechercher un titre…"
                class="mt-6 w-full max-w-[280px] rounded-full border border-border px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">

            <ul class="mt-4 divide-y divide-divider">
                <template x-for="session in filtered" :key="session.id">
                    <li>
                        <a :href="session.url"
                            class="flex cursor-pointer items-center justify-between gap-3 rounded-lg py-3 pl-2 pr-2 hover:bg-cream">
                            <span class="text-sm font-semibold text-navy">
                                <span x-text="session.title"></span> — <span x-text="session.date"></span>
                            </span>
                            <span class="flex items-center gap-2">
                                <span x-show="session.isActive" class="rounded-full bg-success-bg px-2 py-0.5 text-[11px] font-semibold uppercase text-success">Active</span>
                                <span :class="session.isOpen ? 'bg-success-bg text-success' : 'bg-divider text-muted'" class="rounded-full px-2 py-0.5 text-[11px] font-semibold uppercase" x-text="session.isOpen ? 'Ouverte' : 'Clôturée'"></span>
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-muted-strong" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                </svg>
                                <span class="sr-only">Voir les détails</span>
                            </span>
                        </a>
                    </li>
                </template>
            </ul>
        </div>
    </div>
</x-layouts.admin>
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact --filter="shows a details affordance"`
Expected: PASS

- [ ] **Step 5: Run the full sessions management test suite to check for regressions**

Run: `php artisan test --compact --filter=MeetingSessionManagementTest`
Expected: all tests PASS

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/admin/sessions/index.blade.php tests/Feature/Admin/MeetingSessionManagementTest.php
git commit -m "$(cat <<'EOF'
feat: make session rows fully clickable with a details icon

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: Session detail page — back link, responsive header, mobile roster

**Files:**
- Modify: `resources/views/admin/sessions/show.blade.php`
- Test: `tests/Feature/Admin/AttendanceDashboardTest.php:1-57` (append one test)

**Interfaces:**
- Consumes: color tokens from Task 1; `layouts.admin` shell from Task 2. Consumes the existing `qrCodePanel(url)` and `attendanceDashboard(records)` Alpine components from `resources/js/app.js` — **unchanged** signatures, only class names on the elements that use them change.
- Produces: nothing new consumed by later tasks — this page is a leaf.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Admin/AttendanceDashboardTest.php` (after the last `it(...)` block):

```php
it('shows a link back to the sessions list', function () {
    $meetingSession = MeetingSession::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.show', $meetingSession))
        ->assertOk()
        ->assertSee('Retour aux séances')
        ->assertSee('href="'.route('admin.sessions.index').'"', false);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter="shows a link back to the sessions list"`
Expected: FAIL — no "Retour aux séances" link exists yet on this page.

- [ ] **Step 3: Rewrite the session detail page**

Replace the full contents of `resources/views/admin/sessions/show.blade.php`:

```blade
<x-layouts.admin :title="$meetingSession->title . ' — Dashboard'">
    <div
        x-data="attendanceDashboard(@js($attendances->map(fn ($attendance) => [
            'id' => $attendance->id,
            'name' => $attendance->name,
            'title' => $attendance->title->value,
            'club' => $attendance->club,
            'phone' => $attendance->phone,
            'category' => $attendance->category->value,
            'categoryLabel' => $attendance->category->label(),
            'present' => $attendance->present,
            'isLate' => $attendance->is_late,
        ])))"
        class="rounded-2xl bg-white shadow-[0_2px_10px_rgba(20,30,50,.06)]"
    >
        <div class="border-b border-divider px-4 pb-5 pt-6 md:px-8 md:pt-7">
            <a href="{{ route('admin.sessions.index') }}"
                class="inline-flex cursor-pointer items-center gap-1 text-sm font-semibold text-muted hover:text-navy">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
                </svg>
                Retour aux séances
            </a>
            <p class="mt-3 text-[11px] font-semibold uppercase text-gold">RC Cotonou Nexus · District 9103</p>
            <div class="mt-1 flex flex-col gap-4 md:flex-row md:flex-wrap md:items-start md:justify-between">
                <div>
                    <h1 class="font-display text-2xl font-extrabold text-navy">{{ $meetingSession->title }}</h1>
                    <p class="text-[15px] text-muted">{{ $meetingSession->date->translatedFormat('d F Y') }}</p>
                </div>
                <div class="flex flex-col gap-3 md:flex-row md:items-center">
                    <div x-data="qrCodePanel(@js(route('attendance.show')))" class="relative">
                        <button type="button" @click="toggle()"
                            class="cursor-pointer w-full rounded-lg border border-border px-4 py-2 text-sm font-bold text-navy hover:bg-cream md:w-auto">
                            QR code
                        </button>
                        <div x-show="open" x-cloak @click.outside="open = false"
                            class="absolute right-0 top-full z-10 mt-2 w-72 rounded-xl border border-divider bg-white p-4 shadow-[0_2px_10px_rgba(20,30,50,.06)]">
                            <canvas x-ref="canvas" class="mx-auto"></canvas>
                            <p class="mt-3 break-all text-center text-xs text-muted-strong" x-text="url"></p>
                            <div class="mt-3 flex gap-2">
                                <button type="button" @click="share()"
                                    class="cursor-pointer flex-1 rounded-lg bg-navy px-3 py-2 text-xs font-bold text-white">
                                    <span x-show="!copied">Partager le lien</span>
                                    <span x-show="copied" x-cloak>Lien copié ✓</span>
                                </button>
                                <button type="button" @click="download()"
                                    class="cursor-pointer flex-1 rounded-lg border border-border px-3 py-2 text-xs font-bold text-navy">
                                    Télécharger
                                </button>
                            </div>
                        </div>
                    </div>
                    <a href="{{ route('admin.sessions.export-pdf', $meetingSession) }}"
                        class="cursor-pointer w-full rounded-lg bg-navy px-4 py-2 text-center text-sm font-bold text-white hover:bg-navy-hover md:w-auto">
                        Exporter en PDF
                    </a>
                    <span class="w-full rounded-full {{ $meetingSession->is_open ? 'bg-success-bg text-success' : 'bg-divider text-muted' }} px-3 py-1 text-center text-xs font-semibold md:w-auto">
                        ● {{ $meetingSession->is_open ? 'Séance ouverte' : 'Séance clôturée' }}
                    </span>
                    <form method="POST" action="{{ route('admin.sessions.toggle-open', $meetingSession) }}" class="w-full md:w-auto">
                        @csrf
                        <button type="submit" class="cursor-pointer w-full text-sm font-semibold text-navy underline md:w-auto">
                            {{ $meetingSession->is_open ? 'Clôturer la séance' : 'Rouvrir la séance' }}
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-3 px-4 py-5 md:grid-cols-5 md:px-8">
            <div class="rounded-lg bg-navy p-3 text-white">
                <p class="text-lg font-extrabold">{{ $attendances->where('present', true)->count() }}/{{ $attendances->count() }}</p>
                <p class="text-xs">Présents ({{ $attendances->count() > 0 ? round($attendances->where('present', true)->count() / $attendances->count() * 100) : 0 }}%)</p>
            </div>
            @foreach (\App\Enums\AttendanceCategory::cases() as $category)
                @php $categoryCount = $attendances->filter(fn ($attendance) => $attendance->category === $category)->count(); @endphp
                <div class="rounded-lg p-3" style="background-color: {{ $category->colors()['bg'] }}; color: {{ $category->colors()['accent'] }}">
                    <p class="text-lg font-extrabold">{{ $categoryCount }}</p>
                    <p class="text-xs">{{ $category->label() }}</p>
                </div>
            @endforeach
        </div>

        <div class="flex flex-wrap items-center gap-3 px-4 py-4 md:px-8">
            <input type="text" x-model="search" placeholder="Rechercher un nom…"
                class="w-full max-w-[280px] rounded-full border border-border px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
            <select x-model="activeTitle"
                class="cursor-pointer rounded-lg border border-border px-3 py-2 text-sm">
                <option value="all">Tous les titres</option>
                <template x-for="option in titleOptions" :key="option">
                    <option :value="option" x-text="option"></option>
                </template>
            </select>
            <button type="button" @click="activeCategory = 'all'"
                :class="activeCategory === 'all' ? 'bg-navy text-white' : 'border border-border text-navy'"
                class="cursor-pointer rounded-full px-3 py-2 text-xs font-semibold md:py-1.5">Tous</button>
            @foreach (\App\Enums\AttendanceCategory::cases() as $category)
                <button type="button" @click="activeCategory = '{{ $category->value }}'"
                    :class="activeCategory === '{{ $category->value }}' ? 'bg-navy text-white' : 'border border-border text-navy'"
                    class="cursor-pointer rounded-full px-3 py-2 text-xs font-semibold md:py-1.5">{{ $category->label() }}</button>
            @endforeach
        </div>

        <div class="max-h-[520px] overflow-y-auto px-4 pb-8 md:px-8">
            <template x-for="group in groups" :key="group.category">
                <div class="mb-5">
                    <p class="mb-2 text-xs font-semibold uppercase text-muted-strong" x-text="group.records[0].categoryLabel + ' (' + group.records.length + ')'"></p>
                    <template x-for="record in group.records" :key="record.id">
                        <div class="flex flex-col gap-2 border-b border-divider py-2.5 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex items-center gap-3">
                                <div class="flex h-[34px] w-[34px] shrink-0 items-center justify-center rounded-full bg-divider text-xs font-bold" x-text="initials(record.name)"></div>
                                <div>
                                    <p class="text-[14.5px] font-semibold text-navy" x-text="record.name"></p>
                                    <p class="text-[12.5px] text-muted-strong">
                                        <span x-text="record.title + ' · ' + record.club"></span>
                                        <span x-show="record.isLate" class="font-bold text-gold"> · marqué en retard</span>
                                    </p>
                                    <p class="mt-0.5 font-mono text-xs text-muted-strong sm:hidden" x-text="record.phone"></p>
                                </div>
                            </div>
                            <div class="flex items-center justify-between gap-3 sm:justify-end">
                                <span class="hidden font-mono text-sm text-muted-strong sm:inline" x-text="record.phone"></span>
                                <form method="POST" :action="'/admin/attendances/' + record.id + '/toggle-present'">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit"
                                        :class="record.present ? 'bg-success-bg text-success' : 'border border-border text-muted'"
                                        class="cursor-pointer rounded-lg px-3 py-1.5 text-xs font-semibold">
                                        <span x-text="record.present ? 'Présent' : 'Marquer présent'"></span>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </template>
                </div>
            </template>
        </div>
    </div>
</x-layouts.admin>
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact --filter="shows a link back to the sessions list"`
Expected: PASS

- [ ] **Step 5: Run the full attendance dashboard test suite to check for regressions**

Run: `php artisan test --compact --filter=AttendanceDashboardTest`
Expected: all tests PASS

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/admin/sessions/show.blade.php tests/Feature/Admin/AttendanceDashboardTest.php
git commit -m "$(cat <<'EOF'
feat: add a back-to-list link and make the session dashboard mobile-friendly

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: Full-suite verification

**Files:** none (verification only)

- [ ] **Step 1: Run the entire Pest suite**

Run: `php artisan test --compact`
Expected: all tests pass (no regressions across the whole app, not just the Admin folder).

- [ ] **Step 2: Run Pint across the whole diff**

Run: `vendor/bin/pint --dirty --format agent`
Expected: no further changes reported (all previous task commits already ran this; this is a final safety net in case anything was missed).

- [ ] **Step 3: Rebuild frontend assets**

Run: `npm run build`
Expected: build succeeds with no errors or warnings about missing files (confirms the logo path and new Tailwind utility classes all resolve).

- [ ] **Step 4: Manual mobile/desktop check**

Using this project's `run` skill (or `composer run dev` / `php artisan serve` + `npm run dev` manually), open in a browser at both a desktop width and a 375px-wide mobile viewport:
- `/admin/login` — logo visible, form usable.
- `/admin/sessions` — sidebar/hamburger drawer works, logo visible, session rows are fully clickable with the eye icon and `cursor-pointer`, create-session form stacks on mobile.
- `/admin/sessions/{id}` — "Retour aux séances" link navigates back to the list, header actions stack vertically on mobile and sit in a row on desktop, attendee rows reflow (phone under name) on narrow screens, all buttons show `cursor-pointer` on hover.

Expected: no visual breakage, no console errors (check with `browser-logs` if using Laravel Boost's MCP tools).

- [ ] **Step 5: Confirm nothing left uncommitted**

Run: `git status --porcelain`
Expected: empty output (everything from Tasks 1–5 was already committed; this task itself has no file changes to commit).
