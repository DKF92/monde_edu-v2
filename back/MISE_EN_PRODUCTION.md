# Mise en production (MEP) — Monde Éducatif V2

Plan pas à pas pour la première mise en production, puis pour chaque évolution, **sans jamais recopier à la main** du code ou des tables entre le développement et la production.

Rappels qui guident tout le plan :

- le **serveur de l'école** (Windows 11, réseau local) **fait foi** ; le **serveur en ligne** (VPS) en est une copie ;
- en ligne, on n'écrit que la pédagogie (notes, absences, conduite, rapports) et l'assistant ; tout l'administratif (inscriptions, paiements, dépenses, entrées, comptes…) ne s'écrit qu'à l'école ;
- la synchronisation est lancée par l'école toutes les 2 minutes (voir `SYNCHRONISATION.md`) ;
- **le schéma de la base ne change que par les migrations Laravel**, jamais à la main, ni en développement ni en production.

---

## 0. Architecture cible

```
                     Internet
                        │
          ┌─────────────┴──────────────┐
          │  VPS (Ubuntu 24.04 LTS)     │   app.monde-edu.ci
          │  Nginx + PHP 8.3-FPM        │   - application (PWA) + API en ligne
          │  MySQL 8.4                  │   - SERVEUR_ROLE=en_ligne
          └─────────────▲──────────────┘
                        │ HTTPS, clé X-Sync-Cle (toutes les 2 min, lancé par l'école)
          ┌─────────────┴──────────────┐
          │ Serveur de l'école (Win 11) │   local.monde-edu.ci → IP fixe du réseau local
          │ Caddy (HTTPS) + PHP 8.3     │   - API de l'école (fait foi)
          │ MySQL 8.4 (service)         │   - SERVEUR_ROLE=local
          └─────────────▲──────────────┘
                        │ Wi-Fi / câble de l'école
              Postes et téléphones (PWA installée depuis app.monde-edu.ci)
```

- L'application s'ouvre toujours depuis **https://app.monde-edu.ci** (elle s'installe sur les téléphones et les PC).
- En mode automatique, elle parle d'abord au **serveur de l'école** s'il répond (https://local.monde-edu.ci), sinon au serveur en ligne, sinon elle passe hors ligne (file d'attente pour la pédagogie).
- Le serveur de l'école doit être en **HTTPS** : une page https ne peut pas appeler une API http (contenu mixte).

---

## 1. Ce qu'il faut préparer dans le code (avant la première MEP)

À faire en développement, puis à pousser sur Git comme toute évolution :

| # | Élément | Pourquoi |
|---|---|---|
| 1 | **Contrôle de version dans la synchronisation** : chaque serveur annonce sa dernière migration ; si elles diffèrent, la synchro se met en pause (message dans Paramètres › Synchronisation) au lieu d'échouer. | Pendant une MEP, un serveur est déjà à jour et l'autre pas encore. |
| 2 | `environment.prod.ts` : `apiUrl: 'https://app.monde-edu.ci/api'`. | L'adresse actuelle est un exemple. |
| 3 | `.env.production.example` (VPS) et `.env.ecole.example` (Windows), sans aucun secret. | Modèles de configuration versionnés. |
| 4 | **Intégration continue** (GitHub Actions) : vérifications PHP et Angular, construction du front, `composer install --no-dev`, archive de version `monde-edu-vX.Y.Z.zip`. | Une version = une archive construite une seule fois, identique pour les deux serveurs. |
| 5 | **Scripts de déploiement** versionnés : `deploiement/deployer-vps.sh` et `deploiement/deployer-ecole.ps1`. | Mettre à jour = lancer un script, jamais copier des fichiers. |
| 6 | **Droits de production** : une migration retire « Encaisser » au Fondateur et au Super admin (ils gardent « Voir les paiements »). | Règle décidée pour la production. |
| 7 | Commande `php artisan app:verifier` : extensions PHP (zip, gd, intl, mbstring, pdo_mysql, fileinfo, opcache), droits d'écriture, déclencheurs MySQL, lien `storage`, rôle du serveur, clé de synchro. | Contrôle automatique après chaque installation ou mise à jour. |

