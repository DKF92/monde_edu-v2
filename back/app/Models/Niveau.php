<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Referentiel global, commun a tous les etablissements (voir migration).
 */
#[Fillable(['code', 'libelle', 'cycle', 'ordre'])]
class Niveau extends Model
{
    protected $table = 'niveaux';

    public function classes(): HasMany
    {
        return $this->hasMany(Classe::class);
    }

    public function matieres(): BelongsToMany
    {
        return $this->belongsToMany(Matiere::class, 'matiere_niveau')
            ->withPivot('coefficient', 'is_obligatoire')
            ->withTimestamps();
    }
}
