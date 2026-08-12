# Édition du sous-domaine (super-admin) & masquage des séances (admin) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permettre au super-admin de modifier le sous-domaine (`host`) d'un club, et à l'admin de masquer/afficher des séances dans la liste.

**Architecture:** App Laravel 13 multi-tenant. Le super-admin gère les `Tenant` (connexion `central`) via `TenantController`. Les `MeetingSession` sont par tenant (SQLite dédié). La liste des séances est filtrée côté client par le composant Alpine `sessionsList` dans `resources/js/app.js`.

**Tech Stack:** PHP 8.4, Laravel 13, Pest 4, Blade, Alpine.js 3, Tailwind 4.

## Global Constraints

- PHP `^8.3` ; suivre les conventions PHP du projet (accolades obligatoires, types de retour explicites, promotion de propriétés dans le constructeur).
- Chaque changement doit être couvert par un test Pest ; lancer `php artisan test --compact --filter=...` sur les tests concernés.
- Après modification de fichiers PHP : lancer `vendor/bin/pint --dirty --format agent`.
- Les migrations de séance vont dans `database/migrations/` (par-tenant), **pas** dans `database/migrations/central/`.
- Interdiction absolue de masquer la séance active.
- Les libellés UI sont en français, cohérents avec l'existant.

---

### Task 1: Édition du sous-domaine d'un club (super-admin)

**Files:**
- Create: `app/Http/Requests/SuperAdmin/UpdateTenantRequest.php`
- Create: `resources/views/super-admin/tenants/edit.blade.php`
- Modify: `app/Http/Controllers/SuperAdmin/TenantController.php` (ajout `edit` et `update`)
- Modify: `routes/web.php` (2 routes dans le groupe super-admin, après la ligne `tenants.create`)
- Modify: `resources/views/super-admin/tenants/index.blade.php` (lien « Modifier » par ligne)
- Test: `tests/Feature/SuperAdmin/TenantHostEditTest.php`

**Interfaces:**
- Consumes: `App\Models\Tenant` (connexion `central`, `$fillable` inclut déjà `host`) ; factory `Tenant::factory()` ; modèle `App\Models\SuperAdmin` + garde `super_admin` ; helper de test `superAdminUrl(string $path)`.
- Produces:
  - Routes nommées `super-admin.tenants.edit` (`GET superadmin/tenants/{tenant}/edit`) et `super-admin.tenants.update` (`PATCH superadmin/tenants/{tenant}`).
  - `TenantController::edit(Tenant $tenant): View`
  - `TenantController::update(UpdateTenantRequest $request, Tenant $tenant): RedirectResponse`

- [ ] **Step 1: Write the failing test**

Créer `tests/Feature/SuperAdmin/TenantHostEditTest.php` :

```php
<?php

use App\Models\SuperAdmin;
use App\Models\Tenant;

it('displays the tenant edit page to a super-admin', function () {
    $tenant = Tenant::factory()->create(['name' => 'Rotary Club Test', 'host' => 'test.example.test']);

    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->get(superAdminUrl("superadmin/tenants/{$tenant->id}/edit"))
        ->assertOk()
        ->assertSee('Rotary Club Test')
        ->assertSee('test.example.test');
});

it('updates the host of a tenant', function () {
    $tenant = Tenant::factory()->create(['host' => 'old.example.test']);

    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->patch(superAdminUrl("superadmin/tenants/{$tenant->id}"), [
            'host' => 'new.example.test',
        ])->assertRedirect(superAdminUrl('superadmin/tenants'));

    expect($tenant->refresh()->host)->toBe('new.example.test');
});

it('rejects a host already used by another tenant', function () {
    Tenant::factory()->create(['host' => 'taken.example.test']);
    $tenant = Tenant::factory()->create(['host' => 'mine.example.test']);

    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->patch(superAdminUrl("superadmin/tenants/{$tenant->id}"), [
            'host' => 'taken.example.test',
        ])->assertSessionHasErrors(['host']);

    expect($tenant->refresh()->host)->toBe('mine.example.test');
});

it('accepts the tenant keeping its own unchanged host', function () {
    $tenant = Tenant::factory()->create(['host' => 'same.example.test']);

    $this->actingAs(SuperAdmin::factory()->create(), 'super_admin')
        ->patch(superAdminUrl("superadmin/tenants/{$tenant->id}"), [
            'host' => 'same.example.test',
        ])->assertRedirect(superAdminUrl('superadmin/tenants'));

    expect($tenant->refresh()->host)->toBe('same.example.test');
});

it('requires guests to authenticate before editing', function () {
    $tenant = Tenant::factory()->create();

    $this->get(superAdminUrl("superadmin/tenants/{$tenant->id}/edit"))
        ->assertRedirect();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=TenantHostEditTest`
