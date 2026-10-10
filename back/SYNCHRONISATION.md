# Synchronisation serveur de l'école / serveur en ligne

Le **serveur de l'école** fait foi. Le **serveur en ligne** en est une copie : on y consulte tout, mais on n'y écrit que la pédagogie (notes, arrêt des notes, résultats, absences, conduite, rapports) et l'assistant. La liste exacte est dans `config/serveur.php`.

## Fonctionnement

- Des **déclencheurs MySQL** (`sj_{table}_ai/_au/_ad`) notent chaque changement dans `sync_journal`, même une modification faite à la main dans la base. Ils sont recréés après chaque migration.
- Toutes les 2 minutes, le serveur de l'école :
  1. **récupère** les changements faits en ligne (`GET /api/sync/journal`) et les applique. Si une note, une conduite ou une absence arrive pour une période clôturée ou des notes arrêtées à l'école, elle est refusée : elle va dans `sync_rejets` avec son auteur, et le serveur en ligne est corrigé ;
  2. **envoie** au serveur en ligne tout ce qui a changé chez lui (`POST /api/sync/recevoir`).
- Sur une même donnée modifiée des deux côtés, **la plus récente l'emporte**. Deux saisies de la même donnée (même élève, matière, période…) sont fusionnées.
- Les **fichiers** (photos des élèves et du personnel, logo, justificatifs de dépenses, documents) partent avec la ligne qui les désigne. L'école annonce leur empreinte, et seuls ceux qui manquent ou ont changé en ligne sont envoyés (`/api/sync/fichiers/verifier`, puis `/api/sync/fichier`).
- Une **saisie refusée** est visible par son auteur, à l'école comme en ligne : pastille et Profil, avec « J'ai compris ». L'administrateur la voit dans Paramètres › Synchronisation.
- Les **sessions** sont recopiées : une connexion ouverte sur un serveur devient valable sur l'autre à la synchronisation suivante.
- Les deux serveurs se reconnaissent par une **clé secrète partagée** (en-tête `X-Sync-Cle`).

## Mise en service

1. **Les deux serveurs** : mêmes migrations (`php artisan migrate`). L'utilisateur MySQL doit pouvoir créer des déclencheurs ; avec le journal binaire actif, il faut parfois `log_bin_trust_function_creators = 1`.
2. **Serveur en ligne**, dans le `.env` :
   ```
   SERVEUR_ROLE=en_ligne
   SYNC_CLE=une-longue-cle-secrete
   ```
   puis `php artisan sync:preparer` (enregistre le rôle, recrée les déclencheurs).
3. **Serveur de l'école**, dans le `.env` :
   ```
   SERVEUR_ROLE=local
   SYNC_URL=https://app.votre-domaine.ci
   SYNC_CLE=une-longue-cle-secrete
   ```
   puis `php artisan sync:preparer`.
4. **Première copie** (serveur en ligne vide), depuis l'école :
   ```
   php artisan sync:initialiser
   php artisan sync:executer
   ```
5. **Planification** à l'école : la synchronisation tourne toutes les 2 minutes avec le planificateur Laravel. Il faut soit `php artisan schedule:work` en continu, soit une tâche planifiée Windows qui lance `php artisan schedule:run` chaque minute.

## Commandes

| Commande | Rôle |
|---|---|
| `php artisan sync:etat` | Rôle, changements en attente, dernière synchronisation, dernière erreur, saisies refusées |
| `php artisan sync:executer` | Lance une synchronisation tout de suite |
| `php artisan sync:preparer` | Rôle du serveur et déclencheurs (après un changement de `.env`) |
| `php artisan sync:initialiser` | Met toutes les données de l'école en attente d'envoi (première copie) |

## Écran d'administration

**Paramètres › Synchronisation** (droit « Paramètres de l'établissement ») : rôle du serveur, changements en attente d'envoi, dernière synchronisation (ou dernière réception, côté serveur en ligne), dernière erreur, saisies refusées. À l'école, un bouton « Synchroniser maintenant ».
