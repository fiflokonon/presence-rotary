# Admin design refresh — spec

Date: 2026-07-10

## Context

The admin area (`resources/views/admin/**`, `resources/views/components/layouts/admin.blade.php`)
currently consists of:

- `admin.blade.php` — a bare layout: a one-line header (club name + logout button) and a
  centered `max-w-[1040px]` content column. No navigation, no logo.
- `sessions/index.blade.php` — session creation form + a live-filterable list of sessions
  (Alpine `sessionsList`), each row a plain `<a>` linking to the session's show page.
- `sessions/show.blade.php` — the session dashboard: header with actions (QR code popover,
  export PDF, open/close toggle, status badge), stat tiles, filters, and the attendee list
  (Alpine `attendanceDashboard`).
- `auth/login.blade.php` — login form, rendered through the public `layouts.app` (no admin
  chrome, correctly so since the user isn't authenticated yet).

Styling is plain Tailwind v4 utility classes with colors hardcoded as inline hex values
(`#12213D` navy, `#C77700` gold, `#F5F3EE` cream, `#0E7C66` success green, `#6B6558` /
`#8A8474` muted text, `#DEDAD0` / `#EDEAE2` borders, `#FBEAEA` / `#B23B3B` error). No icon
library or component system is in place; Alpine.js (already a dependency) drives all
interactivity. `public/assets/rotary-nexus-logo.png` exists but is not referenced anywhere
in the views yet.

## Goals

1. Modernize the visual design of the admin area (a "marked" refresh, not just a polish
   pass — per user's explicit choice).
2. Add `cursor-pointer` consistently to every clickable element.
3. Let the admin navigate back to the sessions list from a session's detail page.
4. Show the Rotary Nexus logo (`public/assets/rotary-nexus-logo.png`) on admin pages
   (including login).
5. Add an eye icon on the sessions list as an explicit "view details" affordance.
6. Make the admin area mobile-friendly.

Out of scope: the public attendance form (`resources/views/attendance/**`,
`components/layouts/app.blade.php` beyond adding the logo to the login page), the PDF
export template, backend/controller logic, and any new dependency (icons are inline SVG,
no icon package is added).

## Design

### 1. Color tokens

Add semantic color tokens to the `@theme` block in `resources/css/app.css`, mapped to the
existing palette (no color value changes, just naming):

```css
@theme {
    /* existing font tokens unchanged */
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
```

These become available as Tailwind utilities (`bg-navy`, `text-muted`, `border-border`,
etc. per Tailwind v4's automatic utility generation from `@theme` color tokens). All admin
views being touched in this pass are migrated from inline hex arbitrary values to these
utilities. Views outside scope (public attendance form, PDF template) are left as-is —
no risk of regression there, and they can migrate later opportunistically.

### 2. Admin layout shell (`components/layouts/admin.blade.php`)

Replaces the current single-line header with a sidebar shell:

- **Structure**: a flex row — fixed-width left sidebar (`w-60`) + main content area
  (`flex-1`, own scroll). Content area keeps a max-width inner wrapper
  (`max-w-[1040px]`) for readability on wide screens, same as today.
- **Sidebar contents** (top to bottom): logo (`rotary-nexus-logo.png`, ~40px height) next
  to "RC Cotonou Nexus" wordmark; a nav section with a single "Séances" link
  (`admin.sessions.index`), highlighted (bold + accent left border or filled background)
  when the current route is `admin.sessions.*`; a spacer; a "Se déconnecter" button
  pinned to the bottom of the sidebar, styled as a muted text button (as today).
- **Mobile (`< md`)**: sidebar is hidden by default and rendered as an off-canvas drawer.
  A slim mobile topbar (logo + hamburger button) replaces the sidebar's visual space.
  Tapping the hamburger slides the drawer in from the left with a dimmed backdrop;
  tapping the backdrop or a nav link closes it. This is driven by a new Alpine component,
  `Alpine.data('adminShell', ...)` in `resources/js/app.js`, holding `sidebarOpen: boolean`
  and `close()`/`toggle()` methods — `x-data="adminShell()"` on the shell root,
  `x-show`/`x-transition` on the drawer and backdrop, `x-cloak` to avoid flash on load.
- **Active route detection**: use `request()->routeIs('admin.sessions.*')` directly in the
  Blade template (simple server-side conditional class) rather than pushing routing state
  into Alpine — no need for JS to know about Laravel routes.
- No changes to the `guest`/`auth` behavior — the logout form/button logic stays as-is,
  just restyled.

### 3. Sessions list page (`sessions/index.blade.php`)

- Creation form: inputs restyled with the new tokens (`border-border`, focus ring in
  `navy`), fields stack full-width on mobile and sit in a row (`sm:flex-row`) on larger
  screens; submit button full-width on mobile.
- List rows: each `<li>` becomes the click target (`cursor-pointer`, hover background
  `hover:bg-cream`) — the existing inner `<a>` still provides the semantic link/href
  (for middle-click / open-in-new-tab), sized to fill the row (`absolute inset-0` or a
  stretched-link pattern) so the whole row is clickable, not just the text.
- An eye icon (inline SVG, 16–18px, `text-muted-strong`, `hover:text-navy`) is placed at
  the trailing edge of each row as an explicit "voir les détails" affordance, inside the
  same clickable row (it does not need its own separate click handler since the row itself
  is the link).
- Status badges (Active / Ouverte-Clôturée) keep their current pill styling, migrated to
  the new tokens.
- Search input restyled to match the new input style used elsewhere.

### 4. Session detail page (`sessions/show.blade.php`)

- **Back navigation**: a "← Retour aux séances" link added above the session title,
  pointing to `route('admin.sessions.index')`. This is the direct fix for goal #3 and is
  additive to the sidebar's own "Séances" nav link (belt-and-suspenders: works even if a
  user has the sidebar collapsed/scrolled past on mobile).
- **Header actions**: the existing action cluster (QR code popover button, Export PDF
  link, status badge, Ouvrir/Clôturer form/button) is wrapped so that:
  - Desktop (`md:` and up): laid out in a horizontal row, as today.
  - Mobile: each action stacks full-width in a vertical column below the title/date block
    (per user's choice — no collapsing into an overflow menu).
- Stat tiles grid keeps its existing responsive behavior
  (`grid-cols-2 gap-3 md:grid-cols-5`), restyled with the new color tokens.
- Filters row (search input, title `<select>`, category pill buttons): wraps naturally via
  existing `flex-wrap`; pill buttons get slightly larger touch targets on mobile
  (`py-2` instead of `py-1.5` below `md`).
- Attendee list rows: the name/title/club block and phone number and the "toggle present"
  button currently sit in one `flex items-center justify-between` row, which will overflow
  the phone number off-screen on narrow phones. Fix: below `sm`, phone number moves to a
  second line under the name (still shown, just re-flowed), and the toggle button stays
  right-aligned — achieved with a `flex-col sm:flex-row` wrapper, no data or logic changes.
- All interactive elements in this page (QR toggle button, share/download buttons,
  export PDF link, open/close toggle button, category filter pills, toggle-present
  buttons) get explicit `cursor-pointer`.

### 5. Login page (`auth/login.blade.php`)

- Add the Rotary Nexus logo above the "Connexion administrateur" heading, centered,
  modest size (~56px height), keeping the rest of the centered-card layout as-is (no
  sidebar — user isn't authenticated).
- Inputs/button restyled with the new color tokens for visual consistency with the rest
  of the refreshed admin area.

### 6. Icons

No icon package dependency is added. The eye icon (and any other icon needed, e.g. a
hamburger/menu icon and a close "×" icon for the mobile drawer) are inlined as small
hand-written SVGs directly in the Blade templates (outline style, `currentColor` stroke,
~18–24px), consistent with the project's current no-dependency approach to the QR code
panel and everything else.

### 7. `cursor-pointer` audit

Every element that acts as a click target across the touched files gets `cursor-pointer`
explicitly, even where a native element (`<button>`, `<a>`) already implies it by default
in most browsers — this makes it explicit and consistent, and covers non-native click
targets like the sessions list `<li>` row wrapper. Scope: files touched in this pass only
(`admin.blade.php`, `sessions/index.blade.php`, `sessions/show.blade.php`,
`auth/login.blade.php`). Not retrofitted onto the public attendance form (out of scope).

## Testing

- No backend logic changes, so no new Pest feature tests are required for this pass.
- Existing feature tests that assert on admin page content/routes (if any use exact
  string/HTML matching that this refresh would break, e.g. checking for
  "Retour" or exact classes) will be checked and adjusted for structural changes
  (e.g. presence of the "Retour aux séances" link, sidebar nav link) rather than exact
  markup, following existing test conventions.
- Manual verification: load `/admin/login`, `/admin/sessions`, and an individual
  `/admin/sessions/{id}` page at both a desktop and a mobile (375px) viewport to confirm
  the sidebar/drawer, back link, logo, eye icon, and stacked mobile actions all work as
  designed, per this project's `verify`/`run` skills.

## Files touched

- `resources/css/app.css` (new color tokens)
- `resources/js/app.js` (new `adminShell` Alpine component)
- `resources/views/components/layouts/admin.blade.php` (sidebar shell + mobile drawer)
- `resources/views/admin/sessions/index.blade.php`
- `resources/views/admin/sessions/show.blade.php`
- `resources/views/admin/auth/login.blade.php`
