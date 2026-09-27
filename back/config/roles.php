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
        'roles.gerer',

        'annees_scolaires.gerer',
        'classes.gerer',
        'matieres.gerer',
        'emplois_du_temps.gerer',

        'eleves.voir',
        'eleves.gerer',
        'inscriptions.gerer',

        'notes.saisir',
        'notes.voir',
        'moyennes.gerer',
        'bulletins.voir',
        'absences.gerer',

        'tarifs.gerer',
        'reglements.encaisser',
        'reglements.voir',
        'dettes.gerer',
        'reductions.gerer',
        'depenses.gerer',

        'rapports.voir',
    ],

    'roles' => [
        'Fondateur' => ['*'],
        'Directeur des etudes' => [
            'eleves.voir', 'eleves.gerer', 'inscriptions.gerer', 'classes.gerer',
            'matieres.gerer', 'emplois_du_temps.gerer', 'notes.voir', 'moyennes.gerer',
            'bulletins.voir', 'absences.gerer', 'rapports.voir',
        ],
        'Directeur administratif' => [
            'utilisateurs.gerer', 'eleves.voir', 'tarifs.gerer', 'reglements.voir',
            'dettes.gerer', 'depenses.gerer', 'rapports.voir',
        ],
        'Educateur' => ['eleves.voir', 'absences.gerer', 'notes.voir', 'bulletins.voir'],
        'Professeur' => ['eleves.voir', 'notes.saisir', 'notes.voir', 'emplois_du_temps.gerer'],
        'Caissier' => ['eleves.voir', 'reglements.encaisser', 'reglements.voir', 'dettes.gerer'],
        'Comptable' => ['reglements.voir', 'depenses.gerer', 'rapports.voir'],
        'Econome' => ['depenses.gerer', 'rapports.voir'],
        'Secretaire' => ['eleves.voir', 'inscriptions.gerer'],
        'Informaticien' => ['utilisateurs.gerer', 'etablissement.gerer'],
        'Parent' => ['eleves.voir', 'notes.voir', 'bulletins.voir', 'reglements.voir'],
    ],

];
