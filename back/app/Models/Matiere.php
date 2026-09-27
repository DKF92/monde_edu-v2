<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEtablissement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['etablissement_id', 'code', 'libelle', 'coefficient_defaut', 'groupe_bulletin'])]
class Matiere extends Model
{
    use BelongsToEtablissement;

    public function niveaux(): BelongsToMany
    {
        return $this->belongsToMany(Niveau::class, 'matiere_niveau')
            ->withPivot('coefficient', 'is_obligatoire')
            ->withTimestamps();
    }
}
