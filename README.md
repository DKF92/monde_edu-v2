# Monde Éducatif V2

Version améliorée de l'application de gestion scolaire (V1 : PHP procédural + Doctrine,
une base de données MySQL par établissement). La V2 repense l'architecture pour gérer
nativement **plusieurs établissements dans une base de données unique**, avec :

- **back/** : API Laravel 13 (PHP 8.3)
- **front/** : Application PWA Angular 18 + Ionic 9

## Pourquoi ce changement d'architecture multi-établissements

En V1, chaque établissement avait sa propre base MySQL (les identifiants de connexion
étaient stockés en clair dans une base "maître" `bd_primaire.info_etablissement`).
Cela complique les migrations (à rejouer sur N bases), empêche les rapports inter-
établissements, et duplique la logique applicative.

En V2, toutes les données vivent dans **une seule base** (`monde_edu_v2`), avec une
colonne `etablissement_id` sur chaque table métier. Un utilisateur peut être rattaché
à plusieurs établissements (table pivot `etablissement_user`, ex : fondateur d'un
groupe scolaire), et son rôle/ses permissions sont définis **par établissement** via
le système "teams" de `spatie/laravel-permission`. Toute requête API doit préciser
l'établissement courant via l'en-tête `X-Etablissement-Id` ; un middleware
(`App\Http\Middleware\ResolveEtablissement`) vérifie l'accès et scope automatiquement
les requêtes Eloquent (`App\Models\Concerns\BelongsToEtablissement`).

## Démarrage rapide (Laragon)

Les binaires PHP/Composer/Node/MySQL de Laragon ne sont pas dans le PATH système :
ajoutez-les temporairement ou utilisez un terminal Laragon (Menu > Terminal).

### Backend (Laravel)

```bash
cd back
composer install
cp .env.example .env   # si besoin, .env existe déjà et pointe sur monde_edu_v2
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

L'API est disponible sur `http://127.0.0.1:8000/api`.

Un établissement de démonstration est créé par le seeder :
- Établissement : `Groupe Scolaire Demo` (code `DEMO001`)
- Utilisateur : `fondateur@demo.mondeeducatif.ci` / `password` (rôle Fondateur, tous droits)

### Frontend (Angular + Ionic PWA)

```bash
cd front
npm install --legacy-peer-deps   # requis: @ionic/angular-toolkit exige typescript >=5.9,
                                   # incompatible avec Angular 18 (~5.5) - conflit de peer deps sans gravité
npm start                         # ng serve, http://localhost:4200
```

L'URL de l'API est configurée dans `src/environments/environment.ts`.

## Ce qui a été livré dans cette première itération

### Base de données (voir `back/database/migrations`)
Schéma normalisé (~30 tables) remplaçant les tables "à colonnes figées" de la V1
(ex: `classe` avec 100+ colonnes de statistiques, `emploi_temps` avec des colonnes
`mat1..mat11`/`s1..s11`, `inscription` avec des dizaines de colonnes `cout_*`/`reste_*`) :
- `etablissements`, `etablissement_user`, `annees_scolaires`, `periodes`
- `niveaux`, `matieres` + `matiere_niveau` (coefficient par niveau), `classes`,
  `affectations_enseignants`, `emplois_du_temps` (une ligne par créneau)
- `eleves`, `tuteurs` + `eleve_tuteur`, `inscriptions`
- `types_frais`, `grilles_tarifaires` + `grille_tarifaire_lignes`, `echeanciers`,
  `frais_eleves`, `reductions`, `dettes`, `reglements` + `reglement_lignes`, `depenses`
- `types_examens`, `notes`, `moyennes`, `moyennes_generales`, `absences`, `documents_eleves`
- Rôles/permissions : tables `spatie/laravel-permission` (scopées par établissement)

### Backend
- Authentification API par token (Laravel Sanctum)
- Rôles standard provisionnés automatiquement pour chaque nouvel établissement
  (`config/roles.php`, `App\Services\EtablissementProvisioningService`)
- Endpoints : `POST /login`, `GET /me`, `POST /logout`, `GET /dashboard`,
  `GET /eleves`, `GET /eleves/{id}`

### Frontend (PWA)
- Écran de connexion
- Écran de sélection d'établissement (utilisateur multi-établissements)
- Navigation par onglets (Accueil / Élèves / Profil)
- Tableau de bord avec indicateurs clés (effectifs, encaissements)
- Liste des élèves avec recherche et pagination infinie
- Profil : rôles de l'utilisateur, changement d'établissement, déconnexion
- Manifest PWA + service worker (installable, fonctionnement hors-ligne basique)

## Prochaines étapes suggérées

- Écrans de gestion : inscriptions, saisie des notes, encaissement des frais
- Policies Laravel pour vérifier les permissions par action (actuellement les rôles
  sont attribués mais pas encore vérifiés dans les contrôleurs)
- Module paie/RH et SMS (repris de la V1, volontairement non portés dans cette 1ère
  itération pour se concentrer sur le cœur pédagogique/financier)
- Remplacer les icônes PWA par défaut (`front/public/icons`) par le logo de l'établissement
