# Attendance Logo Repositioning + Global Page Loader Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the club logo into the navy identity banner on the public attendance form, and add a reusable spinning-logo loader shown globally on every link click / form submit across the app.

**Architecture:** Pure Blade + Tailwind + Alpine.js changes, no backend logic. A new `<x-loader>` component wraps the existing logo image with Tailwind's `animate-spin`. A new `<x-page-loading-overlay>` partial renders a fixed full-screen overlay controlled by a new Alpine store (`pageLoading`), toggled on by global `click`/`submit` listeners registered in `resources/js/app.js`. The overlay is included once in each of the two base layouts (`layouts.app`, `layouts.admin`), so it's active on every page.

**Tech Stack:** Laravel 13 Blade components, Tailwind CSS v4 (`animate-spin` utility), Alpine.js (already a dependency, no new packages).

## Global Constraints

- No new dependencies — everything uses Tailwind + Alpine, already installed.
- Asset path stays `public/assets/rotary-nexus-logo.png` referenced via `asset()`, matching existing usage elsewhere in the codebase.
- Run `vendor/bin/pint --dirty --format agent` after any PHP/Blade file changes.
- No AJAX/SPA navigation is introduced — the overlay relies on real full-page HTTP navigations and is never explicitly hidden by JS.

---

### Task 1: Move the logo into the navy banner on the attendance form

**Files:**
- Modify: `resources/views/attendance/show.blade.php:2-9`
- Test: `tests/Feature/AttendanceFormTest.php` (existing test at line 72, no changes expected, run to confirm)

**Interfaces:**
- Consumes: nothing new.
- Produces: nothing consumed by later tasks — this task is self-contained.

- [ ] **Step 1: Run the existing logo test to confirm current baseline passes**

Run: `php artisan test --compact --filter="shows the club logo on the attendance form page"`
Expected: PASS (1 passed)

- [ ] **Step 2: Edit `resources/views/attendance/show.blade.php`**

Replace lines 2-9:

```blade
    <div class="mx-auto flex min-h-screen max-w-[420px] flex-col items-center justify-center gap-6 px-4 py-10">
        <img src="{{ asset('assets/rotary-nexus-logo.png') }}" alt="RC Cotonou Nexus" class="h-14 w-14 object-contain">
        <div class="w-full overflow-hidden rounded-xl bg-white shadow-[0_2px_10px_rgba(20,30,50,.06)]">
            <div class="bg-[#12213D] px-6 pb-[18px] pt-[22px]">
                <p class="font-display text-lg font-extrabold text-white">RC Cotonou Nexus</p>
                <p class="mt-2 text-[10px] font-semibold uppercase tracking-wide text-[#F2B94D]">District 9103</p>
                <p class="font-display text-[15px] font-bold text-white">RC Cotonou Nexus</p>
            </div>
```

with:

```blade
    <div class="mx-auto flex min-h-screen max-w-[420px] flex-col items-center justify-center px-4 py-10">
        <div class="w-full overflow-hidden rounded-xl bg-white shadow-[0_2px_10px_rgba(20,30,50,.06)]">
            <div class="flex flex-col items-center bg-[#12213D] px-6 pb-[18px] pt-[22px] text-center">
                <img src="{{ asset('assets/rotary-nexus-logo.png') }}" alt="RC Cotonou Nexus" class="h-12 w-12 object-contain">
                <p class="mt-2 font-display text-lg font-extrabold text-white">RC Cotonou Nexus</p>
                <p class="mt-2 text-[10px] font-semibold uppercase tracking-wide text-[#F2B94D]">District 9103</p>
                <p class="font-display text-[15px] font-bold text-white">RC Cotonou Nexus</p>
            </div>
```

(The outer wrapper drops `gap-6` since it now has a single child; the closing `</div>` tags later in the file are unchanged.)

- [ ] **Step 3: Run the logo test again to confirm it still passes**

Run: `php artisan test --compact --filter="shows the club logo on the attendance form page"`
Expected: PASS (1 passed)

- [ ] **Step 4: Run the full attendance test suite to check for regressions**

Run: `php artisan test --compact --filter=AttendanceFormTest`
Expected: all tests PASS

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/attendance/show.blade.php
git commit -m "fix: move club logo into the identity banner on the attendance form"
```

---

### Task 2: Add the reusable `<x-loader>` component

**Files:**
- Create: `resources/views/components/loader.blade.php`
- Test: `tests/Feature/LoaderComponentTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `<x-loader />` Blade component, usable with an optional `class` attribute to override sizing (default `h-8 w-8`). Always renders `animate-spin` and `object-contain` regardless of caller-supplied classes. Used by Task 3.

- [ ] **Step 1: Write the failing test**

```bash
php artisan make:test --pest LoaderComponentTest
```

Replace the generated file's contents with:

```php
<?php

it('renders the spinning logo with default sizing', function () {
    $html = (string) $this->blade('<x-loader />');

    expect($html)
        ->toContain('rotary-nexus-logo.png')
        ->toContain('animate-spin')
        ->toContain('h-8 w-8');
});

it('lets callers override the sizing classes', function () {
    $html = (string) $this->blade('<x-loader class="h-16 w-16" />');

    expect($html)
        ->toContain('animate-spin')
        ->toContain('h-16 w-16')
        ->not->toContain('h-8 w-8');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=LoaderComponentTest`