Expected: FAIL (routes `super-admin.tenants.edit` / `update` inexistantes → erreur de résolution de route / 404).

- [ ] **Step 3: Create the form request**

Créer `app/Http/Requests/SuperAdmin/UpdateTenantRequest.php` :

```php
<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'host' => [
                'required',
                'string',
                'max:255',
                Rule::unique('central.tenants', 'host')->ignore($this->route('tenant')),
            ],
        ];
    }
}
```

- [ ] **Step 4: Add controller methods**

Dans `app/Http/Controllers/SuperAdmin/TenantController.php`, ajouter l'import `use App\Http\Requests\SuperAdmin\UpdateTenantRequest;` puis ces deux méthodes (par ex. après `create`) :

```php
public function edit(Tenant $tenant): View
{
    return view('super-admin.tenants.edit', [
        'tenant' => $tenant,
    ]);
}

public function update(UpdateTenantRequest $request, Tenant $tenant): RedirectResponse
{
    $tenant->update(['host' => $request->validated('host')]);

    return redirect()->route('super-admin.tenants.index')->with('status', 'Sous-domaine mis à jour.');
}
```

- [ ] **Step 5: Register the routes**

Dans `routes/web.php`, dans le groupe super-admin, juste après la ligne `Route::get('tenants/create', ...)->name('tenants.create');` :

```php
Route::get('tenants/{tenant}/edit', [TenantController::class, 'edit'])->name('tenants.edit');
Route::patch('tenants/{tenant}', [TenantController::class, 'update'])->name('tenants.update');
```

- [ ] **Step 6: Create the edit view**

Créer `resources/views/super-admin/tenants/edit.blade.php` :

```blade
<x-layouts.super-admin title="Modifier le club — Super-admin">
    <div class="mx-auto max-w-lg rounded-2xl bg-white p-6 shadow-[0_2px_10px_rgba(20,30,50,.06)]">
        <h1 class="font-display text-xl font-extrabold text-navy">Modifier le sous-domaine</h1>
        <p class="mt-1 text-sm text-muted">Club : <span class="font-semibold text-navy">{{ $tenant->name }}</span></p>

        <form method="POST" action="{{ route('super-admin.tenants.update', $tenant) }}" class="mt-6 flex flex-col gap-4">
            @csrf
            @method('PATCH')
            <div class="flex flex-col gap-1.5">
                <label for="host" class="text-sm font-semibold">Sous-domaine (ex. club2.tondomaine.org)</label>
                <input type="text" id="host" name="host" value="{{ old('host', $tenant->host) }}" required
                    class="rounded-lg border border-border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-navy">
                @error('host')
                    <p class="text-sm text-error">{{ $message }}</p>
                @enderror
            </div>
            <div class="flex items-center gap-3">
                <button type="submit"
                    class="cursor-pointer rounded-lg bg-navy px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-hover">
                    Enregistrer
                </button>
                <a href="{{ route('super-admin.tenants.index') }}"
                    class="cursor-pointer text-sm font-semibold text-muted hover:text-navy">
                    Annuler
                </a>
            </div>
        </form>
    </div>
</x-layouts.super-admin>
```

- [ ] **Step 7: Add "Modifier" link in the tenants list**

Dans `resources/views/super-admin/tenants/index.blade.php`, dans la cellule d'actions (celle qui contient le formulaire « Voir en tant que »), envelopper les deux actions dans un conteneur flex et ajouter le lien. Remplacer le `<td>` d'actions par :

