# QR code du formulaire d'émargement

Date : 2026-07-10
Statut : Approuvé (brainstorming)

## Contexte

Le module de liste de présence (voir `2026-07-10-liste-presence-design.md`) est
en production interne : formulaire public d'émargement + dashboard admin
livrés et testés (tâches 1 à 11 + review finale). L'admin souhaite maintenant
pouvoir partager facilement le lien du formulaire public lors des réunions,
via un QR code affiché sur le dashboard.

Le formulaire public résout toujours la séance active côté serveur
(`MeetingSession::active()`) et vit à une seule URL fixe (`route('attendance.show')`,
la racine du site). Le QR code n'a donc pas besoin d'être régénéré par
séance : un seul QR code, pointant vers cette URL fixe, reste valide pour
toutes les séances passées et futures.

## Portée

Dans le périmètre :
- Génération du QR code côté navigateur (aucun appel serveur, aucune
  nouvelle route/contrôleur/migration)
- Affichage sur le dashboard d'une séance (`admin/sessions/{id}`), derrière
  un bouton de bascule à côté d'« Exporter en PDF »
- Bouton « Partager le lien » (partage natif mobile, repli presse-papier)
- Bouton « Télécharger le QR code » (export PNG)

Hors périmètre :
- QR code différent par séance (explicitement rejeté : même URL pour toutes
  les séances)
- Génération côté serveur / route dédiée à une image QR
- Historique ou statistiques de scans

## Décisions d'architecture

| Sujet | Décision |
|---|---|
| Génération du QR code | Librairie JS côté navigateur (`qrcode`, npm), rendue dans un `<canvas>` — pas d'appel réseau, fonctionne hors-ligne |
| Nouvelle dépendance | `qrcode` (npm), à approuver comme `alpinejs`/`barryvdh/laravel-dompdf` l'ont été |
| URL encodée | `route('attendance.show')`, injectée depuis Blade — fixe, identique pour toutes les séances |
| Emplacement | Panneau repliable sur le dashboard de séance (`admin/sessions/{id}`), pas de page dédiée |
| Partage du lien | `navigator.share({ url })` si disponible, sinon `navigator.clipboard.writeText(url)` avec confirmation visuelle |
| Téléchargement de l'image | `canvas.toDataURL('image/png')` + lien `<a download>` déclenché en JS |
| Nouvelles routes/contrôleurs/migrations | Aucune |

## Composants

### `resources/js/app.js`
Nouveau composant Alpine `qrCodePanel(url)`, suivant le même patron que
`attendanceDashboard` :
- `open` (bool, défaut `false`) : pilote l'affichage du panneau
- `url` : l'URL du formulaire, reçue en paramètre depuis Blade
- `copied` (bool) : état temporaire pour le retour visuel du bouton copier
- `render(canvasEl)` : appelle `QRCode.toCanvas(canvasEl, this.url)` à
  l'ouverture du panneau (pas au chargement de la page, pour éviter un
  rendu inutile si l'admin n'ouvre jamais le panneau)
- `share()` : `navigator.share` si `navigator.share` existe, sinon
  `navigator.clipboard.writeText` + `copied = true` pendant 2s
- `download(canvasEl)` : construit un lien `<a>` temporaire avec
  `canvasEl.toDataURL('image/png')` et `download="qr-code-emargement.png"`,
  déclenche un clic programmatique

### `resources/views/admin/sessions/show.blade.php`
- Ajout d'un bouton « QR code » dans le bloc d'actions du header, à côté
  du lien « Exporter en PDF », qui bascule `open`
- Ajout d'un panneau `x-show="open"` contenant : le `<canvas>`, l'URL
  affichée en texte sélectionnable, et les deux boutons d'action
- Le composant est instancié avec `x-data="qrCodePanel(@js(route('attendance.show')))"`
  sur un conteneur dédié (pas sur le conteneur `attendanceDashboard`
  existant, pour garder les deux composants indépendants)

## Comportements détaillés

1. Chargement du dashboard : le panneau QR code est fermé par défaut, rien
   n'est rendu (pas de coût de rendu si l'admin ne l'utilise pas).
2. Clic sur « QR code » : le panneau s'ouvre, le canvas se remplit via
   `qrcode`. Un second clic referme le panneau (le canvas déjà rendu n'est
   pas redétruit, juste masqué via `x-show`).
3. Clic sur « Partager le lien » :
   - Navigateur mobile avec Web Share API : ouvre la feuille de partage
     native (WhatsApp, SMS, etc.) pré-remplie avec l'URL.
   - Sinon : copie l'URL dans le presse-papier, le bouton affiche
     brièvement « Lien copié ✓ » puis revient à son libellé normal.
4. Clic sur « Télécharger le QR code » : télécharge un fichier PNG du QR
   code affiché, quel que soit le navigateur.

## Gestion des erreurs

- `navigator.share` et `navigator.clipboard` sont vérifiés par détection
  de fonctionnalité avant utilisation ; si aucun des deux n'est disponible,
  le texte de l'URL reste affiché et sélectionnable manuellement — aucun
  bouton mort, aucune exception JS non gérée.
- Aucune donnée utilisateur n'est en jeu (l'URL encodée est publique et
  fixe) : aucune validation serveur nécessaire.

## Tests (Pest)

Comme il n'y a ni nouvelle route, ni nouveau contrôleur, ni nouvelle
migration, la couverture Pest se limite à vérifier que le dashboard expose
correctement les données nécessaires au composant côté client :
- Le dashboard authentifié affiche le bouton de bascule « QR code »
- Le dashboard authentifié contient l'URL du formulaire public
  (`route('attendance.show')`) dans le payload Alpine du composant
  `qrCodePanel`

Le rendu du QR code lui-même (canvas, librairie tierce) n'est pas testable
en Pest ; il a été vérifié manuellement au moment de l'implémentation
(cf. smoke test du module principal).

## Auto-review

- Pas de placeholder / TBD restant.
- Cohérence avec le module existant : réutilise le patron Alpine
  (`Alpine.data(...)`) et les tokens de design déjà en place, sans
  introduire de nouvelle route ni de nouvelle dépendance côté PHP.
- Portée resserrée : un seul QR code global, pas de variante par séance,
  conformément à la demande explicite.
- Point tranché explicitement : le rendu du canvas est différé à
  l'ouverture du panneau (pas au chargement de la page) pour éviter un
  coût de rendu inutile — décision d'implémentation raisonnable, non
  demandée explicitement mais cohérente avec le principe YAGNI.
