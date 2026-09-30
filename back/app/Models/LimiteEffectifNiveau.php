<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEtablissement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Effectif maximum des classes d'un niveau (V1 niveau.eff_limite_classe). */
#[Fillable(['etablissement_id', 'niveau_id', 'effectif_max'])]
class LimiteEffectifNiveau extends Model
{
    use BelongsToEtablissement;

    protected $table = 'limites_effectifs_niveaux';

    public function niveau(): BelongsTo
    {
        return $this->belongsTo(Niveau::class);
    }
}