Expected: FAIL (component `loader` not found / view not found)

- [ ] **Step 3: Create `resources/views/components/loader.blade.php`**

```blade
@props([])

<img
    src="{{ asset('assets/rotary-nexus-logo.png') }}"
    alt="Chargement…"
    {{ $attributes->merge(['class' => 'h-8 w-8 object-contain animate-spin']) }}
>
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact --filter=LoaderComponentTest`
Expected: PASS (2 passed)

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/components/loader.blade.php tests/Feature/LoaderComponentTest.php
git commit -m "feat: add reusable spinning-logo loader component"
```

---

### Task 3: Add the global page-loading overlay

**Files:**
- Create: `resources/views/components/page-loading-overlay.blade.php`
- Modify: `resources/js/app.js` (add `pageLoading` store + global listeners, before `Alpine.start()`)
- Modify: `resources/views/components/layouts/app.blade.php`
- Modify: `resources/views/components/layouts/admin.blade.php`
- Test: `tests/Feature/PageLoadingOverlayTest.php`

**Interfaces:**
- Consumes: `<x-loader>` from Task 2.
- Produces: `<x-page-loading-overlay />` Blade component; `Alpine.store('pageLoading')` with boolean property `active`. Nothing later depends on this task.

- [ ] **Step 1: Write the failing test for the overlay markup**

```bash
php artisan make:test --pest PageLoadingOverlayTest
```

Replace the generated file's contents with:

```php
<?php

it('includes the page loading overlay on the public attendance layout', function () {
    $this->get(route('attendance.show'))
        ->assertOk()
        ->assertSee('pageLoading', false)
        ->assertSee('rotary-nexus-logo.png', false);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=PageLoadingOverlayTest`
Expected: FAIL (assertion for `pageLoading` not found in response body)

- [ ] **Step 3: Create `resources/views/components/page-loading-overlay.blade.php`**

```blade
<div
    x-data
    x-show="$store.pageLoading.active"
    x-cloak
    x-transition.opacity
    class="fixed inset-0 z-50 flex items-center justify-center bg-[#12213D]/40"
>
    <x-loader class="h-16 w-16 object-contain animate-spin" />
</div>
```

- [ ] **Step 4: Add the `pageLoading` store and global listeners to `resources/js/app.js`**

Insert this block after the existing `Alpine.data('adminShell', ...)` block (which currently ends right before `Alpine.start();`), so the new code sits directly above `Alpine.start();`:

```js
Alpine.store('pageLoading', {
    active: false,
});

document.addEventListener('click', (event) => {
    const link = event.target.closest('a[href]');

    if (!link) return;
    if (link.target === '_blank' || link.hasAttribute('download')) return;
    if (link.origin !== window.location.origin) return;
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

    Alpine.store('pageLoading').active = true;
});

document.addEventListener('submit', () => {
    Alpine.store('pageLoading').active = true;
});
```

- [ ] **Step 5: Include the overlay in `resources/views/components/layouts/app.blade.php`**

Change:

```blade
<body class="h-full bg-[#F5F3EE] font-sans text-[#12213D] antialiased">
    {{ $slot }}
</body>
```

to:

```blade
<body class="h-full bg-[#F5F3EE] font-sans text-[#12213D] antialiased">
    <x-page-loading-overlay />
    {{ $slot }}
</body>
```

- [ ] **Step 6: Include the overlay in `resources/views/components/layouts/admin.blade.php`**

Add `<x-page-loading-overlay />` as the first line inside `<body ...>`, immediately before the existing `<div x-data="adminShell()" ...>` wrapper.

- [ ] **Step 7: Run the test to verify it passes**

Run: `php artisan test --compact --filter=PageLoadingOverlayTest`
Expected: PASS (1 passed)

- [ ] **Step 8: Run the full test suite to check for regressions**

Run: `php artisan test --compact`
Expected: all tests PASS

- [ ] **Step 9: Build frontend assets**

Run: `npm run build`
Expected: build succeeds with no errors

- [ ] **Step 10: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/components/page-loading-overlay.blade.php \
    resources/js/app.js \
    resources/views/components/layouts/app.blade.php \
    resources/views/components/layouts/admin.blade.php \
    tests/Feature/PageLoadingOverlayTest.php
git commit -m "feat: add global spinning-logo overlay on page navigation"
```

---

### Task 4: Manual verification

**Files:** none (verification only)

- [ ] **Step 1: Start the dev server**

Run: `composer run dev` (or ask the user to run it if a dev server is already managed separately)

- [ ] **Step 2: Verify the attendance form logo placement**

Open the public attendance URL in a browser. Confirm the logo now appears centered inside the navy banner, directly above "RC Cotonou Nexus" / "District 9103", not floating above the card.

- [ ] **Step 3: Verify the overlay on the public form**

Fill in and submit the attendance form. Confirm the spinning-logo overlay appears immediately on submit (visible during the network round-trip before the confirmation page renders).

- [ ] **Step 4: Verify the overlay in the admin area**

Log into `/admin`, click a nav link (e.g. into a session's detail page) and confirm the overlay appears during navigation there too.

- [ ] **Step 5: Report results to the user**

Summarize what was verified and flag anything unexpected (e.g. overlay not clearing, flash of unstyled content) for follow-up.
