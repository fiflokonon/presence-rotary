# Liste de présence — Rotary Club de Cotonou Nexus

Date : 2026-07-10
Statut : Approuvé (brainstorming)

## Contexte

Le dossier design (`README.md`, `Liste de présence Admin.html`, `assets/rotary-nexus-logo.png`)
est un handoff de haute fidélité pour un module d'émargement de réunions de club :
un formulaire public d'émargement et un tableau de bord administrateur. Le HTML fourni
est une référence visuelle/fonctionnelle, pas du code à copier — le module doit être
recréé nativement dans l'application Laravel (13, PHP 8.4, Tailwind v4, Pest).

Le projet est actuellement un squelette Laravel vierge : aucun modèle métier, aucune
route applicative, pas d'authentification, pas de dépôt git. Ce document couvre la
mise en place du module tel que décrit dans le README, ainsi que le bootstrap du
projet lui-même (git, dépendances, base de données).

## Portée

Dans le périmètre :
- Formulaire public d'émargement (ouvert / clôturé / mode retard / confirmation)
- Tableau de bord admin : compteurs, recherche/filtres, groupement par catégorie,
  toggle présence, ouverture/clôture de séance, export PDF
- Authentification admin minimale (pas d'auto-inscription)
- Gestion des séances (création, activation, liste)
- Tests Pest couvrant les règles métier et les routes protégées

Hors périmètre (v1) :
- Mise à jour temps réel du dashboard (websockets/polling) — rechargement de page suffit
- Gestion multi-club / multi-district (un seul club, en dur dans les vues comme le prototype)
- Reset de mot de passe / gestion de plusieurs rôles admin — un seul type de compte admin
- Filtrage serveur de la liste (le README l'autorise en client tant que < 200 personnes)

## Décisions d'architecture

| Sujet | Décision |
|---|---|
| Authentification admin | Minimale, faite main : guard `web` natif Laravel, pas de Breeze, pas d'auto-inscription, comptes créés via seeder |
| Interactivité (filtres, recherche, mode retard) | Blade + Alpine.js, pas de Livewire ni d'API séparée |
| Gestion des séances | CRUD complet : liste, création, activation, historique consultable |
| Séance "active" (celle du formulaire public) | Auto-activation à la création : créer une séance l'active automatiquement et désactive l'ancienne |
| Export PDF | `barryvdh/laravel-dompdf` |
| Nouvelles dépendances | Alpine.js (npm) + `barryvdh/laravel-dompdf` (composer), approuvées |
| Dépôt git | Initialisé (`git init`), commit initial fait avant cette spec |

## Modèle de données

### `meeting_sessions`
Nommé `MeetingSession` (et non `Session`) pour éviter toute collision avec le
concept de session HTTP de Laravel.

| Colonne | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `title` | string | ex. "Réunion hebdomadaire" |
| `date` | date | |
| `time` | time | |
| `is_open` | bool, défaut `true` | pilote l'écran "porte fermée" côté formulaire public |
| `is_active` | bool, défaut `false` | une seule séance active à la fois ; c'est elle que le formulaire public résout |

### `attendances`
| Colonne | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `meeting_session_id` | FK → `meeting_sessions` | |
| `title` | enum (17 valeurs, cf. README §Formulaire) | PDG, DG, DGE, DGN, AdG, PAdG, Past Président, Président, Président Elu, Président Nommé, Secrétaire, Trésorier, Protocole, Président de Commission, Rotarien, Rotaractien, Invité |
| `name` | string | requis |
| `club` | string | requis |
| `phone` | string | requis |
| `classification` | string, nullable | optionnel |
| `email` | string, nullable | optionnel |
| `present` | bool, défaut `true` | corrigeable manuellement par l'admin |
| `is_late` | bool, défaut `false` | vrai si soumis alors que `is_open = false` au moment du submit |
| timestamps | | |

`category` est un accessor Eloquent (non stocké) dérivé de `title` via le mapping
du README :
- Officiels (Bureau) : PDG, DG, DGE, DGN, AdG, PAdG, Past Président, Président,
  Président Elu, Président Nommé, Secrétaire, Trésorier, Protocole, Président de Commission
- Membres : Rotarien
- Rotaractiens : Rotaractien
- Invités : Invité

### `users`
Table Laravel par défaut, réutilisée telle quelle pour les comptes admin
(pas de champ supplémentaire nécessaire).

## Routes & contrôleurs

**Public**
- `GET /` → `AttendanceFormController@show` : résout la `MeetingSession` active
  (`is_active = true`). Aucune séance active → vue "aucune séance en cours"
  (état non présent dans le prototype, ajouté pour la robustesse : le prototype
  suppose toujours une séance existante).
- `POST /attendances` → `AttendanceFormController@store` : valide, crée l'`Attendance`
  rattachée à la séance active, `present = true`, `is_late = ! session->is_open`.

**Admin** (middleware `auth`, préfixe `/admin`)
- `GET /admin/login`, `POST /admin/login`, `POST /admin/logout` → `Admin\AuthController`
- `GET /admin/sessions` → liste des séances (la plus récente d'abord)
- `POST /admin/sessions` → création (title, date, time) ; auto-active et désactive
  l'ancienne active dans la même transaction
- `POST /admin/sessions/{meetingSession}/toggle-open` → inverse `is_open`
- `GET /admin/sessions/{meetingSession}` → dashboard (compteurs + liste groupée),
  données sérialisées en JSON dans le DOM pour Alpine
- `PATCH /admin/attendances/{attendance}/toggle-present` → inverse `present`,
  redirect back
- `GET /admin/sessions/{meetingSession}/export-pdf` → PDF dompdf groupé par catégorie

## Comportements détaillés

### Formulaire public
1. Pas de séance active → écran informatif, pas de formulaire.
2. Séance active + `is_open = true` → formulaire normal, les 4 champs requis
   (`title`, `name`, `club`, `phone`) validés côté serveur ; erreurs affichées via
   bandeau rouge (`@error`/session flash), fidèle au prototype (`bg #FBEAEA`, texte `#B23B3B`).
3. Séance active + `is_open = false` → écran "porte fermée" (icône ⏱, bouton
   "Marquer ma présence en retard"). Un clic Alpine (`x-show`) révèle le même
   formulaire avec le bandeau orange retard — pas de rechargement de page pour
   ce toggle d'affichage.
4. Soumission réussie → écran de confirmation (texte différent selon `is_late`),
   bouton "Envoyer une autre réponse" qui réaffiche le formulaire vierge (Alpine,
   sans rechargement serveur nécessaire puisque c'est un état post-submit côté client
   après un redirect avec flash de succès).

### Dashboard admin
- Les attendances de la séance affichée sont sérialisées en JSON dans un attribut
  Alpine (`x-data`) au chargement de la page.
- Recherche (nom) et filtres (catégorie) sont purement client-side sur ce JSON —
  aligné avec l'autorisation du README pour les séances < 200 personnes.
- Toggle présence et toggle ouverture/clôture de séance restent des soumissions
  de formulaire serveur classiques (pas d'Alpine/fetch), donc l'état affiché est
  toujours cohérent avec la base après rechargement.
- Bandeau de compteurs (total, par catégorie) calculé côté serveur au rendu initial ;
  pas recalculé en JS lors du filtrage (le README ne l'exige pas — le filtrage
  n'affecte que la liste, pas les compteurs globaux).

### Export PDF
Vue Blade dédiée `admin.sessions.pdf`, réutilisant le même regroupement par
catégorie que le dashboard, rendue via `barryvdh/laravel-dompdf`.

## Gestion des erreurs

- Validation formulaire public : erreurs Laravel standards (`$request->validate()`),
  redirect back avec `withErrors` + `withInput`.
- Aucune séance active : rendu d'une vue dédiée plutôt qu'une exception ou un 404 brut.
- Routes admin sans authentification : redirigées vers `/admin/login` par le
  middleware `auth`.
- Actions sur une séance/attendance inexistante : 404 Laravel standard (route model binding).

## Tests (Pest)

- **Mapping catégorie** : chaque valeur de `title` retourne la bonne `category` (accessor).
- **Soumission formulaire public** :
  - séance ouverte → `attendance` créée avec `present=true`, `is_late=false`
  - séance clôturée (mode retard) → `is_late=true`
  - champs requis manquants → erreurs de validation, aucun enregistrement créé
  - aucune séance active → vue informative, pas de formulaire
- **Dashboard admin** :
  - accès sans authentification → redirigé vers login
  - accès authentifié → 200, compteurs corrects par catégorie
  - toggle présence → inverse `present` en base
  - toggle ouverture/clôture → inverse `is_open` en base
- **Séances** :
  - création → nouvelle séance `is_active=true`, `is_open=true`, ancienne séance
    active passée à `is_active=false`
- **Export PDF** : statut 200, `content-type: application/pdf`

## Auto-review

- Pas de placeholder / TBD restant.
- Cohérence vérifiée entre modèle de données, routes et comportements décrits.
- Portée resserrée à un seul club/district (cohérent avec le prototype, pas de
  sur-ingénierie multi-tenant).
- Point ambigu tranché explicitement : l'état "aucune séance active" n'existe pas
  dans le prototype (qui suppose toujours une séance) ; ajouté ici comme garde-fou
  nécessaire pour un premier déploiement sans données.