```blade
<td class="py-3 pr-4">
    <div class="flex items-center gap-4">
        <a href="{{ route('super-admin.tenants.edit', $tenant) }}"
            class="cursor-pointer text-sm font-semibold text-navy hover:text-navy-hover">
            Modifier
        </a>
        <form method="POST" action="{{ route('super-admin.impersonate.start', $tenant) }}">
            @csrf
            <button type="submit" class="cursor-pointer text-sm font-semibold text-navy hover:text-navy-hover">
                Voir en tant que
            </button>
        </form>
    </div>
</td>
```

- [ ] **Step 8: Run tests to verify they pass**

Run: `php artisan test --compact --filter=TenantHostEditTest`
Expected: PASS (5 tests).

- [ ] **Step 9: Format**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 10: Commit**

```bash
git add app/Http/Requests/SuperAdmin/UpdateTenantRequest.php app/Http/Controllers/SuperAdmin/TenantController.php routes/web.php resources/views/super-admin/tenants/edit.blade.php resources/views/super-admin/tenants/index.blade.php tests/Feature/SuperAdmin/TenantHostEditTest.php
git commit -m "feat: allow super-admin to edit a club subdomain"
```

---

### Task 2: Colonne `is_hidden` sur les séances

**Files:**
- Create: `database/migrations/2026_08_12_120000_add_is_hidden_to_meeting_sessions_table.php`
- Modify: `app/Models/MeetingSession.php` (`$fillable` + `casts()`)
- Test: `tests/Feature/Admin/MeetingSessionManagementTest.php` (ajout d'un test au fichier existant)

**Interfaces:**
- Consumes: `App\Models\MeetingSession`, `MeetingSession::factory()`.
- Produces: attribut booléen `is_hidden` sur `MeetingSession` (défaut `false`), assignable en masse et casté en `bool`.

- [ ] **Step 1: Write the failing test**

Ajouter à la fin de `tests/Feature/Admin/MeetingSessionManagementTest.php` :

```php
it('defaults is_hidden to false and casts it to a boolean', function () {
    $meetingSession = MeetingSession::factory()->create();

    expect($meetingSession->fresh()->is_hidden)->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter="defaults is_hidden"`
Expected: FAIL (colonne `is_hidden` inexistante → erreur SQL / propriété nulle).

- [ ] **Step 3: Create the migration**

Créer `database/migrations/2026_08_12_120000_add_is_hidden_to_meeting_sessions_table.php` :

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting_sessions', function (Blueprint $table) {
            $table->boolean('is_hidden')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('meeting_sessions', function (Blueprint $table) {
            $table->dropColumn('is_hidden');
        });
    }
};
```

- [ ] **Step 4: Update the model**

Dans `app/Models/MeetingSession.php` :
- `$fillable` devient : `['title', 'date', 'time', 'is_open', 'is_active', 'is_hidden']`
- Dans `casts()`, ajouter `'is_hidden' => 'boolean',`.

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact --filter="defaults is_hidden"`
Expected: PASS.

- [ ] **Step 6: Format**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_08_12_120000_add_is_hidden_to_meeting_sessions_table.php app/Models/MeetingSession.php tests/Feature/Admin/MeetingSessionManagementTest.php
git commit -m "feat: add is_hidden column to meeting sessions"
```

---

### Task 3: Action masquer/afficher + exclusion des sélecteurs (backend)

**Files:**
- Modify: `app/Http/Controllers/Admin/MeetingSessionController.php` (méthode `toggleHidden`, filtre `is_hidden` dans `show`)
- Modify: `routes/web.php` (route `admin.sessions.toggle-hidden`)
- Test: `tests/Feature/Admin/MeetingSessionManagementTest.php`

**Interfaces:**
- Consumes: `App\Models\MeetingSession` (avec `is_hidden` de la Task 2), `App\Models\User` (garde admin par défaut), route model binding `{meetingSession}`.
- Produces: route nommée `admin.sessions.toggle-hidden` (`POST sessions/{meetingSession}/toggle-hidden`) ; méthode `MeetingSessionController::toggleHidden(MeetingSession $meetingSession): RedirectResponse`.

- [ ] **Step 1: Write the failing tests**

Ajouter à `tests/Feature/Admin/MeetingSessionManagementTest.php` :

```php
it('hides a non-active session and shows it again', function () {
    $meetingSession = MeetingSession::factory()->create(['is_active' => false, 'is_hidden' => false]);

    $this->actingAs(User::factory()->create())
        ->post(route('admin.sessions.toggle-hidden', $meetingSession))
        ->assertRedirect();

    expect($meetingSession->fresh()->is_hidden)->toBeTrue();

    $this->actingAs(User::factory()->create())
        ->post(route('admin.sessions.toggle-hidden', $meetingSession))
        ->assertRedirect();

    expect($meetingSession->fresh()->is_hidden)->toBeFalse();
});

