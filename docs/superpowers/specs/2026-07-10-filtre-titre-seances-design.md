# Filtre par titre sur la liste des séances (admin)

Date : 2026-07-10
Statut : Approuvé (brainstorming)

## Contexte

Le module de liste de présence (voir `2026-07-10-liste-presence-design.md`) est
en production interne. La page `admin/sessions` (`MeetingSessionController::index`)
liste toutes les `MeetingSession` sans aucun filtre ni pagination — juste un
`<ul>` trié par date/heure décroissantes. À mesure que le nombre de séances
grandit, l'admin veut pouvoir retrouver rapidement une séance par son titre.

## Portée

Dans le périmètre :

- Champ de recherche texte au-dessus de la liste des séances, filtrant en
  direct (sans rechargement de page) par titre
- Filtrage insensible à la casse, sur correspondance partielle (substring)

Hors périmètre :

- Pagination (n'existe pas aujourd'hui, non demandée)
- Filtre serveur (query string, endpoint dédié)
- Filtre par date ou par statut (ouverte/clôturée/active) — uniquement le
  titre est demandé
- Tri des résultats (l'ordre par date/heure décroissantes du contrôleur est
  conservé tel quel)

## Décisions d'architecture

| Sujet                        | Décision                                                                                                     |
| ----------------------------- | -------------------------------------------------------------------------------------------------------------- |
| Emplacement du filtrage       | Client (Alpine.js), pas serveur — cohérent avec le seul autre filtre existant (`attendanceDashboard` sur le dashboard de séance) |
| Données                       | La liste complète des séances est déjà chargée par le contrôleur (`->get()`, pas de pagination) ; elle est sérialisée en JSON via `@js()` et filtrée côté client |
| Nouvelles routes/contrôleurs  | Aucune                                                                                                           |
| Nouvelle dépendance          | Aucune (Alpine.js déjà en place)                                                                                |

## Composants

### `resources/js/app.js`

Nouveau composant Alpine `sessionsList(sessions)`, suivant le même patron que
`attendanceDashboard` :

```js
Alpine.data('sessionsList', (sessions) => ({
    sessions,
    search: '',
    get filtered() {
        const search = this.search.toLowerCase();
        return this.sessions.filter((s) => s.title.toLowerCase().includes(search));
    },
}));
```

### `resources/views/admin/sessions/index.blade.php`

- La `<ul>` de la liste passe sous
  `x-data="sessionsList(@js($meetingSessions->map(fn ($s) => [...])))"`, avec
  chaque séance sérialisée : `id`, `title`, `date` (déjà formatée
  `d/m/Y` côté Blade), `url` (`route('admin.sessions.show', $s)`),
  `isActive`, `isOpen`.
- Ajout d'un `<input type="text" x-model="search" placeholder="Rechercher un
  titre…">` juste au-dessus de la liste, même style que le champ de
  recherche du dashboard de séance (`show.blade.php`).
- Le `@foreach` existant devient
  `<template x-for="session in filtered" :key="session.id">`, avec :
  - le lien `<a :href="session.url">` affichant `session.title` et la date
  - les badges « Active » / « Ouverte » / « Clôturée » pilotés par
    `x-show="session.isActive"` et `:class`/`x-text` sur `session.isOpen`
- L'ordre d'affichage suit l'ordre du tableau `sessions` tel que reçu du
  contrôleur (pas de re-tri côté client).

## Comportements détaillés

1. Chargement de la page : la liste complète des séances s'affiche, champ de
   recherche vide.
2. Saisie dans le champ : à chaque frappe, seules les séances dont le titre
   contient la saisie (insensible à la casse) restent affichées.
3. Champ vidé : la liste complète réapparaît.
4. Aucune séance ne correspond : la liste est simplement vide (pas de
   message dédié, cohérent avec l'absence de message équivalent sur le
   dashboard de séance).

## Gestion des erreurs

Aucune donnée utilisateur n'est soumise au serveur (filtrage 100% client) :
aucune validation serveur nécessaire. Pas de nouvel état d'erreur possible.

## Tests (Pest)

Comme il n'y a ni nouvelle route, ni nouveau contrôleur, ni nouvelle
migration, un test Pest browser (`tests/Browser`) couvre le comportement :

- La page `admin/sessions` affiche toutes les séances par défaut
- Taper un titre (ou une partie) dans le champ de recherche ne laisse
  visibles que les séances correspondantes
- Vider le champ réaffiche toutes les séances

## Auto-review

- Pas de placeholder / TBD restant.
- Cohérence avec le module existant : réutilise le patron Alpine
  (`Alpine.data(...)`) déjà utilisé par `attendanceDashboard`, sans
  introduire de route, contrôleur, migration ou dépendance.
- Portée resserrée : uniquement le filtre par titre demandé, pas de
  pagination ni de filtres additionnels (date, statut) non demandés.
- Point tranché explicitement : filtrage client plutôt que serveur, choisi
  par l'utilisateur lors du brainstorming (cohérent avec le seul précédent
  existant dans le codebase).
