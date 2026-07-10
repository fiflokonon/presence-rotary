# Filtre par titre / qualité sur la liste de présence d'une séance — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the admin filter the attendance roster on a session's dashboard (`admin/sessions/{id}`) by the attendee's exact title/qualité (Rotarien, Président, Secrétaire, Invité…), live, without a page reload.

**Architecture:** Purely client-side, extending the existing `attendanceDashboard` Alpine.js component. A new `activeTitle` state and `titleOptions` getter (distinct titles present in the current roster) are added alongside the existing `search` and `activeCategory` state, and combined with AND logic in the `filtered` getter. No new routes, controllers, or migrations — the JSON payload already sent to the component contains `record.title`.

**Tech Stack:** Laravel 13, Blade, Alpine.js (already installed), Pest 4.

## Global Constraints

- Follow existing Laravel/Pest/Pint conventions declared in `CLAUDE.md` (curly braces always, constructor promotion, explicit return types, PHPDoc array shapes, TitleCase enum keys).
- Run `vendor/bin/pint --dirty --format agent` after any PHP changes, before considering the task done.
- No new dependencies (JS or PHP) — client-side filtering only, per the approved spec (`docs/superpowers/specs/2026-07-10-filtre-titre-qualite-presence-design.md`).
- The dropdown must only list titles actually present in the session being viewed, not the full 17-value `AttendanceTitle` enum.
- This repo has no browser-interaction test tooling installed (no `pest-plugin-browser`, no Playwright) — `tests/Pest.php` only registers `Feature`. Follow the existing precedent set by `AttendanceDashboardTest.php`'s `->assertSee('qrCodePanel(', false)` test: verify via HTTP feature test that the filter is wired up and the data payload is present, not the live in-browser filtering itself. Live filtering is verified manually.
- Design tokens (colors, radii, spacing) are documented in `docs/superpowers/specs/2026-07-10-liste-presence-design.md` — reuse the same literal hex values already used in `resources/views/admin/sessions/show.blade.php` (e.g. `#DEDAD0`).
- After the task's tests pass, commit with a message describing this task only.

---

### Task 1: Title/qualité filter dropdown on the attendance dashboard

**Files:**
- Modify: `resources/js/app.js` (extend `attendanceDashboard` Alpine component, lines 6-39)
- Modify: `resources/views/admin/sessions/show.blade.php` (add `<select>` filter, lines 77-88)
- Test: `tests/Feature/Admin/AttendanceDashboardTest.php` (add one test)

**Interfaces:**
- Consumes: the existing JSON payload built in `show.blade.php:3-13` and passed to `x-data="attendanceDashboard(...)"` — each record already has a `title` string field (`$attendance->title->value`), no change needed there.
- Produces: nothing consumed by other tasks — this is a self-contained, terminal feature.

- [ ] **Step 1: Write the failing test**

Open `tests/Feature/Admin/AttendanceDashboardTest.php` and add this test at the end of the file:

```php
it('exposes a title/qualité filter listing the titles present in the roster', function () {
    $meetingSession = MeetingSession::factory()->create();
    Attendance::factory()->for($meetingSession)->create(['title' => AttendanceTitle::Rotarien, 'name' => 'Jean Dupont']);
    Attendance::factory()->for($meetingSession)->create(['title' => AttendanceTitle::Invite, 'name' => 'Awa Bello']);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.show', $meetingSession))
        ->assertOk()
        ->assertSee('x-model="activeTitle"', false)
        ->assertSee('Tous les titres')
        ->assertSee('Rotarien')
        ->assertSee('Invité');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter="exposes a title/qualité filter"`
Expected: FAIL — the response does not contain `x-model="activeTitle"` or `Tous les titres` (neither exists yet).

- [ ] **Step 3: Extend the `attendanceDashboard` Alpine component**

In `resources/js/app.js`, replace the component (lines 6-39):

```js
Alpine.data('attendanceDashboard', (records) => ({
    records,
    search: '',
    activeCategory: 'all',
    activeTitle: 'all',
    get titleOptions() {
        return [...new Set(this.records.map((record) => record.title))].sort();
    },
    get filtered() {
        const search = this.search.toLowerCase();

        return this.records.filter((record) => {
            const matchesCategory = this.activeCategory === 'all' || record.category === this.activeCategory;
            const matchesTitle = this.activeTitle === 'all' || record.title === this.activeTitle;
            const matchesSearch = record.name.toLowerCase().includes(search);

            return matchesCategory && matchesTitle && matchesSearch;
        });
    },
    get groups() {
        const order = ['officials', 'members', 'rotaractors', 'guests'];

        return order
            .map((category) => ({
                category,
                records: this.filtered.filter((record) => record.category === category),
            }))
            .filter((group) => group.records.length > 0);
    },
    initials(name) {
        return name
            .split(' ')
            .filter(Boolean)
            .slice(0, 2)
            .map((part) => part[0])
            .join('')
            .toUpperCase();
    },
}));
```

- [ ] **Step 4: Add the `<select>` filter to the dashboard template**

In `resources/views/admin/sessions/show.blade.php`, replace the filter bar (lines 77-88):

```blade
        <div class="flex flex-wrap items-center gap-3 px-8 py-4">
            <input type="text" x-model="search" placeholder="Rechercher un nom…"
                class="max-w-[280px] rounded-full border border-[#DEDAD0] px-4 py-2 text-sm">
            <select x-model="activeTitle"
                class="rounded-lg border border-[#DEDAD0] px-3 py-2 text-sm">
                <option value="all">Tous les titres</option>
                <template x-for="option in titleOptions" :key="option">
                    <option :value="option" x-text="option"></option>
                </template>
            </select>
            <button type="button" @click="activeCategory = 'all'"
                :class="activeCategory === 'all' ? 'bg-[#12213D] text-white' : 'border border-[#DEDAD0]'"
                class="rounded-full px-3 py-1.5 text-xs font-semibold">Tous</button>
            @foreach (\App\Enums\AttendanceCategory::cases() as $category)
                <button type="button" @click="activeCategory = '{{ $category->value }}'"
                    :class="activeCategory === '{{ $category->value }}' ? 'bg-[#12213D] text-white' : 'border border-[#DEDAD0]'"
                    class="rounded-full px-3 py-1.5 text-xs font-semibold">{{ $category->label() }}</button>
            @endforeach
        </div>
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --compact --filter="exposes a title/qualité filter"`
Expected: PASS

- [ ] **Step 6: Run the full dashboard test file to check for regressions**

Run: `php artisan test --compact tests/Feature/Admin/AttendanceDashboardTest.php`
Expected: All tests PASS (5 pre-existing + 1 new = 6 tests)

- [ ] **Step 7: Format PHP changes**

Run: `vendor/bin/pint --dirty --format agent`
Expected: `tests/Feature/Admin/AttendanceDashboardTest.php` reported clean or auto-fixed (no manual PHP was added outside the test, but Pint must still run per project convention).

- [ ] **Step 8: Build frontend assets**

Run: `npm run build`
Expected: Build succeeds with no errors (confirms `app.js` has no syntax errors before manual verification in the browser).

- [ ] **Step 9: Manual verification**

Start the app (`composer run dev` or equivalent already running), open a session dashboard with attendees of at least two different titles, and confirm:
- The dropdown defaults to "Tous les titres" and shows the full roster.
- Selecting a specific title narrows the roster to matching records only.
- The title filter combines correctly with the existing name search and category buttons (e.g. selecting "Rotarien" + typing part of a name narrows further).
- Switching back to "Tous les titres" restores the category/search-filtered view.

- [ ] **Step 10: Commit**

```bash
git add resources/js/app.js resources/views/admin/sessions/show.blade.php tests/Feature/Admin/AttendanceDashboardTest.php
git commit -m "feat: add a title/qualité filter to the attendance dashboard"
```