Rien de tout cela ne change le fonctionnement de l'application ; ce sont des outils de déploiement.

---

## 2. Organisation Git (dev → prod sans copier-coller)

- Dépôt **privé** GitHub `monde_edu-v2` (vérifier qu'il n'est pas public : le code contient la logique métier, jamais de données).
- Branches :
  - `develop` : travail en cours, testé sur le PC de développement ;
  - `main` : ce qui est en production.
- Une version = un **tag** sur `main` : `v2.0.0` (première MEP), puis `v2.0.1` (correction) ou `v2.1.0` (nouvelle fonction).
- À chaque tag, GitHub Actions construit l'archive de version et la publie dans **GitHub Releases**.
- Les deux serveurs installent **la même archive**. Le serveur ne contient ni `node_modules`, ni les sources Angular, ni Git.
- La base évolue **uniquement** par les fichiers `database/migrations` contenus dans l'archive ; `php artisan migrate --force` les applique sur chaque serveur.
- Les secrets (`.env`, mots de passe, `SYNC_CLE`, `APP_KEY`) ne sont **jamais** dans Git.
- Les données réelles (dumps V1, sauvegardes) ne vont **jamais** dans Git ni sur GitHub.

---

## 3. Domaine et DNS (monde-edu.ci)

1. Confier la zone DNS de `monde-edu.ci` à **Cloudflare** (gratuit). Le registrar .ci garde le domaine ; seuls les serveurs DNS changent.
   - Cela permet d'obtenir un certificat HTTPS pour le serveur de l'école sans l'ouvrir sur Internet (validation DNS).
2. Créer les enregistrements :
   - `app.monde-edu.ci` → A → IP publique du VPS (proxy Cloudflare **désactivé** au début, nuage gris) ;
   - `local.monde-edu.ci` → A → **IP locale fixe** du serveur de l'école (ex. `192.168.1.10`), proxy désactivé.
