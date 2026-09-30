<?php

namespace App\Models;

use Spatie\Permission\Models\Role;

/**
 * Poste (role spatie, scope par etablissement). Equivalent V1 : table
 * "role" (+ "role_fonction" pour ses droits).
 *
 * - code          : identifiant stable des postes standard (le libelle, lui,
 *                   peut etre renomme) ; null pour un poste cree par l'ecole ;
 * - is_active     : un poste desactive ne peut plus etre choisi a la connexion
 *                   ni attribue (V1 role.is_active) ;
 * - est_sensible  : poste protege (caisse, comptabilite...), seul un poste
 *                   ayant "utilisateurs.gerer_sensibles" l'attribue ;
 * - lie_niveaux   : le poste se rattache a des niveaux (educateur) ;
 * - lie_matieres  : le poste enseigne des matieres (professeur).
 */
class Poste extends Role
{
    public const SUPER_ADMIN = 'super_admin';

    public const FONDATEUR = 'fondateur';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'est_sensible' => 'boolean',
            'lie_niveaux' => 'boolean',
            'lie_matieres' => 'boolean',
        ];
    }

    public function estSuperAdmin(): bool
    {
        return $this->code === self::SUPER_ADMIN;
    }
}
