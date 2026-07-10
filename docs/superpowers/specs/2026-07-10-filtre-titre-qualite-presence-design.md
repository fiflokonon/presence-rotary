# Filtre par titre / qualité sur la liste de présence d'une séance (admin)

Date : 2026-07-10
Statut : Approuvé (brainstorming)

## Contexte

Le dashboard de séance (`admin/sessions/{id}`, `show.blade.php`) affiche déjà
la liste des présences avec un champ de recherche par nom (`x-model="search"`)
et des boutons de filtre par catégorie (Bureau/Officiels, Membres,
Rotaractiens, Invités) — pilotés par le composant Alpine `attendanceDashboard`
dans `resources/js/app.js`. La catégorie est une classification large dérivée
du champ `title` de chaque `Attendance` (`AttendanceTitle::category()`).

Le champ `title` lui-même (ex. Rotarien, Président, Secrétaire, Invité…) est
appelé "Titre / Qualité" dans le formulaire de présence
(`attendance-form.blade.php:19`). L'admin veut pouvoir filtrer la liste de
présence d'une séance sur ce champ précis, en plus de la catégorie et du nom.

## Portée

Dans le périmètre :

- Menu déroulant filtrant en direct (sans rechargement de page) la liste de
  présence du dashboard de séance (`show.blade.php`) par `title` exact
  (Rotarien, Président, Secrétaire, Invité, etc.)
- Le menu ne propose que les titres réellement présents dans la séance
  affichée (pas les 17 valeurs de l'enum `AttendanceTitle`)
- Ce filtre se combine (ET logique) avec la recherche par nom et les boutons
  de catégorie déjà existants

Hors périmètre :

- Export PDF (`admin/sessions/pdf.blade.php`) — non demandé
- Page `admin/sessions` (liste des séances) — déjà traitée par un filtre
  précédent (`docs/superpowers/specs/2026-07-10-filtre-titre-seances-design.md`)
- Filtre serveur (query string, endpoint dédié)
- Pagination, tri des résultats

## Décisions d'architecture

| Sujet | Décision |
| --- | --- |
| Emplacement du filtrage | Client (Alpine.js), cohérent avec la recherche par nom et le filtre par catégorie déjà existants sur ce même composant |
| Données | Le payload JSON déjà sérialisé pour `attendanceDashboard` (`show.blade.php:3-13`) contient déjà `record.title` — aucune donnée supplémentaire à sérialiser |
| Nouvelles routes/contrôleurs | Aucune |
| Nouvelle dépendance | Aucune (Alpine.js déjà en place) |

## Composants

### `resources/js/app.js`

Dans `Alpine.data('attendanceDashboard', ...)` :

- Nouvel état `activeTitle: 'all'`.
- Nouveau getter `titleOptions` : valeurs distinctes de `record.title`
  présentes dans `this.records`, triées alphabétiquement.
- `filtered` étendu avec une troisième condition :
  `matchesTitle = this.activeTitle === 'all' || record.title === this.activeTitle`,
  combinée en ET avec `matchesCategory` et `matchesSearch` existants.

### `resources/views/admin/sessions/show.blade.php`

- Un `<select x-model="activeTitle">` ajouté juste après le champ de
  recherche existant (ligne 78-79), dans la même barre de filtres.
  - Option par défaut `value=""` avec le label "Tous les titres",
    correspondant à `activeTitle === 'all'` (donc `value` réel `"all"` sur
    cette option, ou remise à `'all'` via le binding — à trancher pendant
    l'implémentation en cohérence avec le pattern des boutons de catégorie
    existants).
  - `<template x-for="option in titleOptions" :key="option">` pour les
    options restantes, affichant `option`.
  - Style aligné sur le `<select>` déjà utilisé dans
    `attendance-form.blade.php:20-21` :
    `rounded-lg border border-[#DEDAD0] px-3 py-2 text-sm`.

## Comportements détaillés

1. Chargement de la page : la liste complète des présences s'affiche, menu
   déroulant sur "Tous les titres".
2. Sélection d'un titre : seuls les enregistrements dont `title` correspond
   exactement restent affichés, en plus des filtres nom/catégorie déjà actifs.
3. Retour à "Tous les titres" : le filtre par titre est levé, les autres
   filtres restent inchangés.
4. Aucun enregistrement ne correspond à la combinaison de filtres : la liste
   est simplement vide (pas de message dédié, cohérent avec le comportement
   actuel de la recherche par nom).
5. Le menu déroulant ne liste que les titres présents dans la séance
   affichée ; il n'y a donc jamais d'option menant à une liste vide par
   construction (sauf combinaison avec un autre filtre actif).

## Gestion des erreurs

Aucune donnée utilisateur n'est soumise au serveur (filtrage 100% client) :
aucune validation serveur nécessaire, aucun nouvel état d'erreur possible.

## Tests (Pest)

Comme il n'y a ni nouvelle route, ni nouveau contrôleur, ni nouvelle
migration, et que ce repo n'a pas d'outillage de test navigateur, on suit le
précédent posé par le filtre de titre sur `admin/sessions` : un test Feature
vérifie que la page contient le `<select>` de filtre (placeholder "Tous les
titres" ou équivalent) et qu'un titre présent dans les données de test
apparaît dans le payload JSON envoyé à `attendanceDashboard(...)`. Le
filtrage live (sélection dans le menu déroulant, combinaison avec les autres
filtres) est vérifié manuellement.

## Auto-review

- Pas de placeholder / TBD restant, à l'exception d'un point volontairement
  laissé à l'implémentation (la valeur exacte de l'option "Tous les titres"
  dans le `<select>`), documenté explicitement plutôt que laissé implicite.
- Cohérence avec le module existant : réutilise le patron déjà en place pour
  `matchesCategory`/`matchesSearch` dans `attendanceDashboard`, sans
  introduire de route, contrôleur, migration ou dépendance.
- Portée resserrée : uniquement le filtre par titre/qualité sur le dashboard
  de séance, pas d'export PDF, pas de filtre sur la liste des séances (déjà
  traité ailleurs), pas de pagination.
- Point tranché explicitement : filtrage client plutôt que serveur, et menu
  déroulant restreint aux titres présents dans la séance plutôt que la liste
  complète de l'enum — décisions validées par l'utilisateur lors du
  brainstorming.
