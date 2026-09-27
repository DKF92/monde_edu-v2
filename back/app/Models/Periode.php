<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEtablissement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'etablissement_id', 'annee_scolaire_id', 'type_decoupage', 'numero',
    'libelle', 'date_debut', 'date_fin', 'is_active', 'is_cloturee',
])]
class Periode extends Model
{
    use BelongsToEtablissement;

    protected function casts(): array
    {
        return [
            'date_debut' => 'date',
            'date_fin' => 'date',
            'is_active' => 'boolean',
            'is_cloturee' => 'boolean',
        ];
    }

    public function anneeScolaire(): BelongsTo
    {
        return $this->belongsTo(AnneeScolaire::class);
    }
}
