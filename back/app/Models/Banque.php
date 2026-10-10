<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEtablissement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Banque (compte bancaire) de l'etablissement, choisie dans les depenses
 * banque (Parametres > Banques). Une banque deja utilisee ne se supprime
 * pas : on la desactive.
 */
#[Fillable(['etablissement_id', 'nom', 'sigle', 'numero_compte', 'agence', 'is_active'])]
class Banque extends Model
{
    use BelongsToEtablissement;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
