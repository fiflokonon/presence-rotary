# Loader personnalisé par tenant — Design

Date : 2026-08-12

## Contexte

Le composant `resources/views/components/loader.blade.php` affiche actuellement le logo
statique `assets/ife-logo.png` avec la classe `animate-spin`. Il est utilisé uniquement
dans `resources/views/components/page-loading-overlay.blade.php` (l'overlay de chargement
de page), lui-même inclus dans les layouts `app` et `admin`.

Problème : le loader affiche toujours le logo de RC Cotonou Ifè, quel que soit le tenant.
On veut afficher le logo du **club courant**. Mais tous les logos ne rendent pas bien en
rotation. On stabilise donc le logo (aucune rotation) et on ajoute un indicateur de
chargement **en dessous**.

## Objectif

- Le loader affiche le logo du tenant courant, **fixe** (sans rotation).
- Fallback sur `assets/ife-logo.png` si le club n'a pas de logo.
- Un indicateur de chargement animé apparaît sous le logo : **3 points qui pulsent**.

## Décisions

- **Source du logo** : `\App\Models\ClubSetting::current()?->logoUrl() ?? asset('assets/ife-logo.png')`.
  C'est le pattern déjà utilisé dans `layouts/admin.blade.php` et `admin/auth/login.blade.php`.
- **Indicateur** : 3 points animés avec l'utilitaire Tailwind natif `animate-bounce`, chaque
  point ayant un `animation-delay` décalé (0ms / 150ms / 300ms) pour l'effet séquentiel.
- **Couleur des points** : `primary_color` du club, fallback `#0B73C5`, via `style` inline
  (convention déjà présente dans `layouts/admin.blade.php`).
- **Fond de l'overlay** : inchangé (`bg-[#12213D]/40`).

## Implémentation

### `resources/views/components/loader.blade.php`

Le composant devient un empilement vertical.

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

Notes :
- Le prop `$class` (ex. `h-16 w-16`) s'applique désormais à l'`<img>` du logo, plus au
  conteneur — le comportement de dimensionnement reste identique pour l'appelant.
- Les `$attributes` fusionnent sur le conteneur (classes de layout de l'appelant, le cas
  échéant).
- `animate-spin` est **retiré** du logo.

### Appelant — inchangé

`page-loading-overlay.blade.php` continue d'appeler `<x-loader class="h-16 w-16" />`.
Aucune autre modification.

## Tests

Test feature (Pest) sur le rendu du composant / de l'overlay :

- Avec un `ClubSetting` ayant un `logo_path` et un `primary_color`, le rendu contient
  l'URL du logo du club et la couleur primaire sur les points.
- Sans `ClubSetting`, le rendu retombe sur `assets/ife-logo.png` et la couleur `#0B73C5`.
- Le logo **ne porte plus** la classe `animate-spin` (logo stabilisé).
- Les 3 points animés (`animate-bounce`) sont présents.

## Hors périmètre

- Aucune modification des layouts, de l'overlay, ou du store Alpine `pageLoading`.
- Pas de changement du fond de l'overlay.
