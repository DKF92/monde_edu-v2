<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEtablissement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'etablissement_id', 'eleve_id', 'annee_scolaire_id', 'caissier_id',
    'numero_recu', 'montant_total', 'mode_paiement', 'date_paiement',
])]
class Reglement extends Model
{
    use BelongsToEtablissement;

    protected function casts(): array
    {
        return ['date_paiement' => 'datetime'];
    }

    public function eleve(): BelongsTo
    {
        return $this->belongsTo(Eleve::class);
    }

    public function anneeScolaire(): BelongsTo
    {
        return $this->belongsTo(AnneeScolaire::class);
    }

    public function caissier(): BelongsTo
    {
        return $this->belongsTo(Personnel::class);
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(ReglementLigne::class);
    }
}
