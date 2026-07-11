# Attendance form logo repositioning + global page loader — spec

Date: 2026-07-11

## Context

`resources/views/attendance/show.blade.php` currently renders the club logo
(`public/assets/rotary-nexus-logo.png`) as a standalone element floating above
the white card, separate from the dark navy (`bg-[#12213D]`) banner that holds
the club's identity text ("RC Cotonou Nexus" / "District 9103"). The logo
should live inside that banner instead, alongside the text it represents.

Separately, there is no loading/spinner component anywhere in the project.
The app is plain Blade + Alpine.js (no Livewire, no SPA/AJAX navigation) — all
form submissions and link clicks are full HTTP page loads. The user wants a
reusable loader built from the club logo, shown globally on every page
navigation (link clicks and form submissions) across both the public
attendance flow and the admin area.

## Goals

1. Move the logo into the navy banner on the public attendance page, centered
   above the "RC Cotonou Nexus" / "District 9103" text.
2. Add a reusable `<x-loader>` Blade component: the logo image spinning via
   Tailwind's `animate-spin` utility, sized via passed-through classes.
3. Add a global full-page loading overlay, built from `<x-loader>`, shown
   automatically whenever the user clicks an internal link or submits a form
   — anywhere in the app (public attendance pages and admin area). No
   per-button loading state is added separately; the overlay is the single
   mechanism, and it naturally covers the attendance form's "Envoyer" button
   since submitting it is a normal HTTP navigation.

Out of scope: any AJAX/SPA-style navigation, per-button spinners, changes to
admin business logic, new dependencies (everything uses Tailwind + Alpine,
already present).

## Design

### 1. Logo placement (`attendance/show.blade.php`)

Remove the standalone `<img>` above the card (current line 3). Inside the
navy banner div, add the logo above the existing text lines, centered:

```blade
<div class="bg-[#12213D] px-6 pb-[18px] pt-[22px] flex flex-col items-center text-center">
    <img src="{{ asset('assets/rotary-nexus-logo.png') }}" alt="RC Cotonou Nexus" class="h-12 w-12 object-contain">
    <p class="mt-2 font-display text-lg font-extrabold text-white">RC Cotonou Nexus</p>
    <p class="mt-2 text-[10px] font-semibold uppercase tracking-wide text-[#F2B94D]">District 9103</p>
    <p class="font-display text-[15px] font-bold text-white">RC Cotonou Nexus</p>
</div>
```

The banner switches from left-aligned to centered (`items-center text-center`)
to match the "logo centered above text" layout the user approved. The outer
page wrapper (`show.blade.php` line 2) no longer needs `gap-6`/gives up the
now-removed standalone logo, so the flex wrapper collapses to just the card.

### 2. `<x-loader>` component

New file `resources/views/components/loader.blade.php`:

```blade
@props([])

<img
    src="{{ asset('assets/rotary-nexus-logo.png') }}"
    alt="Chargement…"
    {{ $attributes->merge(['class' => 'h-8 w-8 object-contain animate-spin']) }}
>
```

Callers override size via `class`, e.g. `<x-loader class="h-16 w-16 object-contain animate-spin" />`
for the full-page overlay use below. `object-contain` and `animate-spin` are
always present (merged defaults), so callers only need to pass sizing classes
when they want something other than `h-8 w-8`.

### 3. Global page-loading overlay

New file `resources/views/components/page-loading-overlay.blade.php`:

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

An Alpine store (`resources/js/app.js`) tracks the overlay's visibility and
wires up the global listeners:

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

Registered before `Alpine.start()`, alongside the existing `Alpine.data(...)`
calls in `app.js`. Because every navigation in this app is a real HTTP
request, the overlay is simply left showing once triggered — the browser
replaces the whole document when the new page arrives, so there is no explicit
"hide" path to write, and a fresh page load always starts with the store's
default `active: false`.

The overlay partial is included once in each base layout, right after
`<body>` opens:

- `resources/views/components/layouts/app.blade.php`
- `resources/views/components/layouts/admin.blade.php`

### 4. Files touched

- `resources/views/attendance/show.blade.php` (logo moved into banner)
- `resources/views/components/loader.blade.php` (new)
- `resources/views/components/page-loading-overlay.blade.php` (new)
- `resources/views/components/layouts/app.blade.php` (include overlay)
- `resources/views/components/layouts/admin.blade.php` (include overlay)
- `resources/js/app.js` (`pageLoading` store + click/submit listeners)

## Testing

- No backend/controller logic changes — no new Pest feature tests required.
- Existing feature tests asserting on `attendance/show.blade.php` markup (if
  any check for the logo's old position or exact banner structure) will be
  reviewed and adjusted if they break on the new structure.
- Manual verification (per this project's `verify`/`run` skills): load the
  public attendance form and confirm the logo sits in the navy banner;
  trigger a navigation (click a link, submit the attendance form) on both the
  public form and an admin page and confirm the spinning-logo overlay
  appears.
