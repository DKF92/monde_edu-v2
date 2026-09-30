<?php

/**
 * Catalogue des permissions (globales, partagees par tous les etablissements)
 * et des roles standard provisionnes automatiquement pour chaque nouvel
 * etablissement (voir App\Services\EtablissementProvisioningService).
 *
 * Un role est cree PAR etablissement (team scope de spatie/laravel-permission)
 * afin qu'un meme utilisateur puisse avoir des roles differents selon
 * l'etablissement dans lequel il travaille.
 */

return [

    'permissions' => [
        'etablissement.gerer',
        'utilisateurs.gerer',
        // Creer/modifier les comptes qui ont un poste protege (Caissier,
        // Comptable...) et attribuer ces postes. Reserve au Super admin.
        'utilisateurs.gerer_sensibles',
        'roles.gerer',
        // SMS aux parents : reglages et historique ; recharger le credit est
        // reserve au Super admin.
        'sms.gerer',
        'sms.recharger',

        'annees_scolaires.gerer',
        // Choix de la periode (trimestre/semestre) a la connexion. Les postes
        // sans ce droit travaillent sur la periode en cours de l'annee.
        'periodes.choisir',
        'classes.voir',
        'classes.gerer',
        'matieres.gerer',
        'emplois_du_temps.gerer',

        'eleves.voir',
        'eleves.gerer',
        'inscriptions.gerer',

        'notes.saisir',
        'notes.voir',
        'moyennes.gerer',
        // Arreter les notes et cloturer les periodes : reserve au directeur.
        'notes.arreter',
        'bulletins.voir',
        'absences.gerer',

        'tarifs.gerer',
        'reglements.encaisser',
        'reglements.voir',
        'reglements.modifier',
        'dettes.gerer',
        'reductions.gerer',
        'depenses.gerer',
        'depenses.voir',
        'depenses.modifier',

        'rapports.voir',
    ],

    /**
     * Libelles affiches dans l'administration des postes, par groupe.
     * Une permission absente d'ici s'affiche avec son nom technique.
     */
    'libelles' => [
        'Administration' => [
            'etablissement.gerer' => ['Paramètres de l\'établissement', 'Informations de l\'école, paramètres des inscriptions.'],
            'utilisateurs.gerer' => ['Gérer les utilisateurs', 'Créer, modifier et désactiver les comptes du personnel.'],
            'utilisateurs.gerer_sensibles' => ['Gérer les postes protégés', 'Attribuer les postes protégés (caisse, comptabilité...) et modifier ces comptes.'],
            'roles.gerer' => ['Gérer les postes', 'Créer des postes et choisir leurs droits.'],
            'sms.gerer' => ['SMS', 'Réglages des SMS aux parents et historique des envois.'],
            'sms.recharger' => ['Recharger les SMS', 'Enregistrer un achat de crédit SMS.'],
        ],
        'Organisation scolaire' => [
            'annees_scolaires.gerer' => ['Années scolaires', 'Ouvrir, clôturer les années et leurs périodes.'],
            'periodes.choisir' => ['Choisir la période', 'Choisir le trimestre de travail à la connexion.'],
            'classes.voir' => ['Voir les classes', 'Liste des classes, effectifs et listes de classe.'],
            'classes.gerer' => ['Gérer les classes', 'Créer, modifier et supprimer les classes, fixer les effectifs maximums.'],
            'matieres.gerer' => ['Matières', 'Gérer les matières et leurs coefficients.'],
            'emplois_du_temps.gerer' => ['Emplois du temps', 'Construire les emplois du temps.'],
        ],
        'Élèves' => [
            'eleves.voir' => ['Voir les élèves', 'Consulter les fiches élèves.'],
            'eleves.gerer' => ['Gérer les élèves', 'Créer et modifier les fiches élèves.'],
            'inscriptions.gerer' => ['Inscriptions', 'Inscrire les élèves dans les classes.'],
        ],
        'Pédagogie' => [
            'notes.saisir' => ['Saisir les notes', 'Saisir les notes de ses classes.'],
            'notes.voir' => ['Voir les notes', 'Consulter les notes.'],
            'moyennes.gerer' => ['Moyennes', 'Calculer les moyennes et les rangs.'],
            'notes.arreter' => ['Arrêter les notes', 'Arrêter les notes des classes et clôturer les périodes (trimestres, semestres).'],
            'bulletins.voir' => ['Bulletins', 'Consulter et imprimer les bulletins.'],
            'absences.gerer' => ['Absences', 'Saisir les absences et retards.'],
        ],
        'Finances' => [
            'tarifs.gerer' => ['Paramètres des paiements', 'Montants d\'inscription, types de frais et de réductions.'],
            'reglements.encaisser' => ['Encaisser', 'Enregistrer les paiements en caisse.'],
            'reglements.voir' => ['Voir les paiements', 'Consulter les paiements et la situation financière.'],
            'reglements.modifier' => ['Modifier / supprimer un paiement', 'Corriger ou supprimer un paiement, avec un motif obligatoire (historisé).'],
            'dettes.gerer' => ['Dettes', 'Gérer les dettes des années précédentes.'],
            'reductions.gerer' => ['Réductions', 'Accorder des réductions et des cas sociaux.'],
            'depenses.gerer' => ['Enregistrer les dépenses', 'Saisir les sorties de caisse ; sans « Voir les dépenses », on ne voit que les siennes.'],
            'depenses.voir' => ['Voir les dépenses', 'Toutes les dépenses, le point mensuel et le solde de caisse.'],
            'depenses.modifier' => ['Modifier / supprimer une dépense', 'Corriger ou supprimer une dépense, avec un motif obligatoire (historisé).'],
        ],
        'Rapports' => [
            'rapports.voir' => ['Rapports', 'Tableaux de bord et points financiers.'],
        ],
    ],

    /**
     * Droits que seul un poste qui les possede deja peut accorder a un autre
     * poste. "*" ne les donne pas : le Fondateur ne les a pas d'office.
     */
    'permissions_reservees' => ['utilisateurs.gerer_sensibles', 'sms.recharger'],

    /**
     * Postes standard. "code" est l'identifiant stable du poste (le libelle
     * peut etre renomme dans l'administration des postes).
     * - sensible : poste protege, seul le Super admin l'attribue ;
     * - niveaux  : poste rattache a des niveaux (V1 table "choix" de l'educateur) ;
     * - matieres : poste qui enseigne des matieres (V1 administration.matiere_prof).
     */
    'roles' => [
        'Super admin' => [
            'code' => 'super_admin',
            'sensible' => true,
            'description' => 'Administre les comptes et les postes, seul à pouvoir attribuer les postes protégés.',
            'permissions' => ['*', 'utilisateurs.gerer_sensibles', 'sms.recharger'],
        ],
        'Fondateur' => [
            'code' => 'fondateur',
            'sensible' => true,
            'description' => 'Propriétaire de l\'établissement, accès à tous les modules.',
            'permissions' => ['*'],
        ],
        'Directeur des etudes' => [
            'code' => 'directeur_etudes',
            'description' => 'Suivi pédagogique : classes, matières, notes, moyennes.',
            'permissions' => [
                'eleves.voir', 'eleves.gerer', 'inscriptions.gerer', 'classes.voir', 'classes.gerer',
                'matieres.gerer', 'emplois_du_temps.gerer', 'notes.voir', 'moyennes.gerer', 'notes.arreter',
                'bulletins.voir', 'absences.gerer', 'rapports.voir', 'periodes.choisir',
            ],
        ],
        'Directeur administratif' => [
            'code' => 'directeur_administratif',
            'sensible' => true,
            'description' => 'Gestion administrative et financière.',
            'permissions' => [
                'utilisateurs.gerer', 'eleves.voir', 'tarifs.gerer', 'reglements.voir',
                'dettes.gerer', 'depenses.gerer', 'depenses.voir', 'rapports.voir',
            ],
        ],
        'Educateur' => [
            'code' => 'educateur',
            'niveaux' => true,
            'description' => 'Inscrit les élèves dans les classes de ses niveaux, suit les absences.',
            'permissions' => ['eleves.voir', 'inscriptions.gerer', 'classes.voir', 'absences.gerer', 'notes.voir', 'bulletins.voir', 'periodes.choisir'],
        ],
        'Professeur' => [
            'code' => 'professeur',
            'matieres' => true,
            'description' => 'Enseigne ses matières et saisit les notes.',
            'permissions' => ['eleves.voir', 'notes.saisir', 'notes.voir', 'emplois_du_temps.gerer', 'periodes.choisir'],
        ],
        'Caissier' => [
            'code' => 'caissier',
            'sensible' => true,
            'description' => 'Encaisse les paiements des élèves.',
            'permissions' => ['eleves.voir', 'reglements.encaisser', 'reglements.voir', 'reglements.modifier', 'dettes.gerer', 'depenses.gerer'],
        ],
        'Comptable' => [
            'code' => 'comptable',
            'sensible' => true,
            'description' => 'Suit les paiements et les dépenses.',
            'permissions' => ['reglements.voir', 'depenses.gerer', 'depenses.voir', 'rapports.voir'],
        ],
        'Econome' => [
            'code' => 'econome',
            'sensible' => true,
            'description' => 'Gère les dépenses de l\'établissement.',
            'permissions' => ['depenses.gerer', 'depenses.voir', 'depenses.modifier', 'rapports.voir'],
        ],
        'Secretaire' => [
            'code' => 'secretaire',
            'description' => 'Accueil et inscriptions.',
            'permissions' => ['eleves.voir', 'inscriptions.gerer', 'classes.voir'],
        ],
        'Informaticien' => [
            'code' => 'informaticien',
            'description' => 'Support informatique et comptes utilisateurs.',
            'permissions' => ['utilisateurs.gerer', 'etablissement.gerer'],
        ],
        'Parent' => [
            'code' => 'parent',
            'description' => 'Suivi de la scolarité de ses enfants.',
            'permissions' => ['eleves.voir', 'notes.voir', 'bulletins.voir', 'reglements.voir'],
        ],
    ],

];
