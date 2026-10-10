<?php

/**
 * Serveur en ligne / serveur de l'ecole.
 *
 * Le serveur LOCAL (ecole) fait foi. Le serveur EN LIGNE en est une copie
 * (synchronisee par le serveur local) : on y consulte tout, mais on n'y ecrit
 * que la pedagogie (et l'assistant). Inscriptions, paiements, classes, eleves,
 * tarifs, depenses, comptes... se font exclusivement depuis l'ecole.
 *
 * Le role se regle dans le .env : SERVEUR_ROLE=local (defaut) ou en_ligne.
 */
return [

    'role' => env('SERVEUR_ROLE', 'local'),

    /*
     * Ecritures acceptees par le serveur en ligne : « METHODE chemin », chemin
     * sans le prefixe api/ ; {id} = un identifiant, * = un segment quelconque.
     * Toute autre ecriture y est refusee (403, code ecriture_locale_seulement).
     */
    'ecritures_en_ligne' => [
        // Identite
        'POST login',
        'POST logout',
        'POST mot-de-passe',
        // Assistant
        'POST assistant/messages',
        // Notes, arret des notes, resultats
        'PUT notes',
        'DELETE notes/evaluation',
        'POST arret-notes/arreter',
        'POST arret-notes/rouvrir',
        'PATCH arret-notes/periodes/{id}/cloture',
        'POST resultats/enregistrer',
        'POST resultats/decisions',
        // Vie scolaire
        'POST absences',
        'PUT absences/{id}',
        'PATCH absences/{id}/justifier',
        'DELETE absences/{id}',
        'PUT conduite',
        // Rapports (textes avant generation, rapports personnalises)
        'PUT rapports/compiles/*/textes',
        'POST rapports/personnalises/apercu',
        'POST rapports/personnalises',
        'PUT rapports/personnalises/*',
        'DELETE rapports/personnalises/*',
        // Saisie refusee a l'ecole : l'auteur la marque comme vue
        'PATCH sync/rejets/{id}/vu',
        // Synchronisation (protegee par la cle partagee, voir SyncController)
        'POST sync/recevoir',
        'POST sync/fichiers/verifier',
        'POST sync/fichier',
    ],

    /*
     * Synchronisation, lancee par le serveur de l'ecole (php artisan
     * sync:executer, toutes les 2 minutes) : 1) recuperer la pedagogie saisie
     * en ligne, 2) envoyer au serveur en ligne tout ce qui a change a l'ecole.
     */
    'synchro' => [
        // Adresse du serveur en ligne (ex : https://app.monecole.ci), cote ecole.
        'url' => env('SYNC_URL'),
        // Cle secrete partagee, identique dans le .env des deux serveurs.
        'cle' => env('SYNC_CLE'),
        'lot' => 500,

        // Tables jamais synchronisees (techniques, ou propres a chaque serveur).
        'exclues' => [
            'cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs', 'migrations',
            'password_reset_tokens', 'sync_journal', 'sync_etat',
        ],

        // Ecrites des deux cotes : identifiees par uuid (les id different d'un
        // serveur a l'autre), fusionnees par leur cle naturelle si les deux
        // serveurs ont cree la « meme » ligne.
        'pedagogie' => ['notes', 'absences', 'moyennes', 'moyennes_generales', 'rapports_textes', 'rapports_personnalises'],

        // Tables administratives dont le serveur en ligne peut modifier
        // quelques colonnes : seules celles-ci sont reprises a l'ecole.
        'colonnes_en_ligne' => [
            'inscriptions' => ['decision_finale', 'moyenne_annuelle', 'niveau_a_suivre_id'],
            'periodes' => ['is_cloturee'],
            'users' => ['password', 'doit_changer_mot_de_passe', 'derniere_connexion_at'],
            // Refus d'une saisie faite en ligne : l'auteur peut le marquer vu en ligne.
            'sync_rejets' => ['vu_le'],
        ],

        // Fichiers (disque « public ») designes par ces colonnes : envoyes de
        // l'ecole vers le serveur en ligne avec la ligne qui les designe.
        'fichiers' => [
            'eleves' => ['photo_path'],
            'users' => ['photo_path'],
            'etablissements' => ['logo_path'],
            'depenses' => ['piece_justificative_path'],
            'entrees' => ['piece_justificative_path'],
            'documents_eleves' => ['fichier_path'],
        ],
        'taille_max_fichier' => 20 * 1024 * 1024,

        // Sessions (connexion valable sur les deux serveurs), identifiees par
        // le hash du jeton ; les mises a jour (last_used_at a chaque requete)
        // ne sont pas suivies.
        'jetons' => 'personal_access_tokens',
    ],

];
