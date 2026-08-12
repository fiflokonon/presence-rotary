# Design — Édition du sous-domaine (super-admin) & masquage des séances (admin)

Date : 2026-08-12

## Contexte

- **Multi-tenant** : `App\Models\Tenant` (connexion `central`) stocke `host` (domaine complet, ex. `club2.tondomaine.org`) et `sqlite_path` (base SQLite dédiée au tenant). Le middleware `App\Http\Middleware\ResolveTenant` résout le tenant via `Tenant::where('host', $request->getHost())`.
- La liste des clubs vit dans `resources/views/super-admin/tenants/index.blade.php`, servie par `App\Http\Controllers\SuperAdmin\TenantController@index`. La colonne « Sous-domaine » affiche `$tenant->host`.
- **Séances** : `App\Models\MeetingSession` est une table **par tenant** (migrations non-`central`). Elle possède déjà `is_active` (séance active unique) et `is_open`. La liste est dans `resources/views/admin/sessions/index.blade.php`, entièrement filtrée côté client via le composant Alpine `sessionsList`. La page de détail est `resources/views/admin/sessions/show.blade.php`.

## Objectifs

1. Permettre au **super-admin** de modifier le sous-domaine (`host`) d'un club.
2. Permettre à l'**admin** de masquer une séance de la liste, avec une checkbox pour réafficher les séances masquées.

---

## Partie 1 — Modification du sous-domaine (super-admin)

### Approche : page d'édition dédiée

### Routes (dans le groupe super-admin de `routes/web.php`)

- `GET tenants/{tenant}/edit` → `TenantController@edit` — nom `super-admin.tenants.edit`
- `PATCH tenants/{tenant}` → `TenantController@update` — nom `super-admin.tenants.update`

### Contrôleur — `App\Http\Controllers\SuperAdmin\TenantController`

- `edit(Tenant $tenant): View` → retourne `super-admin.tenants.edit` avec le tenant.
- `update(UpdateTenantRequest $request, Tenant $tenant): RedirectResponse` → `$tenant->update(['host' => $request->validated('host')])`, redirige vers `super-admin.tenants.index` avec `->with('status', 'Sous-domaine mis à jour.')`.

### Form request — `App\Http\Requests\SuperAdmin\UpdateTenantRequest`

```php
'host' => [
    'required', 'string', 'max:255',
    Rule::unique('central.tenants', 'host')->ignore($this->route('tenant')),
],
```

`authorize()` retourne `true` (cohérent avec les autres requests super-admin ; l'accès est déjà protégé par le middleware du groupe).

### Vues

- **`super-admin/tenants/edit.blade.php`** (nouveau) : layout `x-layouts.super-admin`. Affiche le **nom du club en lecture seule** (contexte), un champ éditable `host` pré-rempli avec `$tenant->host` (label « Sous-domaine (ex. club2.tondomaine.org) », cohérent avec la vue `create`), bouton « Enregistrer », lien « Annuler » vers l'index. Affiche les erreurs de validation.
- **`super-admin/tenants/index.blade.php`** : ajouter un lien « Modifier » par ligne, dans la cellule d'actions à côté de « Voir en tant que ».

### Portée & impacts

- Seul `host` est modifiable. `name`, `sqlite_path` et la base du tenant restent inchangés → **aucun impact sur les données**, uniquement sur la résolution du domaine (le club sera désormais joignable via le nouveau `host`).

---

## Partie 2 — Masquer/afficher une séance (admin)

### Base de données

- **Migration tenant** (répertoire `database/migrations/`, pas `central`) : ajoute `is_hidden` (`boolean`, défaut `false`) à `meeting_sessions`.
- **Modèle `MeetingSession`** : ajouter `'is_hidden'` à `$fillable` et `'is_hidden' => 'boolean'` dans `casts()`.

### Action

- Route (groupe admin de `routes/web.php`) : `POST sessions/{meetingSession}/toggle-hidden` → `MeetingSessionController@toggleHidden` — nom `admin.sessions.toggle-hidden`.
- `toggleHidden(MeetingSession $meetingSession): RedirectResponse` :
  - **Garde** : si la séance est active (`is_active`) et qu'on tente de la masquer (`! $meetingSession->is_hidden`), refuser → redirection retour avec un message d'erreur (« Impossible de masquer la séance active. »). Dé-masquer reste toujours autorisé.
  - Sinon, inverser `is_hidden` et rediriger retour (`back()`), afin de servir aussi bien la liste que la page de détail.

### Liste — `admin/sessions/index.blade.php`

- Le contrôleur `index` continue de charger **toutes** les séances (masquées incluses) et passe `isHidden` dans le payload JS du composant `sessionsList`.
- Le composant Alpine gère un état `showHidden` (défaut `false`). Le `filtered` exclut les séances masquées sauf si `showHidden` est vrai.
- Ajout d'une **checkbox « Afficher les séances masquées »** liée à `showHidden`.
- Les séances masquées affichées portent un badge « Masquée ».
- Bouton/formulaire « Masquer » / « Afficher » par ligne (POST vers `admin.sessions.toggle-hidden`).

### Page de détail — `admin/sessions/show.blade.php`

- Bouton « Masquer » / « Afficher » (POST vers `admin.sessions.toggle-hidden`), désactivé/absent pour la séance active lorsqu'elle n'est pas masquée.

### Cohérence — exclusion des séances masquées

Les séances masquées sont exclues des sélecteurs de « séance suivante » et de la liste des séances à venir dans `MeetingSessionController@show` :

```php
'upcomingSessions' => MeetingSession::where('id', '!=', $meetingSession->id)
    ->where('is_hidden', false)
    ->where('date', '>=', now()->toDateString())
    ->orderBy('date')
    ->get(),
```

(La séance active n'étant jamais masquable, `MeetingSession::active()` et le check-in invité ne sont pas affectés.)

---

## Tests (Pest)

### Partie 1
- La page d'édition (`super-admin.tenants.edit`) s'affiche pour un super-admin.
- Le super-admin met à jour le `host` d'un tenant → valeur persistée en base `central`.
- Un `host` déjà utilisé par un **autre** tenant est rejeté (erreur de validation).
- Soumettre le **même** `host` (inchangé) pour le tenant courant est accepté.

### Partie 2
- `toggleHidden` masque une séance non-active, puis la ré-affiche.
- Masquer la **séance active** est refusé (reste `is_hidden = false`, message d'erreur).
- La vue index reçoit aussi les séances masquées dans son payload (pour la checkbox côté client).
- `show` : `upcomingSessions` exclut les séances masquées.

---

## Hors périmètre

- Modification du `name` du club ou d'autres champs du tenant depuis la page d'édition.
- Suppression définitive de séances.
- Redirection/alias de l'ancien `host` après changement.