it('refuses to hide the active session', function () {
    $meetingSession = MeetingSession::factory()->create(['is_active' => true, 'is_hidden' => false]);

    $this->actingAs(User::factory()->create())
        ->post(route('admin.sessions.toggle-hidden', $meetingSession))
        ->assertSessionHasErrors();

    expect($meetingSession->fresh()->is_hidden)->toBeFalse();
});

it('excludes hidden sessions from the upcoming sessions selector on the show page', function () {
    $current = MeetingSession::factory()->create(['date' => now()->toDateString()]);
    $visibleUpcoming = MeetingSession::factory()->create(['title' => 'Séance visible', 'date' => now()->addWeek()->toDateString(), 'is_hidden' => false]);
    $hiddenUpcoming = MeetingSession::factory()->create(['title' => 'Séance masquée', 'date' => now()->addWeeks(2)->toDateString(), 'is_hidden' => true]);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.show', $current))
        ->assertOk()
        ->assertSee('Séance visible')
        ->assertDontSee('Séance masquée');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=MeetingSessionManagementTest`
Expected: FAIL (route `admin.sessions.toggle-hidden` inexistante ; le sélecteur affiche encore la séance masquée).

- [ ] **Step 3: Add the toggleHidden controller method**

Dans `app/Http/Controllers/Admin/MeetingSessionController.php`, ajouter (par ex. après `toggleOpen`) :

```php
public function toggleHidden(MeetingSession $meetingSession): RedirectResponse
{
    if (! $meetingSession->is_hidden && $meetingSession->is_active) {
        return redirect()->back()->withErrors(['is_hidden' => 'Impossible de masquer la séance active.']);
    }

    $meetingSession->update(['is_hidden' => ! $meetingSession->is_hidden]);

    return redirect()->back();
}
```

- [ ] **Step 4: Exclude hidden sessions from the upcoming selector**

Dans la méthode `show` du même contrôleur, ajouter `->where('is_hidden', false)` à la requête `upcomingSessions` :

```php
'upcomingSessions' => MeetingSession::where('id', '!=', $meetingSession->id)
    ->where('is_hidden', false)
    ->where('date', '>=', now()->toDateString())
    ->orderBy('date')
    ->get(),
```

- [ ] **Step 5: Register the route**

Dans `routes/web.php`, dans le groupe admin des séances, après la ligne `sessions/{meetingSession}/toggle-open` :

```php
Route::post('sessions/{meetingSession}/toggle-hidden', [MeetingSessionController::class, 'toggleHidden'])->name('sessions.toggle-hidden');
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --compact --filter=MeetingSessionManagementTest`
Expected: PASS.

- [ ] **Step 7: Format**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Admin/MeetingSessionController.php routes/web.php tests/Feature/Admin/MeetingSessionManagementTest.php
git commit -m "feat: add toggle-hidden action and exclude hidden sessions from selectors"
```

---

### Task 4: UI — liste des séances (filtre client + boutons)

**Files:**
- Modify: `resources/js/app.js` (composant Alpine `sessionsList`)
- Modify: `resources/views/admin/sessions/index.blade.php` (payload, checkbox, badge, bouton masquer/afficher)
- Test: `tests/Feature/Admin/MeetingSessionManagementTest.php`

**Interfaces:**
- Consumes: route `admin.sessions.toggle-hidden` (Task 3), attribut `is_hidden` (Task 2), composant `sessionsList`.
- Produces: le payload JS de chaque séance inclut `isHidden` ; état `showHidden` dans le composant ; la vue rend toutes les séances (masquées incluses) pour permettre le filtrage client.

- [ ] **Step 1: Write the failing test**

Ajouter à `tests/Feature/Admin/MeetingSessionManagementTest.php` :

```php
it('includes hidden sessions in the index payload for client-side filtering', function () {
    MeetingSession::factory()->create(['title' => 'Séance masquée liste', 'is_hidden' => true]);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.index'))
        ->assertOk()
        ->assertSee('Séance masquée liste')
        ->assertSee('Afficher les séances masquées');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter="includes hidden sessions in the index"`
Expected: FAIL (« Afficher les séances masquées » absent de la vue).

- [ ] **Step 3: Update the Alpine component**

Dans `resources/js/app.js`, remplacer le composant `sessionsList` par :

```js
Alpine.data('sessionsList', (sessions) => ({
    sessions,
    search: '',
    showHidden: false,
    get filtered() {
        const search = this.search.toLowerCase();

        return this.sessions.filter((session) => {
            if (session.isHidden && !this.showHidden) {
                return false;
            }

            return session.title.toLowerCase().includes(search);
        });
    },
}));
```

- [ ] **Step 4: Update the index view**

Dans `resources/views/admin/sessions/index.blade.php` :

4a. Ajouter `'isHidden' => $meetingSession->is_hidden,` au tableau mappé dans `x-data="sessionsList(@js(...))"` (après `'isOpen'`).

4b. Après le champ de recherche (`<input type="text" x-model="search" ...>`), ajouter la checkbox :

```blade
<label class="mt-3 flex items-center gap-2 text-sm text-muted-strong">
    <input type="checkbox" x-model="showHidden" class="rounded border-border text-navy focus:ring-navy">
    Afficher les séances masquées
</label>
```

4c. Remplacer le contenu du `<li>` (le bloc `<template x-for>`) pour ajouter le badge « Masquée » et le bouton masquer/afficher. Comme un `<a>` ne peut pas contenir un `<form>`, restructurer la ligne en `<div>` avec le lien et le formulaire côte à côte :

```blade
<template x-for="session in filtered" :key="session.id">
    <li>
        <div class="flex items-center justify-between gap-3 rounded-lg py-3 pl-2 pr-2 hover:bg-cream">
            <a :href="session.url" class="flex min-w-0 flex-1 items-center gap-2">
                <span class="min-w-0 truncate text-sm font-semibold text-navy">
                    <span x-text="session.title"></span> — <span x-text="session.date"></span>
                </span>
            </a>
            <span class="flex shrink-0 items-center gap-2">
                <span x-show="session.isHidden" class="rounded-full bg-divider px-2 py-0.5 text-[11px] font-semibold uppercase text-muted">Masquée</span>
                <span x-show="session.isActive" class="rounded-full bg-success-bg px-2 py-0.5 text-[11px] font-semibold uppercase text-success">Active</span>
                <span :class="session.isOpen ? 'bg-success-bg text-success' : 'bg-divider text-muted'" class="rounded-full px-2 py-0.5 text-[11px] font-semibold uppercase" x-text="session.isOpen ? 'Ouverte' : 'Clôturée'"></span>
                <template x-if="!session.isActive || session.isHidden">
                    <form :action="session.toggleHiddenUrl" method="POST">
                        @csrf
                        <button type="submit" class="cursor-pointer text-xs font-semibold text-muted-strong hover:text-navy" x-text="session.isHidden ? 'Afficher' : 'Masquer'"></button>
                    </form>
                </template>
            </span>
        </div>
    </li>
</template>
```

4d. Ajouter `'toggleHiddenUrl' => route('admin.sessions.toggle-hidden', $meetingSession),` au tableau mappé dans `x-data` (après `'url'`).

- [ ] **Step 5: Build assets**

Run: `npm run build`
Expected: build réussi (le manifeste Vite est régénéré pour que le test qui rend la vue trouve les assets).

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --compact --filter="includes hidden sessions in the index"`
Expected: PASS.

- [ ] **Step 7: Format**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 8: Commit**

```bash
git add resources/js/app.js resources/views/admin/sessions/index.blade.php tests/Feature/Admin/MeetingSessionManagementTest.php public/build
git commit -m "feat: filter hidden sessions in the sessions list with a toggle"
```

---

### Task 5: UI — bouton masquer/afficher sur la page de détail

**Files:**
- Modify: `resources/views/admin/sessions/show.blade.php`
- Test: `tests/Feature/Admin/MeetingSessionManagementTest.php`

**Interfaces:**
- Consumes: route `admin.sessions.toggle-hidden` (Task 3), `$meetingSession` dans la vue `show`.
- Produces: bouton « Masquer » / « Afficher » sur la page de détail ; absent pour la séance active non masquée.

- [ ] **Step 1: Write the failing tests**

Ajouter à `tests/Feature/Admin/MeetingSessionManagementTest.php` :

```php
it('shows a hide button on the detail page of a non-active session', function () {
    $meetingSession = MeetingSession::factory()->create(['is_active' => false, 'is_hidden' => false]);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.show', $meetingSession))
        ->assertOk()
        ->assertSee('Masquer');
});

it('hides the hide button for the active session on the detail page', function () {
    $meetingSession = MeetingSession::factory()->create(['is_active' => true, 'is_hidden' => false]);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.show', $meetingSession))
        ->assertOk()
        ->assertDontSee('>Masquer<', false);
});

it('shows a "show" button on the detail page of a hidden session', function () {
    $meetingSession = MeetingSession::factory()->create(['is_active' => false, 'is_hidden' => true]);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.sessions.show', $meetingSession))
        ->assertOk()
        ->assertSee('Afficher la séance');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter="detail page"`
Expected: FAIL (aucun bouton masquer/afficher sur la page de détail).

- [ ] **Step 3: Add the hide/show button to the detail page**

Dans `resources/views/admin/sessions/show.blade.php`, à l'intérieur de la barre d'actions (le `<div class="flex flex-col gap-3 md:flex-row md:items-center">`, après le lien « Exporter en PDF » ligne 56-59), ajouter :

```blade
@if (! $meetingSession->is_active || $meetingSession->is_hidden)
    <form method="POST" action="{{ route('admin.sessions.toggle-hidden', $meetingSession) }}" class="w-full md:w-auto">
        @csrf
        <button type="submit"
            class="cursor-pointer flex w-full items-center justify-center gap-2 rounded-lg border border-border px-4 py-2 text-sm font-bold text-navy hover:bg-cream md:w-auto">
            {{ $meetingSession->is_hidden ? 'Afficher la séance' : 'Masquer' }}
        </button>
    </form>
@endif
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter="detail page"`
Expected: PASS.

- [ ] **Step 5: Format**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 6: Run the full affected suites**

Run: `php artisan test --compact --filter="TenantHostEditTest|MeetingSessionManagementTest"`
Expected: PASS (tous).

- [ ] **Step 7: Commit**

```bash
git add resources/views/admin/sessions/show.blade.php tests/Feature/Admin/MeetingSessionManagementTest.php
git commit -m "feat: add hide/show button on the session detail page"
```

---

## Self-Review

**Spec coverage :**
- Édition du sous-domaine (page dédiée, route, validation unique-ignore, lien liste) → Task 1. ✓
- Colonne `is_hidden` (migration tenant + modèle) → Task 2. ✓
- Action toggle-hidden + garde séance active → Task 3. ✓
- Exclusion des séances masquées du sélecteur « séance suivante » / à venir → Task 3, Step 4. ✓
- Liste : exclusion par défaut + checkbox « Afficher les séances masquées » + badge → Task 4. ✓
- Bouton masquer/afficher dans la liste ET sur la page de détail → Task 4 (liste) + Task 5 (détail). ✓
- Tests couvrant chaque comportement → présents dans chaque task. ✓

**Placeholder scan :** aucun TODO/TBD ; tout le code est fourni littéralement. ✓

**Type consistency :** `toggleHidden(MeetingSession $meetingSession): RedirectResponse`, route `admin.sessions.toggle-hidden`, attribut `is_hidden`, champ JS `isHidden`/`showHidden`/`toggleHiddenUrl`, `super-admin.tenants.edit`/`update` — cohérents entre toutes les tasks. ✓

**Note d'ordonnancement :** Task 3 dépend de Task 2 (colonne) ; Tasks 4 et 5 dépendent de Task 3 (route). Exécuter dans l'ordre 1→5 (Task 1 est indépendante).
