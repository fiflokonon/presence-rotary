# Handoff : Liste de présence — Rotary Club de Cotonou Nexus

## Aperçu
Ce module gère l'émargement des réunions du club : un formulaire public que chaque participant remplit pour confirmer sa présence, et un tableau de bord administrateur qui affiche la liste de présence de la séance en cours, groupée par catégorie (Bureau/Officiels, Membres, Rotaractiens, Invités), avec recherche, filtres, compteurs, export PDF et gestion des présences tardives.

## À propos des fichiers de design
Les fichiers de ce dossier sont des **références de design réalisées en HTML** — des prototypes montrant l'apparence et le comportement voulus, pas du code de production à copier tel quel. La tâche consiste à **recréer ces designs HTML dans l'environnement Laravel du projet** (Blade + contrôleurs + migrations, éventuellement Livewire/Alpine.js pour l'interactivité, ou une API + frontend séparé si c'est l'architecture choisie), en respectant les conventions déjà établies dans le projet.

## Niveau de fidélité
**Haute fidélité (hifi)** : couleurs, typographies, espacements et texte affiché sont définitifs. Recréez l'interface au pixel près avec les outils du projet Laravel (Blade/CSS, ou Tailwind si déjà utilisé). Les données (noms, clubs, téléphones) sont des exemples fictifs à remplacer par les vraies données de la base.

## Écrans / Vues

### 1. Formulaire d'émargement (public, coté participant)
**But** : chaque participant confirme sa présence à la séance en cours.

**Layout** : carte centrée, largeur ~420px, coin arrondi 12px, ombre légère (`0 2px 10px rgba(20,30,50,.06)`), fond blanc.
- En-tête bleu marine (`#12213D`), padding `22px 24px 18px` : logo club (image, hauteur 34px, filtré en blanc) + nom de district (`District 9103`, 10px uppercase, couleur `#F2B94D`) + nom du club (15px bold, Libre Franklin).
- Titre "Liste de présence" (20px, 800, Libre Franklin, `#12213D`) + sous-titre "{titre séance} — {date}" (13.5px, `#8A8474`).
- Champs de formulaire (tous en colonne, gap 16px, padding `16px 24px 24px`) :
  - **Titre / Qualité*** (select) — options : PDG, DG, DGE, DGN, AdG, PAdG, Past Président, Président, Président Elu, Président Nommé, Secrétaire, Trésorier, Protocole, Président de Commission, Rotarien, Rotaractien, Invité. Ce choix détermine automatiquement la catégorie (voir mapping ci-dessous).
  - **Nom et prénoms*** (texte)
  - **Votre club*** (texte)
  - **Numéro de téléphone*** (tel)
  - **Classification** (texte, optionnel — ex. secteur professionnel)
  - **Adresse e-mail** (optionnel)
  - Pas de champs date/heure : la date et l'heure sont déterminées automatiquement par la séance active côté serveur, jamais saisies par l'utilisateur.
- Boutons : "Envoyer" (fond `#12213D`, texte blanc, 14px bold, radius 8px) + "Effacer le formulaire" (texte seul, couleur `#C77700`).
- Validation : les 4 champs marqués * sont obligatoires. Si absents à la soumission, bandeau d'erreur rouge clair (`bg #FBEAEA`, texte `#B23B3B`) : "* Merci de remplir les champs obligatoires."

**États** :
- **Séance ouverte** → formulaire normal (décrit ci-dessus).
- **Séance clôturée par l'admin, réponse pas encore commencée** → écran "porte fermée" : icône ⏱ sur fond `#F1EFEA`, titre "La séance est clôturée", texte explicatif, bouton "Marquer ma présence en retard" (fond `#12213D`) qui ouvre le formulaire en mode retard.
- **Mode retard actif** → même formulaire, avec bandeau orange (`bg #FDF3E2`, texte `#C77700`) : "⏱ Séance clôturée — cette réponse sera enregistrée comme présence en retard."
- **Après soumission** → écran de confirmation : coche verte sur fond `#E7F5F1`, "Présence enregistrée", message qui varie si c'était une présence à l'heure ou en retard, bouton "Envoyer une autre réponse" pour recommencer.

### 2. Tableau de bord administrateur
**But** : l'admin visualise, filtre et corrige la liste de présence de la séance en cours ou passée ; ouvre/clôture le pointage ; exporte en PDF.

**Layout** : carte pleine largeur (~960px), fond blanc, radius 12px.

**En-tête** (padding `28px 32px 20px`, bordure basse `#EDEAE2`) :
- Ligne éyebrow "RC Cotonou Nexus · District 9103" (11px uppercase, `#C77700`)
- Titre séance (26px, 800, Libre Franklin, `#12213D`) + date (15px, `#6B6558`)
- À droite : bouton "Exporter en PDF" (fond `#12213D`) + statut de séance (pastille "● Séance ouverte" verte `#E7F5F1`/`#0E7C66` ou "● Séance clôturée" grise `#F1EFEA`/`#6B6558`) + bouton toggle "Clôturer la séance" / "Rouvrir la séance".

**Bandeau de compteurs** (grid 5 colonnes, gap 12px) :
1. Total présents / total (fond bleu marine `#12213D`, texte blanc) — "{présents}/{total}" + "Présents ({pct}%)"
2. Bureau/Officiels (fond `#EAF1FB`, accent `#17458F`)
3. Membres (fond `#E7F5F1`, accent `#0E7C66`)
4. Rotaractiens (fond `#FDF3E2`, accent `#C77700`)
5. Invités (fond `#F1EFEA`, accent `#6B6558`)

**Barre de recherche/filtres** (padding `16px 32px`) : champ recherche par nom (max-width 280px) + chips de filtre par catégorie ("Tous", "Bureau / Officiels", "Membres", "Rotaractiens", "Invités"), radius 999px, bordure `#DEDAD0`.

**Liste groupée** (max-height 520px, scroll) : une section par catégorie présente dans les résultats filtrés, avec pastille de couleur + libellé + compte. Chaque ligne personne :
- avatar rond (initiales, 34px, fond/texte de la couleur de catégorie)
- nom (14.5px, 600) + "titre · club" (12.5px, `#8A8474`), avec suffixe " · marqué en retard" en orange (`#C77700`, bold) si la personne a confirmé après la clôture
- téléphone (monospace, `#A39C8C`)
- bouton toggle présence : "Présent" (fond `#E7F5F1`, texte `#0E7C66`) / "Marquer présent" — cliquable pour correction manuelle par l'admin.

## Mapping Titre → Catégorie
- Officiels (Bureau) : PDG, DG, DGE, DGN, AdG, PAdG, Past Président, Président, Président Elu, Président Nommé, Secrétaire, Trésorier, Protocole, Président de Commission
- Membres : Rotarien
- Rotaractiens : Rotaractien
- Invités : Invité

## Interactions & comportement
- Soumission du formulaire → crée un enregistrement de présence, marqué `present: true`, et `late: true` si la séance était clôturée au moment de la soumission.
- Toggle "Clôturer/Rouvrir la séance" (admin) → change l'état `sessionOpen` de la séance active. Contrôle qui pilote l'affichage "porte fermée" côté formulaire public.
- Recherche et filtres sont côté client (temps réel, à chaque frappe/clic) — en prod, peuvent rester côté client si la liste par séance reste petite (<200 personnes), sinon prévoir un filtrage serveur.
- Bouton toggle présence sur chaque ligne (admin) → correction manuelle, inverse `present`.
- Export PDF : génère un document imprimable de la liste de présence de la séance affichée (à implémenter côté Laravel, ex. avec `barryvdh/laravel-dompdf` ou équivalent — le layout PDF doit reprendre le regroupement par catégorie).

## État / Modèle de données suggéré
Deux entités principales :
- `sessions` (ou `meetings`) : id, title, date, time, is_open (bool)
- `attendances` : id, session_id (FK), name, phone, email (nullable), club, classification (nullable), title (enum des 17 valeurs listées), category (dérivé du titre — soit stocké, soit calculé), present (bool, true par défaut à la soumission), is_late (bool), created_at

Catégorie peut être un accessor Eloquent dérivé du `title` plutôt qu'une colonne séparée, pour éviter toute désynchronisation.

## Design tokens

**Couleurs**
- Bleu marine (primaire, en-têtes, boutons) : `#12213D` / hover `#1c3559`
- Bleu accent (Officiels) : `#17458F`, fond `#EAF1FB`
- Vert (Membres, présence) : `#0E7C66`, fond `#E7F5F1`
- Orange/or (Rotaractiens, retard, accents District) : `#C77700` / `#F2B94D`, fond `#FDF3E2`
- Gris neutre (Invités, texte secondaire) : `#6B6558`, `#8A8474`, `#A39C8C`, fond `#F1EFEA`
- Rouge (erreurs) : `#B23B3B`, fond `#FBEAEA`
- Bordures : `#DEDAD0`, `#EDEAE2`, `#F2F0EA`
- Fond de page : `#F5F3EE`

**Typographie**
- Titres : Libre Franklin, poids 700/800
- Corps/UI : Source Sans 3, poids 400/500/600/700
- Chiffres monospace (téléphone) : ui-monospace / Menlo

**Rayons & ombres**
- Cartes : radius 12px, `box-shadow: 0 2px 10px rgba(20,30,50,.06)`
- Boutons/chips : radius 8px (rectangulaires), 999px (pilules)
- Avatars : cercle (radius 50%)

## Assets
- Logo du club : `assets/rotary-nexus-logo.png` (affiché en blanc sur fond bleu marine via `filter: brightness(0) invert(1)` dans le prototype — utilisez plutôt une version blanche du logo si disponible pour la production).
- Polices Google Fonts : Libre Franklin (600/700/800), Source Sans 3 (400/500/600/700).

## Fichiers
- `Liste de présence Admin.html` — prototype complet (formulaire + tableau de bord + variantes de design explorées 1a/1b/1c, la 1a est la version retenue). Le HTML utilise un moteur de template propriétaire (attributs `{{ }}`, balises `sc-if`/`sc-for`) propre à l'outil de design — ne pas le copier tel quel, il sert de référence visuelle et fonctionnelle.
- `assets/rotary-nexus-logo.png` — logo du club.