3. Créer un **jeton API Cloudflare** limité à « Zone DNS : modifier » sur `monde-edu.ci` (il servira à Caddy pour le certificat de l'école).
4. Sur la box ou le routeur de l'école : réserver l'IP du serveur (bail DHCP fixe). Vérifier que la box ne bloque pas un nom public qui pointe vers une IP locale (« protection DNS rebinding ») ; sinon ajouter `local.monde-edu.ci` dans la box ou utiliser le DNS 1.1.1.1 sur les postes.

---

## 4. Serveur en ligne (VPS)

### 4.1 Système (une seule fois)

1. Ubuntu Server **24.04 LTS**, 2 vCPU, 4 Go de RAM et 60 Go de disque minimum.
2. Créer un utilisateur `deploy` (sudo), connexion **par clé SSH uniquement** ; désactiver la connexion `root` et les mots de passe SSH.
3. Pare-feu `ufw` : 22 (SSH), 80 et 443 seulement. `fail2ban`, `unattended-upgrades`.
4. Installer :
   - Nginx ;
   - PHP 8.3-FPM avec les extensions `zip`, `gd`, `intl`, `mbstring`, `xml`, `curl`, `bcmath`, `mysql`, `fileinfo` et `opcache` ;
   - MySQL 8.4 ;
   - Certbot.
5. MySQL :
   - créer la base `monde_edu` et un utilisateur dédié (pas `root`), avec les droits complets sur cette base seulement, y compris `TRIGGER` ;
   - `log_bin_trust_function_creators = 1` dans la configuration (déclencheurs de synchronisation) ;
   - `max_allowed_packet` à 64M (lots de synchronisation).
6. PHP : `memory_limit = 512M`, `upload_max_filesize = 20M`, `post_max_size = 25M`, **OPcache activé** (`opcache.enable=1`, `opcache.validate_timestamps=0` en production).

### 4.2 Arborescence et première installation

```
/var/www/monde-edu/
  releases/v2.0.0/      ← archive de version décompressée
  shared/.env           ← secrets du serveur
  shared/storage/       ← photos, logos, justificatifs (persistant entre versions)
  current → releases/v2.0.0   (lien symbolique servi par Nginx)
```

1. Copier `shared/.env` depuis `.env.production.example` :
   ```
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://app.monde-edu.ci
   APP_KEY=…            # LA MÊME clé que le serveur de l'école
   DB_DATABASE=monde_edu
   SERVEUR_ROLE=en_ligne
   SYNC_CLE=…           # longue clé aléatoire, identique à l'école
   CORS_ALLOWED_ORIGINS=https://app.monde-edu.ci
   ```
2. Lancer `deployer-vps.sh v2.0.0`. Le script fait, dans l'ordre :
   1. télécharger l'archive ;
   2. décompresser dans `releases/` ;
   3. créer les liens vers `shared/.env` et `shared/storage` ;
   4. `php artisan down` ;
   5. `php artisan migrate --force` ;
   6. `php artisan sync:preparer` ;
   7. `php artisan config:cache`, `route:cache` et `view:cache` ;
   8. basculer `current` ;
   9. recharger PHP-FPM ;
   10. `php artisan up`.
3. Nginx :
   - `root /var/www/monde-edu/current/front` pour l'application Angular, avec repli vers `index.html` ;
   - `/api` et `/storage` vers `current/back/public` (PHP-FPM).
4. `certbot --nginx -d app.monde-edu.ci` (renouvellement automatique).
5. Tâche cron : `* * * * * php /var/www/monde-edu/current/back/artisan schedule:run`.
6. `php artisan app:verifier`.

### 4.3 Sauvegardes du VPS

- Chaque nuit : `mysqldump --single-transaction --routines --triggers` compressé et chiffré, plus `shared/storage`, gardés 30 jours.
- Une copie hors du VPS (stockage objet ou disque de l'école).
- Activer aussi les instantanés (snapshots) proposés par l'hébergeur.

---

## 5. Serveur de l'école (Windows 11)

### 5.1 Machine

- PC dédié, toujours allumé, branché sur **onduleur** et relié au réseau en câble.
- Windows 11 Pro à jour, compte administrateur dédié et session utilisateur sans droits d'administration.
- Désactiver la mise en veille. Planifier les mises à jour Windows la nuit, avec redémarrage automatique.
- Disque : SSD ; un **second disque ou disque externe** pour les sauvegardes.

### 5.2 Logiciels (installés comme services Windows, qui démarrent seuls)

Laragon convient au développement, mais en production il faut des **services** qui redémarrent seuls :

| Logiciel | Installation | Démarrage |
|---|---|---|
| **MySQL 8.4** | installateur officiel MSI | service Windows (automatique) |
| **PHP 8.3** (NTS x64) | archive officielle dans `C:\monde-edu\php` | `php-cgi.exe -b 127.0.0.1:9000` en service via **NSSM** |
| **Caddy** (avec le module DNS Cloudflare) | `caddy.exe` dans `C:\monde-edu\caddy` | service via NSSM |

- Mêmes réglages MySQL et PHP qu'en ligne (`log_bin_trust_function_creators`, extensions, OPcache, `memory_limit`).
- **Caddy** obtient et renouvelle tout seul le certificat de `local.monde-edu.ci` par validation DNS (jeton Cloudflare), sans ouvrir de port vers Internet.
- `Caddyfile` (versionné, sans le jeton) : servir `back/public` en FastCGI vers `127.0.0.1:9000`, en HTTPS sur `local.monde-edu.ci`.
- Pare-feu Windows : n'autoriser le **443** que depuis le **réseau local**. Rien n'est ouvert vers Internet : c'est l'école qui va vers le VPS.

### 5.3 Arborescence et installation

```
C:\monde-edu\
  releases\v2.0.0\     ← archive de version décompressée
  shared\.env          ← secrets
  shared\storage\      ← fichiers (persistants)
  current              ← jonction vers la version active
  sauvegardes\
```

1. `shared\.env` depuis `.env.ecole.example` :
   ```
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://local.monde-edu.ci
   APP_KEY=…            # même clé qu'en ligne
   DB_DATABASE=monde_edu
   SERVEUR_ROLE=local
   SYNC_URL=https://app.monde-edu.ci
   SYNC_CLE=…           # même clé qu'en ligne
   CORS_ALLOWED_ORIGINS=https://app.monde-edu.ci
   ```
2. Lancer en administrateur : `deployer-ecole.ps1 -Version v2.0.0`. Le script fait les mêmes étapes que sur le VPS :
   1. sauvegarde de la base **avant** toute migration ;
   2. téléchargement et décompression de l'archive ;
   3. liens vers `.env` et `storage` ;
   4. `php artisan down` ;
   5. `migrate --force` ;
   6. `sync:preparer` ;
   7. mise en cache de la configuration et des routes ;
   8. bascule de `current` ;
   9. redémarrage du service PHP ;
   10. `php artisan up`.
3. **Planificateur des tâches Windows** : tâche « Monde Éducatif - planificateur » lancée chaque minute, même sans session ouverte : `C:\monde-edu\php\php.exe C:\monde-edu\current\back\artisan schedule:run`. Elle lance la synchronisation toutes les 2 minutes.
4. **Paramètres › Établissement** : renseigner l'adresse du serveur de l'école `https://local.monde-edu.ci`. Les applications la retiennent à la connexion.

### 5.4 Sauvegardes de l'école (la base qui fait foi)

- Tâche planifiée chaque nuit :
  - `mysqldump` complet, avec les déclencheurs ;
  - copie de `shared\storage` ;
  - le tout compressé et **chiffré** (7-Zip AES), gardé 30 jours sur le second disque.
- Une copie **hors de l'école** chaque nuit (vers le VPS ou un stockage en ligne), toujours chiffrée.
- Le journal binaire MySQL reste actif : il permet de revenir à une heure précise.
- **Tester une restauration** une fois par mois sur une base de test.

---

## 6. Les données : passage de la V1 à la V2 (jour J)

La base de production part **vide** et reçoit la migration de la V1. On ne copie **jamais** la base de développement (elle contient des comptes et des données de test).

**J-7 — répétition générale**
1. Installer le serveur de l'école (§5) avec la version candidate.
2. Lancer la migration V1 → V2 depuis l'interface, avec le dernier dump V1.
3. Contrôler le rapport de migration (effectifs par classe, encaissé et dépenses par année, restes).
4. Faire tester par les postes clés : caisse, éducateurs, direction des études, comptable.
5. Noter les écarts et les corriger **dans le code**, puis faire une nouvelle version.

**J-1 au soir — gel de la V1**
1. Annoncer l'arrêt : plus aucune saisie en V1 après l'heure fixée.
2. Faire le dump final de la base V1 ; le garder chiffré, hors de Git.

**Jour J, dans l'ordre**
1. Serveur de l'école :
   - remettre la base `monde_edu` à zéro (base neuve) ;
   - `deployer-ecole.ps1 -Version v2.0.0` ;
   - migration V1 depuis l'interface (Super admin), avec le dump final ;
   - contrôler le rapport.
2. **Comptes réels** :
   - désactiver ou supprimer tous les comptes de démonstration ;
   - créer ou vérifier les comptes du personnel (téléphone obligatoire, mot de passe provisoire, changement forcé à la première connexion) ;
   - vérifier les postes et droits (Administration › Rôles).
3. **Paramètres** :
   - établissement, en-tête et logo ;
   - année et périodes ;
   - grilles et ordre de paiement ;
   - **Reçu et billet** (date d'échéance du billet) ;
   - banques ;
   - dettes prises en compte ;
   - statut visible en classe ;
   - SMS.
4. Serveur en ligne : base vide, déjà migrée (§4).
5. Première copie, depuis l'école :
   ```
   php artisan sync:initialiser
   php artisan sync:executer
   ```
   Puis `php artisan sync:etat` des deux côtés : plus rien en attente, aucune erreur.
6. **Recette** : voir la liste du §8.
7. Ouverture aux utilisateurs :
   - installer l'application depuis `https://app.monde-edu.ci` (carte « Installer ») sur les PC et les téléphones ;
   - vérifier la pastille « Serveur de l'école » dans l'établissement et « En ligne » à l'extérieur.
8. Garder la V1 **en lecture seule** pendant un mois (consultation), puis l'archiver.

---

## 7. Chaque évolution après la MEP (le cycle normal)

1. Développer sur `develop`, avec la base de développement. Toute modification de structure passe par une **nouvelle migration**, et toute correction de données par une migration ou une commande, jamais par une requête manuelle sur la production.
2. Tester en local : `ng build`, tests, recette des écrans touchés.
3. Fusionner `develop` → `main`, puis poser le tag `v2.x.y`. GitHub Actions construit l'archive et la publie.
4. Hors des heures de travail (le soir) :
   1. **Serveur de l'école en premier** : `deployer-ecole.ps1 -Version v2.x.y`. Le script commence par une sauvegarde, puis migre. La synchro se met en pause tant que le serveur en ligne n'a pas la même version (contrôle du §1).
   2. **Serveur en ligne ensuite** : `deployer-vps.sh v2.x.y`. La synchro reprend seule et rattrape ce qui a été saisi entre-temps.
   3. Contrôle : `php artisan app:verifier` et `php artisan sync:etat` sur les deux serveurs.
5. Les applications installées se mettent à jour seules : le bandeau « Nouvelle version disponible › Mettre à jour » apparaît.
6. **Retour arrière** si besoin :
   1. remettre `current` sur la version précédente (le dossier est encore là) ;
   2. si une migration a modifié les données, restaurer la sauvegarde faite par le script juste avant ;
   3. sinon, préférer une version corrective `v2.x.(y+1)`.

---

## 8. Recette de MEP (liste de contrôle)

- [ ] `https://app.monde-edu.ci` : connexion, installation de l'application (PC et téléphone).
- [ ] Dans l'école : pastille « Serveur de l'école » ; débrancher Internet → l'application continue (serveur de l'école).
- [ ] Hors de l'école (4G) : pastille « En ligne » ; consultation possible ; une inscription ou un paiement est refusé (écriture réservée à l'école) ; une note est acceptée puis arrive à l'école dans les 2 minutes.
- [ ] Encaisser un paiement réel de test, imprimer le reçu et le billet, puis supprimer ce paiement avec un motif.
- [ ] Imprimer :
  - [ ] une liste de classe ;
  - [ ] un bulletin ;
  - [ ] le reste à payer (gros document) ;
  - [ ] le bilan financier.
- [ ] Mise à jour en ligne (site de l'État) : un élève de test, sans envoyer de matricule réel inutile.
- [ ] Paramètres › Synchronisation : aucune erreur, rien en attente.
- [ ] Sauvegarde de la nuit présente et restaurable (test sur une base de test).
- [ ] Comptes de démonstration désactivés ; droits « Encaisser » retirés au Fondateur et au Super admin.

---

## 9. Sécurité et conformité

- Les données sont **personnelles** (élèves mineurs, parents, personnel) :
  - accès HTTPS seulement ;
  - comptes nominatifs ;
  - mots de passe changés à la première connexion ;
  - postes sensibles attribués par le Super admin seulement.
- Déclaration du traitement auprès de l'**ARTCI** (autorité de protection des données en Côte d'Ivoire) avant l'ouverture.
- Sauvegardes chiffrées. La clé de chiffrement est conservée à deux endroits sûrs, hors des serveurs.
- `APP_DEBUG=false` partout (aucun détail technique affiché aux utilisateurs).
- Mettre à jour régulièrement Ubuntu, Windows, PHP et MySQL ; les dépendances du projet passent par les versions (`composer audit`, `npm audit` dans l'intégration continue).
