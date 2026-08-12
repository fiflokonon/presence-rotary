# Loader personnalisé par tenant — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Le loader affiche le logo du tenant courant **fixe** (sans rotation) avec 3 points animés en dessous, au lieu du logo Ifè qui tourne.

**Architecture:** Un seul composant Blade (`x-loader`) est refondu en empilement vertical. Il lit `ClubSetting::current()` pour le logo et la couleur primaire, avec fallback sur `ife-logo.png` / `#0B73C5`. L'overlay appelant et le store Alpine `pageLoading` restent inchangés.

**Tech Stack:** Laravel 13, Blade, Tailwind v4 (`animate-bounce`, `object-contain`), Pest 4.

## Global Constraints

- Fallback logo : `asset('assets/ife-logo.png')`.
- Fallback couleur points : `#0B73C5`.
- Couleurs via `style` inline (convention de `layouts/admin.blade.php`).
- Fond de l'overlay inchangé : `bg-[#12213D]/40`.
- Pint : lancer `vendor/bin/pint --dirty --format agent` après toute modif PHP.
- Tests : suite `Feature` avec `RefreshDatabase`. La migration `2026_07_22_120001_seed_club_settings_table` seed déjà une ligne `club_settings` (`name=RC Cotonou Ife`, `logo_path=null`, `primary_color=#0B73C5`) disponible dans chaque test.

---

### Task 1: Refonte du composant loader (logo fixe + 3 points animés)

**Files:**
- Modify: `resources/views/components/loader.blade.php`
- Test: `tests/Feature/LoaderComponentTest.php` (réécriture des 2 tests existants + ajouts)

**Interfaces:**
- Consumes : `\App\Models\ClubSetting::current()` → `?ClubSetting` ; `ClubSetting::logoUrl(): string` ; propriété `primary_color: string`.
- Produces : composant `<x-loader class="..." />` — le prop `class` s'applique au dimensionnement de l'`<img>` du logo (comportement identique pour l'appelant `page-loading-overlay.blade.php`).

- [ ] **Step 1: Réécrire le fichier de test avec le comportement attendu**

Remplacer **tout** le contenu de `tests/Feature/LoaderComponentTest.php` par :

```php
<?php

use App\Models\ClubSetting;

it('renders the tenant logo without rotation by default', function () {
    $html = (string) $this->blade('<x-loader />');

    expect($html)
        ->toContain('ife-logo.png')
        ->toContain('object-contain')
        ->toContain('h-8 w-8')
        ->not->toContain('animate-spin');
});

it('lets callers override the logo sizing classes', function () {
    $html = (string) $this->blade('<x-loader class="h-16 w-16" />');

    expect($html)
        ->toContain('h-16 w-16')
        ->not->toContain('h-8 w-8')
        ->not->toContain('animate-spin');
});

it('renders three bouncing dots in the club primary color', function () {
    $html = (string) $this->blade('<x-loader />');

    expect(substr_count($html, 'animate-bounce'))->toBe(3);
    expect($html)->toContain('#0B73C5');
});

it('uses the club logo and primary color when configured', function () {
    ClubSetting::query()->first()->update([
        'logo_path' => 'tenants/1/club/logo.png',
        'primary_color' => '#FF8800',
    ]);

    $html = (string) $this->blade('<x-loader />');

    expect($html)
        ->toContain('tenants/1/club/logo.png')
        ->toContain('#FF8800')
        ->not->toContain('ife-logo.png');
});
```

- [ ] **Step 2: Lancer les tests pour vérifier qu'ils échouent**

Run: `php artisan test --compact --filter=LoaderComponentTest`
Expected: FAIL — le composant contient encore `animate-spin` et pas `animate-bounce`.

- [ ] **Step 3: Réécrire le composant loader**

Remplacer **tout** le contenu de `resources/views/components/loader.blade.php` par :

```blade
@props(['class' => 'h-8 w-8'])

@php
    $clubSetting = \App\Models\ClubSetting::current();
    $logoUrl = $clubSetting?->logoUrl() ?? asset('assets/ife-logo.png');
    $dotColor = $clubSetting?->primary_color ?? '#0B73C5';
@endphp

<div {{ $attributes->merge(['class' => 'flex flex-col items-center gap-3']) }}>
    <img
        src="{{ $logoUrl }}"
        alt="Chargement…"
        class="{{ $class }} object-contain"
    >
    <div class="flex items-center gap-1.5" role="status" aria-label="Chargement…">
        <span class="h-2 w-2 rounded-full animate-bounce" style="background-color: {{ $dotColor }};"></span>
        <span class="h-2 w-2 rounded-full animate-bounce" style="background-color: {{ $dotColor }}; animation-delay: 150ms;"></span>
        <span class="h-2 w-2 rounded-full animate-bounce" style="background-color: {{ $dotColor }}; animation-delay: 300ms;"></span>
    </div>
</div>
```

- [ ] **Step 4: Lancer les tests pour vérifier qu'ils passent**

Run: `php artisan test --compact --filter=LoaderComponentTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Vérifier la non-régression de l'overlay**

Run: `php artisan test --compact --filter=PageLoadingOverlayTest`
Expected: PASS — l'overlay affiche toujours `ife-logo.png` (club seedé sans logo_path) et le store `pageLoading`.

- [ ] **Step 6: Formatage**

Run: `vendor/bin/pint --dirty --format agent`
Expected: aucune erreur.

- [ ] **Step 7: Commit**

```bash
git add resources/views/components/loader.blade.php tests/Feature/LoaderComponentTest.php
git commit -m "feat: personnalise le loader avec le logo du tenant fixe et 3 points animes

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Self-Review

**1. Spec coverage :**
- Logo du tenant → `$logoUrl` depuis `ClubSetting::current()` ✓ (Task 1, Step 3 + test « uses the club logo »).
- Logo fixe (pas de rotation) → `animate-spin` retiré ✓ (test `not->toContain('animate-spin')`).
- Fallback ife-logo → `logoUrl()` retombe sur ife-logo si `logo_path` null ✓ (test par défaut).
- 3 points qui pulsent → 3 `animate-bounce` ✓ (test `substr_count == 3`).
- Couleur points = primary_color, fallback #0B73C5 → ✓ (tests couleur par défaut et custom).
- Fond overlay inchangé → aucune modif de `page-loading-overlay.blade.php` ✓ (Global Constraints + non-régression Step 5).

**2. Placeholder scan :** aucun TBD/TODO ; code complet fourni pour chaque step.

**3. Type consistency :** `ClubSetting::current(): ?self`, `logoUrl(): string`, `primary_color` string — cohérents entre spec, composant et tests.
