# Filtre par titre sur la liste des séances — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the admin filter the `admin/sessions` list by title, live, without a page reload.

**Architecture:** Purely client-side. A new Alpine.js component (`sessionsList`) receives the full list of sessions (already loaded by the controller, unpaginated) serialized from Blade, and filters it in-browser as the admin types. No new routes, controllers, or migrations.

**Tech Stack:** Laravel 13, Blade, Alpine.js (already installed), Pest 4.

## Global Constraints

- Follow existing Laravel/Pest/Pint conventions declared in `CLAUDE.md` (curly braces always, constructor promotion, explicit return types, PHPDoc array shapes, TitleCase enum keys).
- Run `vendor/bin/pint --dirty --format agent` after any PHP changes, before considering the task done.
- No new dependencies (JS or PHP) — client-side filtering only, per the approved spec (`docs/superpowers/specs/2026-07-10-filtre-titre-seances-design.md`).
- Design tokens (colors, radii, spacing) are documented in `docs/superpowers/specs/2026-07-10-liste-presence-design.md` — reuse the same literal hex values already used in `resources/views/admin/sessions/index.blade.php` and `show.blade.php` (e.g. `#12213D`, `#DEDAD0`, `#EDEAE2`, `#8A8474`, `#0E7C66`, `#6B6558`, `#F1EFEA`, `#E7F5F1`).
- This repo has no browser-interaction test tooling installed (no `pest-plugin-browser`, no Playwright) — `tests/Pest.php` only registers `Feature`. Follow the existing precedent set by the QR-code feature (`AttendanceDashboardTest.php`'s `->assertSee('qrCodePanel(', false)` test): verify via HTTP feature test that the Alpine component is wired up and the data payload is present, not the live in-browser filtering itself. Live filtering is verified manually.
- After the task's tests pass, commit with a message describing this task only.

---

### Task 1: Live title filter on the sessions list

**Files:**
- Modify: `resources/js/app.js` (add `sessionsList` Alpine component)
- Modify: `resources/views/admin/sessions/index.blade.php` (add search input, convert the list to an Alpine `x-for` template)
- Test: `tests/Feature/Admin/MeetingSessionManagementTest.php` (add one test)

**Interfaces:**
- Consumes: the existing `$meetingSessions` collection passed to the view by `MeetingSessionController::index()` (`app/Http/Controllers/Admin/MeetingSessionController.php:15-20`) — no controller change needed, it already returns all sessions unpaginated, ordered by date/time descending.
- Produces: nothing consumed by other tasks — this is a self-contained, terminal feature.

- [ ] **Step 1: Write the failing test**

Open `tests/Feature/Admin/MeetingSessionManagementTest.php` and add this test at the end of the file:

```php
it('exposes a title filter with every session in the client-side payload', function () {
    MeetingSession::factory()->create(['title' => 'Réunion hebdomadaire']);
    MeetingSession::factory()->create(['title' => 'Assemblée annuelle']);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.index'))
        ->assertOk()
        ->assertSee('sessionsList(', false)
        ->assertSee('Rechercher un titre…')
        ->assertSee('Réunion hebdomadaire')
        ->assertSee('Assemblée annuelle');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter="exposes a title filter"`
Expected: FAIL — the response does not contain `sessionsList(` or `Rechercher un titre…` (neither exists yet).

- [ ] **Step 3: Add the `sessionsList` Alpine component**

In `resources/js/app.js`, add this block after the closing `}));` of `attendanceDashboard` (line 39) and before `Alpine.data('qrCodePanel', ...)` (line 41):

```js
Alpine.data('sessionsList', (sessions) => ({
    sessions,
    search: '',
    get filtered() {
        const search = this.search.toLowerCase();

        return this.sessions.filter((session) => session.title.toLowerCase().includes(search));
    },
}));
```

- [ ] **Step 4: Rewrite the sessions list markup to use the component**

In `resources/views/admin/sessions/index.blade.php`, replace the `<ul>` block (lines 34-50):

```blade
        <ul class="mt-6 divide-y divide-[#EDEAE2]">
            @foreach ($meetingSessions as $meetingSession)
                <li class="flex items-center justify-between py-3">
                    <a href="{{ route('admin.sessions.show', $meetingSession) }}" class="text-sm font-semibold text-[#12213D] hover:underline">
                        {{ $meetingSession->title }} — {{ $meetingSession->date->format('d/m/Y') }}
                    </a>
                    <span class="flex items-center gap-2">
                        @if ($meetingSession->is_active)
                            <span class="rounded-full bg-[#E7F5F1] px-2 py-0.5 text-[11px] font-semibold uppercase text-[#0E7C66]">Active</span>
                        @endif
                        <span class="rounded-full {{ $meetingSession->is_open ? 'bg-[#E7F5F1] text-[#0E7C66]' : 'bg-[#F1EFEA] text-[#6B6558]' }} px-2 py-0.5 text-[11px] font-semibold uppercase">
                            {{ $meetingSession->is_open ? 'Ouverte' : 'Clôturée' }}
                        </span>
                    </span>
                </li>
            @endforeach
        </ul>
```

with:

```blade
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
                class="mt-6 max-w-[280px] rounded-full border border-[#DEDAD0] px-4 py-2 text-sm">

            <ul class="mt-4 divide-y divide-[#EDEAE2]">
                <template x-for="session in filtered" :key="session.id">
                    <li class="flex items-center justify-between py-3">
                        <a :href="session.url" class="text-sm font-semibold text-[#12213D] hover:underline">
                            <span x-text="session.title"></span> — <span x-text="session.date"></span>
                        </a>
                        <span class="flex items-center gap-2">
                            <span x-show="session.isActive" class="rounded-full bg-[#E7F5F1] px-2 py-0.5 text-[11px] font-semibold uppercase text-[#0E7C66]">Active</span>
                            <span :class="session.isOpen ? 'bg-[#E7F5F1] text-[#0E7C66]' : 'bg-[#F1EFEA] text-[#6B6558]'" class="rounded-full px-2 py-0.5 text-[11px] font-semibold uppercase" x-text="session.isOpen ? 'Ouverte' : 'Clôturée'"></span>
                        </span>
                    </li>
                </template>
            </ul>
        </div>
```

Leave everything else in the file unchanged (the layout wrapper, the heading, the create form, the validation error block).

- [ ] **Step 5: Build the frontend assets**

Run: `npm run build`
Expected: exits 0, `public/build/manifest.json` regenerated with no errors, `sessionsList` bundled into `app-*.js`.

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test --compact --filter="exposes a title filter"`
Expected: PASS.

- [ ] **Step 7: Run the full suite to check for regressions**

Run: `php artisan test --compact`
Expected: all tests pass — in particular `MeetingSessionManagementTest`'s `lists existing sessions to an authenticated admin` test (which asserts `->assertSee('Réunion hebdomadaire')`) must still pass, since the title is still rendered, just via `@js()` serialization instead of a Blade loop.

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/js/app.js resources/views/admin/sessions/index.blade.php tests/Feature/Admin/MeetingSessionManagementTest.php
git commit -m "feat: add a live title filter to the admin sessions list"
```

- [ ] **Step 9: Manual verification**

Run `composer run dev` (or `npm run dev` + `php artisan serve`), log in as an admin, open `/admin/sessions`, and confirm:
- All sessions are listed by default, search field empty.
- Typing part of a session's title (any case) narrows the list to matching sessions only, with no page reload.
- Clearing the field restores the full list.
- The "Active" / "Ouverte" / "Clôturée" badges and the link to each session's dashboard still work correctly on the filtered items.

---

## Self-Review Notes

- Spec coverage: live client-side filter by title (case-insensitive, substring match), no pagination/date/status filter added, existing sort order preserved (array order from the controller is untouched) — all covered by Task 1's single deliverable.
- No `TBD`/placeholder steps remain; the full JS component and Blade markup are given verbatim.
- Naming consistency checked: `sessionsList` (Alpine component name) is used identically in `resources/js/app.js` (`Alpine.data('sessionsList', ...)`) and `resources/views/admin/sessions/index.blade.php` (`x-data="sessionsList(...)"`); the `filtered` getter and `session.title`/`session.date`/`session.url`/`session.isActive`/`session.isOpen` property names match between the component and the template.
- Scope: intentionally a single task — one JS component, one Blade rewrite, one test. Splitting further (e.g. "add search input" vs. "wire up filtering") would leave an intermediate state with a dead input or a component with nothing rendering it, neither independently useful nor independently testable.
- Testing gap flagged explicitly: this repo has no browser-automation test tooling, so the actual keystroke-by-keystroke filtering behavior isn't asserted by Pest — same limitation already accepted for the QR-code feature's canvas rendering. Step 9 (manual verification) closes that gap for this task.
